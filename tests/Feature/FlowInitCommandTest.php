<?php

use Illuminate\Support\Facades\Process;

/**
 * Fakes a cluster for tool:init --tool=n8n. $secret seeds the instance's existing
 * credentials Secret; $liveDeployments are Deployments already running.
 *
 * @param  array<string, string>  $secret
 * @param  list<string>  $liveDeployments
 * @param  array{manifest: ?string, secret: ?array, commands: list<string>}|null  $seen
 */
function fakeFlowInitCluster(?array &$seen, array $secret = [], array $liveDeployments = [], bool $rolloutFails = false, ?array $capacity = null): void
{
    $seen = ['manifest' => null, 'secret' => null, 'commands' => []];

    Process::fake(function ($process) use (&$seen, $secret, $liveDeployments, $rolloutFails, $capacity) {
        $cmd = (string) $process->command;
        $seen['commands'][] = $cmd;

        if ($capacity !== null && str_contains($cmd, 'get nodes -o json')) {
            return Process::result(output: (string) json_encode(['items' => $capacity['nodes']]));
        }

        if ($capacity !== null && str_contains($cmd, 'get pods -A -o json')) {
            return Process::result(output: (string) json_encode(['items' => $capacity['pods']]));
        }

        if (str_contains($cmd, ' apply -f -')) {
            $input = (string) $process->input;
            $object = json_decode($input, true);
            if (($object['kind'] ?? null) === 'Secret') {
                $seen['secret'] = $object;
            } elseif (str_contains($input, 'kind: Deployment')) {
                $seen['manifest'] = $input;
            }

            return Process::result(output: 'configured');
        }

        if (preg_match('#get deployment/([a-z0-9-]+) #', $cmd, $m) === 1) {
            return Process::result(output: in_array($m[1], $liveDeployments, true) ? "deployment/{$m[1]}" : '');
        }

        if (preg_match("#get secret n8n-secrets-flow-example-com .*jsonpath='?\\{\\.data\\.([a-z-]+)\\}#", $cmd, $m) === 1) {
            return Process::result(output: isset($secret[$m[1]]) ? base64_encode($secret[$m[1]]) : '');
        }

        return match (true) {
            str_contains($cmd, 'get configmap plex-commons') => Process::result(output: json_encode(['services' => ['postgres' => ['enabled' => true]]])),
            str_contains($cmd, 'get configmap plex-registry') => Process::result(output: '', exitCode: 1),
            str_contains($cmd, ' rollout status ') && $rolloutFails => Process::result(errorOutput: 'deployment exceeded its progress deadline', exitCode: 1),
            str_contains($cmd, ' rollout status ') => Process::result(output: 'successfully rolled out'),
            default => Process::result(output: ''),
        };
    });
}

function runFlowInit(string $engine = 'n8n'): Illuminate\Testing\PendingCommand
{
    return test()->artisan('tool:init', [
        '--tool' => $engine === 'windmill' ? 'windmill' : 'n8n',
        'environment' => 'local',
        '--domain' => 'flow.example.com',
        '--force' => true,
        '--no-interaction' => true,
    ]);
}

test('tool:init --tool=n8n names every n8n resource after its host and pins the image from the N8n class', function (): void {
    fakeFlowInitCluster($seen);

    runFlowInit()->assertExitCode(0);

    expect($seen['manifest'])->toContain('name: n8n-flow-example-com')
        ->toContain('name: n8n-secrets-flow-example-com')
        ->toContain('claimName: n8n-storage-flow-example-com')
        ->toContain('value: n8n_flow_example_com')
        ->toContain('image: '.(new App\Tools\N8n)->image('n8n'))
        // The old labels said `larakube-tool: flow` — a leftover category name
        // that never matched the real tool. The modern `larakube.io/*` labels
        // (from $names->labels()) must say n8n, not the category it dispatches from.
        ->toContain('larakube.io/tool: n8n')
        ->not->toContain('larakube-tool: flow')
        ->not->toContain('flow-secrets')
        ->not->toContain('  name: n8n'.PHP_EOL)
        ->and($seen['secret']['metadata']['name'] ?? null)->toBe('n8n-secrets-flow-example-com');
});

test('tool:init --tool=n8n reuses the instance\'s encryption key and never puts it in argv', function (): void {
    fakeFlowInitCluster($seen, ['encryption-key' => 'kept-encryption-key', 'db-password' => 'kept-db-password']);

    runFlowInit()->assertExitCode(0);

    expect(base64_decode($seen['secret']['data']['encryption-key']))->toBe('kept-encryption-key')
        ->and(base64_decode($seen['secret']['data']['db-password']))->toBe('kept-db-password')->and($seen['commands'])->each->not->toContain('kept-encryption-key');
});

test('tool:init --tool=n8n refuses a second engine on a host that already runs one', function (): void {
    fakeFlowInitCluster($seen, liveDeployments: ['n8n-flow-example-com']);

    runFlowInit('windmill')
        ->expectsOutputToContain('flow.example.com already runs n8n')
        ->assertExitCode(1);

    expect($seen['manifest'])->toBeNull();
});

test('windmill names its resources after its host too', function (): void {
    fakeFlowInitCluster($seen);

    runFlowInit('windmill')->assertExitCode(0);

    expect($seen['manifest'])->toContain('name: windmill-flow-example-com')
        ->toContain('name: windmill-secrets-flow-example-com')
        ->toContain('windmill_flow_example_com')
        ->toContain('image: '.(new App\Tools\Windmill)->image('windmill'))
        ->not->toContain('flow-secrets');
});

test('tool:init deploys each engine locally at its default host using Plex Commons Postgres', function (string $engine, string $label): void {
    fakeFlowInitCluster($seen);

    $this->artisan("tool:init --tool={$engine} local")
        ->assertExitCode(0)
        ->expectsOutputToContain("Applying {$label} manifests...")
        ->expectsOutputToContain("{$label} stack is live.");

    expect($seen['manifest'])->toContain("name: {$engine}-")
        ->toContain('postgres.larakube-plex.svc.cluster.local');
})->with([['n8n', 'n8n'], ['windmill', 'Windmill']]);

test('tool:init --tool=n8n stops, instead of reporting it live, when the rollout fails', function (): void {
    fakeFlowInitCluster($seen, rolloutFails: true);

    runFlowInit()
        ->expectsOutputToContain('deployment exceeded its progress deadline')
        ->doesntExpectOutputToContain('stack is live')
        ->assertExitCode(1);
});

// n8n:remove's own coverage lives in ToolRemoveCommandTest.php (shared
// AbstractToolRemoveCommand behavior tested once across tools, including
// flow) rather than duplicated here.

/** A single, nearly-full node — too small for n8n's real resources: block to fit. */
function tinyOneNodeCapacity(): array
{
    return [
        'nodes' => [['status' => ['allocatable' => ['cpu' => '1', 'memory' => '1Gi']]]],
        'pods' => [[
            'status' => ['phase' => 'Running'],
            'spec' => ['containers' => [['resources' => ['requests' => ['cpu' => '900m', 'memory' => '900Mi']]]]],
        ]],
    ];
}

test('tool:init --tool=n8n refuses on a cluster without enough free capacity, non-interactively and without --force', function (): void {
    fakeFlowInitCluster($seen, capacity: tinyOneNodeCapacity());

    test()->artisan('tool:init', [
        '--tool' => 'n8n',
        'environment' => 'local',
        '--domain' => 'flow.example.com',
        '--no-interaction' => true,
    ])
        ->assertExitCode(1)
        ->expectsOutputToContain('This cluster may not have enough free capacity for this install');

    expect($seen['manifest'])->toBeNull();
});

test('tool:init --tool=n8n --force proceeds past a cluster without enough free capacity', function (): void {
    fakeFlowInitCluster($seen, capacity: tinyOneNodeCapacity());

    runFlowInit() // already passes --force
        ->assertExitCode(0)
        ->expectsOutputToContain('Proceeding despite low free cluster capacity (--force).');

    expect($seen['manifest'])->not->toBeNull();
});
