<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/**
 * tls:prune edits the file that holds every private key on the cluster, so
 * the tests pin down what it keeps, the order it works in, and that nothing
 * sensitive reaches a command line.
 */
function tlsPruneAcme(): string
{
    return (string) json_encode([
        'letsencrypt' => [
            'Account' => ['Email' => 'ops@example.com', 'Registration' => ['body' => new stdClass], 'PrivateKey' => 'ACCOUNT-KEY'],
            'Certificates' => [
                ['domain' => ['main' => 'app.example.com'], 'certificate' => 'CERT-APP', 'key' => 'KEY-APP', 'Store' => 'default'],
                ['domain' => ['main' => 'gone.example.com'], 'certificate' => 'CERT-GONE', 'key' => 'KEY-GONE', 'Store' => 'default'],
                ['domain' => ['main' => 'old.example.com', 'sans' => ['www.example.com']], 'certificate' => 'CERT-SAN', 'key' => 'KEY-SAN', 'Store' => 'default'],
            ],
        ],
    ], JSON_PRETTY_PRINT);
}

function tlsPruneFakes(array &$captured, ?string $acme = null, array $overrides = []): array
{
    $captured = ['written' => null, 'order' => []];

    return $overrides + [
        '*get deployment -n traefik traefik' => Process::result(),
        '*get pvc traefik-acme*' => Process::result(output: ''),
        '*get ingress -A -o json*' => Process::result(output: (string) json_encode(['items' => [
            ['metadata' => ['namespace' => 'apps', 'name' => 'app'], 'spec' => ['rules' => [['host' => 'app.example.com'], ['host' => 'www.example.com']]]],
        ]])),
        '*exec -n traefik deploy/traefik -- cat /acme/acme.json' => Process::result(output: $acme ?? tlsPruneAcme()),
        '*cp -p /acme/acme.json /acme/acme.json.bak*' => function () use (&$captured) {
            $captured['order'][] = 'backup';

            return Process::result();
        },
        '*exec -i -n traefik deploy/traefik*' => function (PendingProcess $process) use (&$captured) {
            $captured['order'][] = 'write';
            $captured['written'] = $process->input;

            return Process::result();
        },
        '*rollout restart deployment/traefik*' => function () use (&$captured) {
            $captured['order'][] = 'restart';

            return Process::result();
        },
        // After the restart, only the kept certificates remain.
        '*grep -o*' => Process::result(output: "\"main\": \"app.example.com\"\n\"main\": \"old.example.com\""),
        '*' => Process::result(output: ''),
    ];
}

test('tls:prune removes only certificates no ingress uses, backing up before it writes and restarting after', function (): void {
    $captured = [];
    Process::fake(tlsPruneFakes($captured));

    $this->artisan('tls:prune production --context=ctx --force --no-interaction')
        ->expectsOutputToContain('gone.example.com')
        ->expectsOutputToContain('Removed 1 unused certificate(s)')
        ->assertExitCode(0);

    expect($captured['order'])->toBe(['backup', 'write', 'restart']);

    $written = json_decode($captured['written'], true);
    $mains = array_column(array_column($written['letsencrypt']['Certificates'], 'domain'), 'main');

    // A certificate stays when any of its domains is still routed (www via SAN).
    expect($mains)->toBe(['app.example.com', 'old.example.com'])
        ->and($written['letsencrypt']['Account']['PrivateKey'])->toBe('ACCOUNT-KEY');

    // Traefik's empty objects must stay objects, not become arrays.
    expect($captured['written'])->toContain('"body": {}');

    // Keys travel on stdin only.
    Process::assertNotRan(fn (PendingProcess $process) => str_contains($process->command, 'KEY-'));
});

test('tls:prune changes nothing when every certificate is still routed', function (): void {
    $captured = [];
    $acme = (string) json_encode(['letsencrypt' => ['Certificates' => [
        ['domain' => ['main' => 'app.example.com'], 'certificate' => 'C', 'key' => 'K'],
    ]]]);
    Process::fake(tlsPruneFakes($captured, $acme));

    $this->artisan('tls:prune production --context=ctx --force --no-interaction')
        ->expectsOutputToContain('Nothing to prune')
        ->assertExitCode(0);

    expect($captured['order'])->toBe([]);
});

test('tls:prune stops without writing when acme.json can\'t be read', function (): void {
    $captured = [];
    Process::fake(tlsPruneFakes($captured, 'not json'));

    $this->artisan('tls:prune production --context=ctx --force --no-interaction')
        ->expectsOutputToContain('Nothing was changed')
        ->assertExitCode(1);

    expect($captured['order'])->toBe([]);
});

test('tls:prune stops without writing when the backup fails', function (): void {
    $captured = [];
    Process::fake(tlsPruneFakes($captured, overrides: [
        '*cp -p /acme/acme.json /acme/acme.json.bak*' => Process::result(exitCode: 1),
    ]));

    $this->artisan('tls:prune production --context=ctx --force --no-interaction')
        ->expectsOutputToContain('Could not back up')
        ->assertExitCode(1);

    expect($captured['written'])->toBeNull();
});

test('tls:prune refuses managed clusters for now', function (): void {
    $captured = [];
    Process::fake(tlsPruneFakes($captured, overrides: [
        '*get pvc traefik-acme*' => Process::result(output: 'persistentvolumeclaim/traefik-acme'),
    ]));

    $this->artisan('tls:prune production --context=ctx --force --no-interaction')
        ->expectsOutputToContain('single-node (VPS) clusters')
        ->assertExitCode(1);

    expect($captured['order'])->toBe([]);
});
