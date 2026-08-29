<?php

/**
 * Split-DNS for VPN-only hosts.
 *
 * Restricting an ingress to VPN peers is only half the job: public DNS still
 * answers that hostname with the cluster's PUBLIC address, so a connected peer
 * arrives at Traefik from its ISP address and the allow-list refuses it. The
 * /etc/hosts line teammates have been pasting is a manual workaround for
 * exactly this. These tests pin the pieces that replace it.
 */

use App\Http\Integrations\Netbird\Requests\CreateGroupRequest;
use App\Http\Integrations\Netbird\Requests\DeleteNameserverGroupRequest;
use App\Http\Integrations\Netbird\Requests\DeletePeerRequest;
use App\Http\Integrations\Netbird\Requests\ListGroupsRequest;
use App\Http\Integrations\Netbird\Requests\ListNameserverGroupsRequest;
use App\Http\Integrations\Netbird\Requests\ListPeersRequest;
use App\Http\Integrations\Netbird\Requests\SaveNameserverGroupRequest;
use App\Traits\InteractsWithVpn;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function splitDnsSubject(): object
{
    return new class
    {
        use InteractsWithVpn;

        /** @return list<string> */
        public function hosts(string $kubectl): array
        {
            return $this->vpnOnlyHosts($kubectl);
        }

        public function reconcile(string $kubectl, string $host, string $pat): bool
        {
            return $this->reconcileVpnSplitDns($kubectl, 'larakube-vpn', $host, $pat, 'production');
        }

        public function gatewayIp(string $host, string $pat, string $kubectl): ?string
        {
            return $this->vpnGatewayOverlayIp($host, $pat, $kubectl);
        }
    };
}

/** Two ingresses: one VPN-gated, one ordinary. Only the first may ever appear. */
function splitDnsIngressJson(): string
{
    return (string) json_encode(['items' => [
        [
            'metadata' => ['annotations' => [
                'traefik.ingress.kubernetes.io/router.middlewares' => 'larakube-shared-chat-vpn-only@kubernetescrd',
            ]],
            'spec' => ['rules' => [['host' => 'admin.chat.example.com']]],
        ],
        [
            'metadata' => ['annotations' => []],
            'spec' => ['rules' => [['host' => 'docs.example.com']]],
        ],
    ]]);
}

function splitDnsFakes(): array
{
    return [
        '*get ingress -A -o json*' => Process::result(output: splitDnsIngressJson()),
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*apply -f *' => Process::result(output: 'configmap/vpn-resolver-config created'),
        '*rollout restart*' => Process::result(output: 'restarted'),
        '*' => Process::result(output: ''),
    ];
}

test('only VPN-gated hosts are collected, never the rest of the zone', function (): void {
    // A wildcard or an over-broad list would drag public sites -- and the SSO
    // host the VPN itself logs in through -- across the mesh to a gateway with
    // no route for them.
    Process::fake(splitDnsFakes());

    expect(splitDnsSubject()->hosts('kubectl'))->toBe(['admin.chat.example.com']);
});

test('the resolver answers VPN-only hosts with the gateway address and still forwards everything else', function (): void {
    $manifest = view('k8s.vpn.resolver-config', [
        'hosts' => ['admin.chat.example.com'],
        'gatewayIp' => '100.84.155.135',
        'instance' => '',
    ])->render();

    expect($manifest)
        ->toContain('admin.chat.example.com:5353')
        ->toContain('100.84.155.135 admin.chat.example.com')
        // Without a default block the resolver is authoritative for the whole
        // internet and NXDOMAINs it.
        ->toContain('.:5353')
        ->toContain('forward . 1.1.1.1')
        // Reloading in place is what lets the reconcile skip a pod restart --
        // a restart re-enrols the client as a new peer on a new address and
        // invalidates the records just written.
        ->toContain('reload')
        // No fallthrough: it would turn the AAAA query every client sends
        // alongside the A into NXDOMAIN, which macOS and iOS read as "no such
        // name" and stop on.
        ->not->toContain('fallthrough')
        // NOT :53 -- the NetBird client already binds <overlay-ip>:53 in this
        // very pod, confirmed live with netstat.
        ->not->toContain(':53 {');
});

test('reconcile registers a match-domain group on the resolver port, distributed to All', function (): void {
    Process::fake(splitDnsFakes());

    Saloon::fake([
        ListNameserverGroupsRequest::class => MockResponse::make([]),
        ListPeersRequest::class => MockResponse::make([
            ['id' => 'p1', 'name' => 'vpn-client-abc', 'ip' => '100.84.155.135', 'connected' => true],
        ]),
        ListGroupsRequest::class => MockResponse::make([['id' => 'grp-all', 'name' => 'All']]),
        CreateGroupRequest::class => MockResponse::make(['id' => 'grp-all']),
        SaveNameserverGroupRequest::class => MockResponse::make(['id' => 'ns-1']),
    ]);

    expect(splitDnsSubject()->reconcile('kubectl', 'vpn.example.com', 'pat'))->toBeTrue();

    Saloon::assertSent(function ($request) {
        if (! $request instanceof SaveNameserverGroupRequest) {
            return true;
        }

        $body = $request->body()->all();

        return $body['domains'] === ['admin.chat.example.com']
            && $body['nameservers'][0]['port'] === 5353
            && $body['nameservers'][0]['ip'] === '100.84.155.135'
            // primary would make this group answer EVERYTHING, not just the
            // domains listed.
            && $body['primary'] === false
            && $body['search_domains_enabled'] === false
            // SSO users never present a setup key, so they never pick up
            // auto_groups -- they are only ever in All.
            && $body['groups'] === ['grp-all'];
    });
});

test('a second run updates the existing group instead of stacking duplicates', function (): void {
    Process::fake(splitDnsFakes());

    Saloon::fake([
        ListNameserverGroupsRequest::class => MockResponse::make([
            ['id' => 'ns-existing', 'name' => 'LaraKube Cluster Internal'],
        ]),
        ListPeersRequest::class => MockResponse::make([
            ['id' => 'p1', 'name' => 'vpn-client-abc', 'ip' => '100.84.155.135', 'connected' => true],
        ]),
        ListGroupsRequest::class => MockResponse::make([['id' => 'grp-all', 'name' => 'All']]),
        SaveNameserverGroupRequest::class => MockResponse::make(['id' => 'ns-existing']),
    ]);

    expect(splitDnsSubject()->reconcile('kubectl', 'vpn.example.com', 'pat'))->toBeTrue();

    Saloon::assertSent(fn ($request) => ! $request instanceof SaveNameserverGroupRequest
        || str_ends_with($request->resolveEndpoint(), '/ns-existing'));
});

test('the group is removed once nothing is VPN-only any more', function (): void {
    // Left behind, it would keep aiming peers at a resolver holding no records.
    Process::fake([
        '*get ingress -A -o json*' => Process::result(output: (string) json_encode(['items' => []])),
        '*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        ListNameserverGroupsRequest::class => MockResponse::make([
            ['id' => 'ns-existing', 'name' => 'LaraKube Cluster Internal'],
        ]),
        DeleteNameserverGroupRequest::class => MockResponse::make([], 200),
    ]);

    expect(splitDnsSubject()->reconcile('kubectl', 'vpn.example.com', 'pat'))->toBeTrue();

    Saloon::assertSent(DeleteNameserverGroupRequest::class);
});

test('the gateway address is read back live, never assumed', function (): void {
    // It survives only via the client PVC: a --purge re-enrols the gateway on a
    // different address, and a stored one would silently point at nothing.
    Process::fake(splitDnsFakes());

    Saloon::fake([
        ListPeersRequest::class => MockResponse::make([
            ['name' => 'some-other-peer', 'ip' => '100.1.1.1'],
            ['name' => 'vpn-client-xyz', 'ip' => '100.113.100.204'],
        ]),
    ]);

    expect(splitDnsSubject()->gatewayIp('vpn.example.com', 'pat', 'kubectl'))
        ->toBe('100.113.100.204');
});

test('the connected gateway wins when a rollout has left an orphan behind', function (): void {
    // Every client rollout enrols a NEW peer and orphans the previous one, so
    // the name prefix routinely matches several. Taking whichever came first
    // aimed split-DNS at a disconnected peer on a live cluster (2026-08-30).
    Process::fake(splitDnsFakes());

    Saloon::fake([
        ListPeersRequest::class => MockResponse::make([
            ['id' => 'old', 'name' => 'vpn-client-6d6d96fb8c-prg5m', 'ip' => '100.84.155.135', 'connected' => false],
            ['id' => 'new', 'name' => 'vpn-client-5665b95544-fnckf', 'ip' => '100.84.209.9', 'connected' => true],
        ]),
    ]);

    expect(splitDnsSubject()->gatewayIp('vpn.example.com', 'pat', 'kubectl'))
        ->toBe('100.84.209.9');
});

test('orphaned gateway peers are retired, and the live one never is', function (): void {
    Process::fake(splitDnsFakes());

    Saloon::fake([
        ListNameserverGroupsRequest::class => MockResponse::make([]),
        ListPeersRequest::class => MockResponse::make([
            ['id' => 'old', 'name' => 'vpn-client-old', 'ip' => '100.84.155.135', 'connected' => false],
            ['id' => 'new', 'name' => 'vpn-client-new', 'ip' => '100.84.209.9', 'connected' => true],
            ['id' => 'phone', 'name' => 'someones-iphone', 'ip' => '100.84.3.3', 'connected' => false],
        ]),
        ListGroupsRequest::class => MockResponse::make([['id' => 'grp-all', 'name' => 'All']]),
        SaveNameserverGroupRequest::class => MockResponse::make(['id' => 'ns-1']),
        DeletePeerRequest::class => MockResponse::make([], 200),
    ]);

    splitDnsSubject()->reconcile('kubectl', 'vpn.example.com', 'pat');

    // The orphan goes; the live gateway and an unrelated person's laptop stay.
    Saloon::assertSent(fn ($request) => ! $request instanceof DeletePeerRequest
        || str_ends_with($request->resolveEndpoint(), '/old'));
});

test('the client PVC is mounted where NetBird actually keeps peer identity', function (): void {
    // /etc/netbird was empty on a live pod: the PVC persisted nothing, so every
    // restart registered a NEW peer with a NEW overlay address and orphaned the
    // old one. Identity lives in /var/lib/netbird (default.json + state.json).
    $manifest = view('k8s.vpn.client', ['instance' => '', 'isLocal' => false])->render();

    expect($manifest)
        ->toContain('mountPath: /var/lib/netbird')
        ->not->toContain('mountPath: /etc/netbird');
});

test('reconcile waits for a connected gateway rather than writing a dead address', function (): void {
    // Registration lands a few seconds after the pod is Running. Reading peers
    // immediately can see only the previous, disconnected gateway.
    Process::fake(splitDnsFakes());
    $polls = 0;

    Saloon::fake([
        ListNameserverGroupsRequest::class => MockResponse::make([]),
        // First poll sees only the dead gateway, as it would moments after a
        // rollout; the new one appears on the second.
        ListPeersRequest::class => function () use (&$polls) {
            $polls++;

            return MockResponse::make($polls === 1
                ? [['id' => 'old', 'name' => 'vpn-client-old', 'ip' => '100.84.155.135', 'connected' => false]]
                : [
                    ['id' => 'old', 'name' => 'vpn-client-old', 'ip' => '100.84.155.135', 'connected' => false],
                    ['id' => 'new', 'name' => 'vpn-client-new', 'ip' => '100.84.209.9', 'connected' => true],
                ]);
        },
        ListGroupsRequest::class => MockResponse::make([['id' => 'grp-all', 'name' => 'All']]),
        SaveNameserverGroupRequest::class => MockResponse::make(['id' => 'ns-1']),
        DeletePeerRequest::class => MockResponse::make([], 200),
    ]);

    splitDnsSubject()->reconcile('kubectl', 'vpn.example.com', 'pat');

    Saloon::assertSent(function ($request) {
        if (! $request instanceof SaveNameserverGroupRequest) {
            return true;
        }

        return $request->body()->all()['nameservers'][0]['ip'] === '100.84.209.9';
    });
});
