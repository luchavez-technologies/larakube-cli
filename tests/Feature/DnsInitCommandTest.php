<?php

use App\Exceptions\MissingFlagException;
use App\Http\Integrations\Cloudflare\Requests\ListZonesRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/**
 * ExternalDNS is now one instance per dns:init GROUP — a stable name covering
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
            '*create secret generic cloudflare-token-*' => Process::result(output: 'created'),
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

test('dns:init refuses the local environment', function (): void {
    $this->artisan('dns:init local')
        ->expectsOutputToContain('only supported on cloud environments')
        ->assertExitCode(1);
});

test('dns:init requires a token before anything else', function (): void {
    Process::fake(dnsFakes());

    $this->artisan('dns:init prod --no-interaction --force')->run();
})->throws(MissingFlagException::class, 'Missing required --cloudflare-token');

test('dns:init manages the sole zone the token can see when --zone= is omitted', function (): void {
    Process::fake(dnsFakes());
    dnsZonesSaloonFake(['example.com']);

    $this->artisan('dns:init prod --cloudflare-token=t --no-interaction --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('ExternalDNS is managing example.com');
});

test('dns:init requires --group= when the token covers multiple zones and none is given', function (): void {
    // An unfiltered/unnamed multi-zone instance is exactly the ambiguity this
    // flag exists to avoid guessing at — a stable identity must never be
    // derived from a mutable zone set (see groupSlug()'s own docblock).
    Process::fake(dnsFakes());
    dnsZonesSaloonFake(['ourfridays.com', 'larakube.app']);

    $this->artisan('dns:init prod --cloudflare-token=t --no-interaction --force')->run();
})->throws(MissingFlagException::class, 'Missing required --group');

test('dns:init confines the instance to one zone and gives it a cluster-unique owner', function (): void {
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

    $this->artisan('dns:init prod --zone=example.com --cloudflare-token=t --no-interaction --force')
        ->assertExitCode(0);

    expect($applied)->not->toBeNull('the ExternalDNS manifest was never applied')
        ->and($applied)->toContain('--domain-filter=example.com')
        ->and($applied)->toContain('--txt-owner-id=larakube-abc12345-example-com');
});

test('dns:init manages several zones sharing one token under one named group', function (): void {
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

    $this->artisan('dns:init prod --group=shared --cloudflare-token=t --no-interaction --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('ExternalDNS is managing ourfridays.com, larakube.app')
        ->expectsOutputToContain('external-dns-shared');

    expect($applied)->not->toBeNull()
        ->and($applied)->toContain('--domain-filter=ourfridays.com')
        ->and($applied)->toContain('--domain-filter=larakube.app')
        ->and($applied)->toContain('--txt-owner-id=larakube-abc12345-shared');
});

test('dns:init --zone= narrows discovery to a subset of what the token can see', function (): void {
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

    $this->artisan('dns:init prod --group=shared --zone=ourfridays.com --zone=larakube.app --cloudflare-token=t --no-interaction --force')
        ->assertExitCode(0);

    expect($applied)->toContain('--domain-filter=ourfridays.com')
        ->and($applied)->toContain('--domain-filter=larakube.app')
        ->and($applied)->not->toContain('--domain-filter=nexa.site');
});

test('dns:init refuses when --zone= names something the token cannot see', function (): void {
    Process::fake(dnsFakes());
    dnsZonesSaloonFake(['example.com']);

    $this->artisan('dns:init prod --zone=other.example --cloudflare-token=t --no-interaction --force')
        ->expectsOutputToContain("can't see: other.example")
        ->assertExitCode(1);
});

test('dns:init refuses when a zone in scope is already managed under a different instance', function (): void {
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

    $this->artisan('dns:init prod --group=different-name --zone=example.com --cloudflare-token=t --no-interaction --force')
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

        $this->artisan('dns:init prod --zone=example.com --cloudflare-token=t --no-interaction --force')
            ->assertExitCode(0);

        preg_match('/--txt-owner-id=(\S+)/', (string) $applied, $m);
        $owners[] = $m[1] ?? null;
    }

    expect($owners[0])->not->toBeNull()
        ->and($owners[1])->not->toBeNull()
        ->and($owners[0])->not->toBe($owners[1]);
});

test('dns:init refuses to deploy when it cannot establish a cluster identity', function (): void {
    // Falling back to a shared constant owner id is the bug — better to refuse.
    Process::fake(dnsFakes('', [
        '*get configmap larakube-cluster*' => Process::result(output: '', exitCode: 1),
        '*create -f *' => Process::result(output: '', exitCode: 1),
    ]));
    dnsZonesSaloonFake(['example.com']);

    $this->artisan('dns:init prod --zone=example.com --cloudflare-token=t --no-interaction --force')
        ->assertExitCode(1);
});

test('the token secret is named after the resolved group', function (): void {
    $secretCmd = null;

    Process::fake(dnsFakes('abc12345', [
        '*create secret generic cloudflare-token-*' => function ($process) use (&$secretCmd) {
            $secretCmd = is_string($process->command) ? $process->command : implode(' ', (array) $process->command);

            return Process::result(output: 'created');
        },
    ]));
    dnsZonesSaloonFake(['other.co.uk']);

    $this->artisan('dns:init prod --zone=other.co.uk --cloudflare-token=second-account-token --no-interaction --force')
        ->assertExitCode(0);

    expect($secretCmd)->toContain('cloudflare-token-other-co-uk')
        ->and($secretCmd)->toContain('second-account-token');
});

test('dns:remove is a no-op when the cluster manages nothing', function (): void {
    Process::fake([
        '*get deployments*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('dns:remove prod --force')
        ->expectsOutputToContain('No ExternalDNS instances')
        ->assertExitCode(0);
});

test('dns:remove warns that existing DNS records survive removal', function (): void {
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
    $this->artisan('dns:remove prod --zone=example.com --force')
        ->expectsOutputToContain('still exist in Cloudflare')
        ->assertExitCode(0);
});

test('dns:remove rejects a zone this cluster does not manage', function (): void {
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

    $this->artisan('dns:remove prod --zone=nope.com --force')
        ->expectsOutputToContain('is not managed by this cluster')
        ->assertExitCode(1);
});

test('dns:remove refuses a bare --zone= that is part of a multi-zone group', function (): void {
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
    $this->artisan('dns:remove prod --zone=ourfridays.com --force')
        ->expectsOutputToContain("is part of the 'shared' instance")
        ->assertExitCode(1);

    Process::assertNotRan(fn ($process) => str_contains(
        is_string($process->command) ? $process->command : implode(' ', (array) $process->command),
        'delete deployment',
    ));
});

test('dns:remove --group= removes every zone in a multi-zone instance', function (): void {
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
    $this->artisan('dns:remove prod --group=shared --force')
        ->expectsOutputToContain('ExternalDNS removed for')
        ->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains(
        is_string($process->command) ? $process->command : implode(' ', (array) $process->command),
        'delete deployment/external-dns-shared',
    ));
});

test('dns:list surfaces the owner id, which is how zone conflicts are diagnosed', function (): void {
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
    $exit = Artisan::call('dns:list prod --json');
    $payload = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($payload[0]['zone'])->toBe('example.com')
        ->and($payload[0]['owner'])->toBe('larakube-abc12345-example-com')
        ->and($payload[0]['ready'])->toBeTrue();
});

test('dns:list shows one row per zone for a multi-zone group, sharing the same instance', function (): void {
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

    $exit = Artisan::call('dns:list prod --json');
    $payload = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($payload)->toHaveCount(2)
        ->and(array_column($payload, 'zone'))->toBe(['larakube.app', 'ourfridays.com'])
        ->and($payload[0]['slug'])->toBe($payload[1]['slug']);
});
