<?php

use App\Commands\Flow\FlowInitCommand;
use Illuminate\Support\Facades\Process;

/**
 * Fakes a cluster for flow:init. $secret seeds the instance's existing
 * credentials Secret; $liveDeployments are Deployments already running.
 *
 * @param  array<string, string>  $secret
 * @param  list<string>  $liveDeployments
 * @param  array{manifest: ?string, secret: ?array, commands: list<string>}|null  $seen
 */
function fakeFlowInitCluster(?array &$seen, array $secret = [], array $liveDeployments = [], bool $rolloutFails = false): void
{
    $seen = ['manifest' => null, 'secret' => null, 'commands' => []];

    Process::fake(function ($process) use (&$seen, $secret, $liveDeployments, $rolloutFails) {
        $cmd = (string) $process->command;
        $seen['commands'][] = $cmd;

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

        if (preg_match("#get secret flow-n8n-secrets-flow-example-com .*jsonpath='?\\{\\.data\\.([a-z-]+)\\}#", $cmd, $m) === 1) {
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
    return test()->artisan(FlowInitCommand::class, [
        'environment' => 'local',
        '--engine' => $engine,
        '--domain' => 'flow.example.com',
        '--force' => true,
        '--no-interaction' => true,
    ]);
}

test('flow:init names every n8n resource after its host and pins the image from the N8n class', function (): void {
    fakeFlowInitCluster($seen);

    runFlowInit()->assertExitCode(0);

    expect($seen['manifest'])->toContain('name: flow-n8n-flow-example-com')
        ->toContain('name: flow-n8n-secrets-flow-example-com')
        ->toContain('claimName: flow-n8n-storage-flow-example-com')
        ->toContain('value: n8n_flow_example_com')
        ->toContain('image: '.(new App\Tools\N8n)->image('n8n'))
        ->toContain('larakube-tool: flow')
        ->not->toContain('flow-secrets')
        ->not->toContain('name: flow-n8n'.PHP_EOL)
        ->and($seen['secret']['metadata']['name'] ?? null)->toBe('flow-n8n-secrets-flow-example-com');
});

test('flow:init reuses the instance\'s encryption key and never puts it in argv', function (): void {
    fakeFlowInitCluster($seen, ['encryption-key' => 'kept-encryption-key', 'db-password' => 'kept-db-password']);

    runFlowInit()->assertExitCode(0);

    expect(base64_decode($seen['secret']['data']['encryption-key']))->toBe('kept-encryption-key')
        ->and(base64_decode($seen['secret']['data']['db-password']))->toBe('kept-db-password')->and($seen['commands'])->each->not->toContain('kept-encryption-key');
});

test('flow:init refuses a second engine on a host that already runs one', function (): void {
    fakeFlowInitCluster($seen, liveDeployments: ['flow-n8n-flow-example-com']);

    runFlowInit('windmill')
        ->expectsOutputToContain('flow.example.com already runs n8n')
        ->assertExitCode(1);

    expect($seen['manifest'])->toBeNull();
});

test('flow:init windmill names its resources after its host too', function (): void {
    fakeFlowInitCluster($seen);

    runFlowInit('windmill')->assertExitCode(0);

    expect($seen['manifest'])->toContain('name: flow-windmill-flow-example-com')
        ->toContain('name: flow-windmill-secrets-flow-example-com')
        ->toContain('windmill_flow_example_com')
        ->toContain('image: '.(new App\Tools\Windmill)->image('windmill'))
        ->not->toContain('flow-secrets');
});

test('flow:init deploys each engine locally at its default host using Plex Commons Postgres', function (string $engine, string $label): void {
    fakeFlowInitCluster($seen);

    $this->artisan("flow:init local --engine={$engine}")
        ->assertExitCode(0)
        ->expectsOutputToContain("Applying Flow ({$label}) manifests...")
        ->expectsOutputToContain("Flow ({$label}) stack is live.");

    expect($seen['manifest'])->toContain("name: flow-{$engine}-")
        ->toContain('postgres.larakube-plex.svc.cluster.local');
})->with([['n8n', 'n8n'], ['windmill', 'Windmill']]);

test('flow:init stops, instead of reporting it live, when the rollout fails', function (): void {
    fakeFlowInitCluster($seen, rolloutFails: true);

    runFlowInit()
        ->expectsOutputToContain('deployment exceeded its progress deadline')
        ->doesntExpectOutputToContain('stack is live')
        ->assertExitCode(1);
});

// flow:remove's own coverage lives in ToolRemoveCommandTest.php (shared
// AbstractToolRemoveCommand behavior tested once across tools, including
// flow) rather than duplicated here.
