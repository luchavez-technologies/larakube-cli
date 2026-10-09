<?php

use App\Exceptions\MissingFlagException;
use App\Http\Integrations\Cloudflare\Requests\ListZonesRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/**
 * ExternalDNS is now one instance per tool:init --tool=external-dns GROUP — a stable name covering
 * one or more Cloudflare zones that share a single API token, discovered from
 * the token itself (Cloudflare's own `GET /zones`), not retyped by hand. The
 * safety properties: --domain-filter (one per zone in the group; without at
 * least one, --policy=sync deletes records in every zone the token can see)
 * and --txt-owner-id (ownership registry, one per group; a shared value made
 * two clusters delete each other's records).
 */
function dnsFakes(string $clusterId = 'abc12345', array $overrides = []): array
{
    // Overrides must be merged BEFORE the '*' catch-all. Process::fake matches
    // patterns in insertion order, and array_merge appends *new* keys at the
    // end — so an override added after the catch-all would never be reached.
    return array_merge(
        [
            '*get configmap larakube-cluster*' => Process::result(output: $clusterId),
            '*create namespace larakube-shared*' => Process::result(output: 'created'),
            '*apply -f -*' => Process::result(output: 'applied'),
            '*get deployments*' => Process::result(output: ''),
        ],
        $overrides,
        ['*' => Process::result(output: '')],
    );
}

/** The token's discoverable Cloudflare zones — cloudflareListZones()'s Saloon-based call. */
function dnsZonesSaloonFake(array $zones): void
{
    Saloon::fake([
        ListZonesRequest::class => MockResponse::make([
            'success' => true,
            'result' => array_values(array_map(
                fn (string $zone, int $i) => ['id' => "zone-{$i}", 'name' => $zone],
                $zones,
                array_keys($zones),
            )),
            'result_info' => ['total_pages' => 1],
        ]),
    ]);
}

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('tool:init --tool=external-dns refuses the local environment', function (): void {
    $this->artisan('tool:init --tool=external-dns local')
        ->expectsOutputToContain('only supported on cloud environments')
        ->assertExitCode(1);
});

test('tool:init --tool=external-dns requires a token before anything else', function (): void {
    Process::fake(dnsFakes());

    $this->artisan('tool:init --tool=external-dns prod --context=ctx --no-interaction --force')->run();
})->throws(MissingFlagException::class, 'Missing required --cloudflare-token');

test('tool:init --tool=external-dns manages the sole zone the token can see when --zone= is omitted', function (): void {
    Process::fake(dnsFakes());
    dnsZonesSaloonFake(['example.com']);

    $this->artisan('tool:init --tool=external-dns prod --context=ctx --cloudflare-token=t --no-interaction --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('ExternalDNS is managing example.com');
});

test('tool:init --tool=external-dns auto-names a multi-zone instance after the environment when no human is present to ask', function (): void {
    // A person just pasting in a Cloudflare token has no way to know it's
    // multi-zone, so this must never throw non-interactively — but the
    // auto-name still can't be derived from the (mutable) zone set, so it
    // falls back to the one identifier that's both already unique per
    // cluster and zone-independent: the environment name.
    Process::fake(dnsFakes('abc12345', [
        '*apply -f -*' => Process::result(output: 'applied'),
    ]));
    dnsZonesSaloonFake(['ourfridays.com', 'larakube.app']);

    $this->artisan('tool:init --tool=external-dns prod --context=ctx --cloudflare-token=t --no-interaction --force')
        ->assertExitCode(0)
        ->expectsOutputToContain("using 'prod'")
        ->expectsOutputToContain('external-dns-prod');
});

test('two clusters sharing one multi-zone token auto-name into two different groups, never colliding', function (): void {
    // The exact prior incident (project_dns_multizone): a shared/derived
    // owner ID across clusters deletes the other cluster's DNS records.
    $appliedByEnv = [];

    foreach (['prod', 'staging'] as $env) {
        Process::fake(dnsFakes('abc12345', [
            '*apply -f -*' => function ($process) use (&$appliedByEnv, $env) {
                $cmd = is_string($process->command) ? $process->command : implode(' ', (array) $process->command);
                if (str_contains($cmd, 'external-dns')) {
                    $appliedByEnv[$env] = $cmd;
                }

                return Process::result(output: 'applied');
            },
        ]));
        dnsZonesSaloonFake(['ourfridays.com', 'larakube.app']);

        $this->artisan("tool:init --tool=external-dns {$env} --context=ctx --cloudflare-token=t --no-interaction --force")
            ->assertExitCode(0);
    }

    expect($appliedByEnv['prod'])->toContain('--txt-owner-id=larakube-abc12345-prod')
        ->and($appliedByEnv['staging'])->toContain('--txt-owner-id=larakube-abc12345-staging');
});

test('tool:init --tool=external-dns confines the instance to one zone and gives it a cluster-unique owner', function (): void {
    $applied = null;

    Process::fake(dnsFakes('abc12345', [
        '*apply -f -*' => function ($process) use (&$applied) {
            $cmd = is_string($process->command) ? $process->command : implode(' ', (array) $process->command);
            if (str_contains($cmd, 'external-dns')) {
                $applied = $cmd;
            }

            return Process::result(output: 'applied');
        },
    ]));
    dnsZonesSaloonFake(['example.com']);

    $this->artisan('tool:init --tool=external-dns prod --context=ctx --zone=example.com --cloudflare-token=t --no-interaction --force')
        ->assertExitCode(0);

    expect($applied)->not->toBeNull('the ExternalDNS manifest was never applied')
        ->and($applied)->toContain('--domain-filter=example.com')
        ->and($applied)->toContain('--txt-owner-id=larakube-abc12345-example-com');
});

test('tool:init --tool=external-dns manages several zones sharing one token under one named group', function (): void {
    $applied = null;

    Process::fake(dnsFakes('abc12345', [
        '*apply -f -*' => function ($process) use (&$applied) {
            $cmd = is_string($process->command) ? $process->command : implode(' ', (array) $process->command);
            if (str_contains($cmd, 'external-dns')) {
                $applied = $cmd;
            }

            return Process::result(output: 'applied');
        },
    ]));
    dnsZonesSaloonFake(['ourfridays.com', 'larakube.app']);

    $this->artisan('tool:init --tool=external-dns prod --context=ctx --group=shared --cloudflare-token=t --no-interaction --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('ExternalDNS is managing ourfridays.com, larakube.app')
        ->expectsOutputToContain('external-dns-shared');

    expect($applied)->not->toBeNull()
        ->and($applied)->toContain('--domain-filter=ourfridays.com')
        ->and($applied)->toContain('--domain-filter=larakube.app')
        ->and($applied)->toContain('--txt-owner-id=larakube-abc12345-shared');
});

test('tool:init --tool=external-dns --zone= narrows discovery to a subset of what the token can see', function (): void {
    $applied = null;

    Process::fake(dnsFakes('abc12345', [
        '*apply -f -*' => function ($process) use (&$applied) {
            $cmd = is_string($process->command) ? $process->command : implode(' ', (array) $process->command);
            if (str_contains($cmd, 'external-dns')) {
                $applied = $cmd;
            }

            return Process::result(output: 'applied');
        },
    ]));
    dnsZonesSaloonFake(['ourfridays.com', 'larakube.app', 'nexa.site']);

    $this->artisan('tool:init --tool=external-dns prod --context=ctx --group=shared --zone=ourfridays.com --zone=larakube.app --cloudflare-token=t --no-interaction --force')
        ->assertExitCode(0);

    expect($applied)->toContain('--domain-filter=ourfridays.com')
        ->and($applied)->toContain('--domain-filter=larakube.app')
        ->and($applied)->not->toContain('--domain-filter=nexa.site');
});

test('tool:init --tool=external-dns refuses when --zone= names something the token cannot see', function (): void {
    Process::fake(dnsFakes());
    dnsZonesSaloonFake(['example.com']);

    $this->artisan('tool:init --tool=external-dns prod --context=ctx --zone=other.example --cloudflare-token=t --no-interaction --force')
        ->expectsOutputToContain("can't see: other.example")
        ->assertExitCode(1);
});

test('tool:init --tool=external-dns refuses when a zone in scope is already managed under a different instance', function (): void {
    Process::fake(dnsFakes('abc12345', [
        '*get deployments*' => Process::result(output: (string) json_encode(['items' => [[
            'metadata' => [
                'name' => 'external-dns-example-com',
                'labels' => ['larakube.io/dns-zone' => 'example-com'],
                'annotations' => ['larakube.io/dns-domain' => 'example.com'],
            ],
            'status' => ['readyReplicas' => 1],
        ]]])),
    ]));
    dnsZonesSaloonFake(['example.com']);

    $this->artisan('tool:init --tool=external-dns prod --context=ctx --group=different-name --zone=example.com --cloudflare-token=t --no-interaction --force')
        ->expectsOutputToContain("already managed by 'external-dns-example-com'")
        ->assertExitCode(1);
});

test('two clusters managing the same zone get different owner ids', function (): void {
    // This is the exact production symptom: identical owner ids made each
    // cluster treat the other's records as orphans and delete them.
    $owners = [];

    foreach (['clusterAA', 'clusterBB'] as $clusterId) {
        $applied = null;

        Process::fake(dnsFakes($clusterId, [
            '*apply -f -*' => function ($process) use (&$applied) {
                $cmd = is_string($process->command) ? $process->command : implode(' ', (array) $process->command);
                if (str_contains($cmd, 'txt-owner-id')) {
                    $applied = $cmd;
                }

                return Process::result(output: 'applied');
            },
        ]));
        dnsZonesSaloonFake(['example.com']);

        $this->artisan('tool:init --tool=external-dns prod --context=ctx --zone=example.com --cloudflare-token=t --no-interaction --force')
            ->assertExitCode(0);

        preg_match('/--txt-owner-id=(\S+)/', (string) $applied, $m);
        $owners[] = $m[1] ?? null;
    }

    expect($owners[0])->not->toBeNull()
        ->and($owners[1])->not->toBeNull()
        ->and($owners[0])->not->toBe($owners[1]);
});

test('tool:init --tool=external-dns refuses to deploy when it cannot establish a cluster identity', function (): void {
    // Falling back to a shared constant owner id is the bug — better to refuse.
    Process::fake(dnsFakes('', [
        '*get configmap larakube-cluster*' => Process::result(output: '', exitCode: 1),
        '*create -f *' => Process::result(output: '', exitCode: 1),
    ]));
    dnsZonesSaloonFake(['example.com']);

    $this->artisan('tool:init --tool=external-dns prod --context=ctx --zone=example.com --cloudflare-token=t --no-interaction --force')
        ->assertExitCode(1);
});

test('the token secret is named after the resolved group', function (): void {
    $secret = null;

    Process::fake(dnsFakes('abc12345', [
        '*apply -f -*' => function ($process) use (&$secret) {
            $manifest = json_decode((string) $process->input, true);
            if (($manifest['kind'] ?? null) === 'Secret') {
                $secret = $manifest;
            }

            return Process::result(output: 'applied');
        },
    ]));
    dnsZonesSaloonFake(['other.co.uk']);

    $this->artisan('tool:init --tool=external-dns prod --context=ctx --zone=other.co.uk --cloudflare-token=second-account-token --no-interaction --force')
        ->assertExitCode(0);

    expect($secret['metadata']['name'])->toBe('cloudflare-token-other-co-uk')
        ->and(base64_decode($secret['data']['token']))->toBe('second-account-token');
    Process::assertNotRan(fn ($process) => str_contains((string) $process->command, 'second-account-token'));
});

test('external-dns:remove is a no-op when the cluster manages nothing', function (): void {
    Process::fake([
        '*get deployments*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('external-dns:remove prod --context=ctx --force')
        ->expectsOutputToContain('No ExternalDNS instances')
        ->assertExitCode(0);
});

test('external-dns:remove warns that existing DNS records survive removal', function (): void {
    // Removing the controller stops reconciliation; it does not delete records.
    // Assuming otherwise leaves stale records resolving to a dead cluster.
    Process::fake([
        '*get deployments*' => Process::result(output: (string) json_encode(['items' => [[
            'metadata' => [
                'name' => 'external-dns-example-com',
                'labels' => ['larakube.io/dns-zone' => 'example-com'],
                'annotations' => [
                    'larakube.io/dns-domain' => 'example.com',
                    'larakube.io/dns-owner-id' => 'larakube-abc12345-example-com',
                ],
            ],
            'status' => ['readyReplicas' => 1],
        ]]])),
        '*delete *' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    // Asserted on the post-removal notice, not the confirmation block —
    // --force skips printing the confirmation entirely.
    $this->artisan('external-dns:remove prod --context=ctx --zone=example.com --force')
        ->expectsOutputToContain('still exist in Cloudflare')
        ->assertExitCode(0);
});

test('external-dns:remove rejects a zone this cluster does not manage', function (): void {
    Process::fake([
        '*get deployments*' => Process::result(output: (string) json_encode(['items' => [[
            'metadata' => [
                'name' => 'external-dns-example-com',
                'labels' => ['larakube.io/dns-zone' => 'example-com'],
                'annotations' => ['larakube.io/dns-domain' => 'example.com'],
            ],
            'status' => ['readyReplicas' => 1],
        ]]])),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('external-dns:remove prod --context=ctx --zone=nope.com --force')
        ->expectsOutputToContain('is not managed by this cluster')
        ->assertExitCode(1);
});

test('external-dns:remove refuses a bare --zone= that is part of a multi-zone group', function (): void {
    Process::fake([
        '*get deployments*' => Process::result(output: (string) json_encode(['items' => [[
            'metadata' => [
                'name' => 'external-dns-shared',
                'labels' => ['larakube.io/dns-zone' => 'shared'],
                'annotations' => ['larakube.io/dns-domain' => 'ourfridays.com,larakube.app'],
            ],
            'status' => ['readyReplicas' => 1],
        ]]])),
        '*' => Process::result(output: ''),
    ]);

    // A laraKubeError() line this long is unreliable to substring-match in
    // full against captured test output (Termwind's renderer doesn't always
    // preserve every character past a certain point) — check the start of
    // the message and the behavior (nothing got deleted) instead of the
    // full text.
    $this->artisan('external-dns:remove prod --context=ctx --zone=ourfridays.com --force')
        ->expectsOutputToContain("is part of the 'shared' instance")
        ->assertExitCode(1);

    Process::assertNotRan(fn ($process) => str_contains(
        is_string($process->command) ? $process->command : implode(' ', (array) $process->command),
        'delete deployment',
    ));
});

test('external-dns:remove --group= removes every zone in a multi-zone instance', function (): void {
    Process::fake([
        '*get deployments*' => Process::result(output: (string) json_encode(['items' => [[
            'metadata' => [
                'name' => 'external-dns-shared',
                'labels' => ['larakube.io/dns-zone' => 'shared'],
                'annotations' => ['larakube.io/dns-domain' => 'ourfridays.com,larakube.app'],
            ],
            'status' => ['readyReplicas' => 1],
        ]]])),
        '*delete *' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    // Checking behavior (the shared instance's resources actually got
    // deleted, in one pass, not per-zone) rather than the full printed
    // summary line — see the comment on the refusal test above for why.
    $this->artisan('external-dns:remove prod --context=ctx --group=shared --force')
        ->expectsOutputToContain('ExternalDNS removed for')
        ->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains(
        is_string($process->command) ? $process->command : implode(' ', (array) $process->command),
        'delete deployment/external-dns-shared',
    ));
});

test('external-dns:list surfaces the owner id, which is how zone conflicts are diagnosed', function (): void {
    Process::fake([
        '*get deployments*' => Process::result(output: (string) json_encode(['items' => [[
            'metadata' => [
                'name' => 'external-dns-example-com',
                'labels' => ['larakube.io/dns-zone' => 'example-com'],
                'annotations' => [
                    'larakube.io/dns-domain' => 'example.com',
                    'larakube.io/dns-owner-id' => 'larakube-abc12345-example-com',
                ],
            ],
            'status' => ['readyReplicas' => 1],
        ]]])),
        '*' => Process::result(output: ''),
    ]);

    // Via --json: the table renderer does not write through the console output
    // capture, and the owner id is the value that actually matters here.
    $exit = Artisan::call('external-dns:list prod --context=ctx --json');
    $payload = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($payload[0]['zone'])->toBe('example.com')
        ->and($payload[0]['owner'])->toBe('larakube-abc12345-example-com')
        ->and($payload[0]['ready'])->toBeTrue();
});

test('external-dns:list shows one row per zone for a multi-zone group, sharing the same instance', function (): void {
    Process::fake([
        '*get deployments*' => Process::result(output: (string) json_encode(['items' => [[
            'metadata' => [
                'name' => 'external-dns-shared',
                'labels' => ['larakube.io/dns-zone' => 'shared'],
                'annotations' => [
                    'larakube.io/dns-domain' => 'ourfridays.com,larakube.app',
                    'larakube.io/dns-owner-id' => 'larakube-abc12345-shared',
                ],
            ],
            'status' => ['readyReplicas' => 1],
        ]]])),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('external-dns:list prod --context=ctx --json');
    $payload = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($payload)->toHaveCount(2)
        ->and(array_column($payload, 'zone'))->toBe(['larakube.app', 'ourfridays.com'])
        ->and($payload[0]['slug'])->toBe($payload[1]['slug']);
});

test('tool:init --tool=external-dns reuses the stored Cloudflare token instead of demanding it again', function (): void {
    // Re-applying the manifest — to pick up a new flag, say — used to prompt for
    // the credential and then OVERWRITE the stored one with whatever was typed.
    // Worse, a token with a different zone scope resolves to a different group
    // slug, standing up a SECOND ExternalDNS instance with its own
    // --txt-owner-id against the same zones: the shape that had clusters
    // deleting each other's records.
    Process::fake(dnsFakes(overrides: [
        '*get secret -n larakube-shared -o name*' => Process::result(output: "secret/cloudflare-token-luchtech-dev\nsecret/unrelated"),
        '*get secret cloudflare-token-luchtech-dev*' => Process::result(output: base64_encode('cf-stored-token')),
    ]));

    dnsZonesSaloonFake(['luchtech.dev']);

    $this->artisan('tool:init --tool=external-dns prod --context=ctx --no-interaction --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Reusing the stored Cloudflare token');
});

test('tool:init --tool=external-dns takes the token from LARAKUBE_CLOUDFLARE_TOKEN so it never reaches argv', function (): void {
    $secret = null;
    Process::fake(dnsFakes('abc12345', [
        '*apply -f -*' => function ($process) use (&$secret) {
            $manifest = json_decode((string) $process->input, true);
            if (($manifest['kind'] ?? null) === 'Secret') {
                $secret = $manifest;
            }

            return Process::result(output: 'applied');
        },
    ]));
    dnsZonesSaloonFake(['example.com']);
    putenv('LARAKUBE_CLOUDFLARE_TOKEN=env-token');

    try {
        $this->artisan('tool:init --tool=external-dns prod --context=ctx --no-interaction --force')->assertExitCode(0);
    } finally {
        putenv('LARAKUBE_CLOUDFLARE_TOKEN');
    }

    expect(base64_decode($secret['data']['token']))->toBe('env-token');
});
