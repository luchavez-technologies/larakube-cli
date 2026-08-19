<?php

use App\Exceptions\MissingFlagException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/**
 * ExternalDNS is now one instance per Cloudflare zone. The two args that carry
 * the safety properties are --domain-filter (confine to one zone; without it
 * --policy=sync deletes records in every zone the token can see) and
 * --txt-owner-id (ownership registry; a shared value made two clusters delete
 * each other's records).
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
        ],
        $overrides,
        ['*' => Process::result(output: '')],
    );
}

test('dns:init refuses the local environment', function (): void {
    $this->artisan('dns:init local')
        ->expectsOutputToContain('only supported on cloud environments')
        ->assertExitCode(1);
});

test('dns:init requires a zone rather than defaulting to every zone', function (): void {
    // An unfiltered ExternalDNS under --policy=sync deletes records it does not
    // recognise, so "all zones" must never be reachable by omission.
    Process::fake(dnsFakes());

    $this->artisan('dns:init prod --cloudflare-token=t --no-interaction --force')->run();
})->throws(MissingFlagException::class, 'Missing required --zone');

test('dns:init requires a token it can scope to that zone', function (): void {
    Process::fake(dnsFakes());

    $this->artisan('dns:init prod --zone=example.com --no-interaction --force')->run();
})->throws(MissingFlagException::class, 'Missing required --cloudflare-token');

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

    $this->artisan('dns:init prod --zone=example.com --cloudflare-token=t --no-interaction --force')
        ->assertExitCode(0);

    expect($applied)->not->toBeNull('the ExternalDNS manifest was never applied')
        ->and($applied)->toContain('--domain-filter=example.com')
        ->and($applied)->toContain('--txt-owner-id=larakube-abc12345-example-com');
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

    $this->artisan('dns:init prod --zone=example.com --cloudflare-token=t --no-interaction --force')
        ->assertExitCode(1);
});

test('each zone gets its own token secret so zones can span Cloudflare accounts', function (): void {
    $secretCmd = null;

    Process::fake(dnsFakes('abc12345', [
        '*create secret generic cloudflare-token-*' => function ($process) use (&$secretCmd) {
            $secretCmd = is_string($process->command) ? $process->command : implode(' ', (array) $process->command);

            return Process::result(output: 'created');
        },
    ]));

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
