<?php

use App\Enums\ClusterTool;
use App\Support\Labels;
use App\Support\ToolRevision;
use Illuminate\Support\Facades\Process;

/**
 * @param  list<array<string, mixed>>  $registry
 * @param  list<array<string, mixed>>  $workloads
 * @param  list<array<string, mixed>>  $ingresses
 */
function toolUpdateFakes(array $registry, array $workloads = [], array $ingresses = []): array
{
    return [
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode($registry))),
        '*get deployment,statefulset,daemonset -A*' => Process::result(output: json_encode(['items' => $workloads])),
        '*get ingress *' => Process::result(output: json_encode(['items' => $ingresses])),
        '*' => Process::result(output: ''),
    ];
}

function toolUpdateWorkload(string $tool, string $instance, array $annotations = []): array
{
    return [
        'metadata' => [
            'labels' => [Labels::MANAGED_BY => 'larakube', Labels::TOOL => $tool, Labels::INSTANCE => $instance],
            'annotations' => $annotations,
        ],
        'spec' => ['template' => ['spec' => ['containers' => [['image' => 'example/image:1']]]]],
    ];
}

/** @return list<array<string, mixed>> */
function toolUpdateDryRun(object $test, string $arguments): array
{
    $exit = Artisan::call(trim("tool:update --context=test {$arguments} --dry-run --json"));

    expect($exit)->toBe(0);

    return json_decode(Artisan::output(), true);
}

test('tool:update needs exactly one of --tool or --all', function (string $arguments): void {
    Process::fake(toolUpdateFakes([]));

    $this->artisan("tool:update --context=test {$arguments}")
        ->assertExitCode(1)
        ->expectsOutputToContain('Pass either --tool=NAME or --all.');
})->with(['neither' => [''], 'both' => ['--all --tool=n8n']]);

test('--all re-runs every instance that is behind with the options it was installed with, and skips current ones', function (): void {
    Process::fake(toolUpdateFakes(
        [
            ['tool' => 'headlamp', 'instance' => 'dash-example-com', 'host' => 'dash.example.com'],
            ['tool' => 'n8n', 'instance' => 'flow-example-com', 'host' => 'flow.example.com'],
        ],
        [
            toolUpdateWorkload('headlamp', 'dash-example-com', [Labels::REVISION => 'aaaaaaaaaaaa', Labels::INSTALL_OPTIONS => '{"vpn-only":true,"app-name":"Ops"}']),
            toolUpdateWorkload('n8n', 'flow-example-com', [Labels::REVISION => ToolRevision::for(ClusterTool::N8N)]),
        ],
    ));

    $results = toolUpdateDryRun($this, '--all');

    expect($results)->toHaveCount(1)
        ->and($results[0]['tool'])->toBe('headlamp')
        ->and($results[0]['outcome'])->toBe('planned')
        ->and($results[0]['command'])->toBe('larakube tool:init --tool=headlamp --app-name=Ops --vpn-only --domain=dash.example.com --context=test --force');
});

test('an untracked install keeps its VPN restriction, Commons choice and admin email from what the cluster still knows', function (): void {
    Process::fake(toolUpdateFakes(
        [['tool' => 'stalwart', 'instance' => 'send-example-com', 'host' => 'send.example.com', 'noPlex' => true, 'adminEmail' => 'admin@example.com']],
        [toolUpdateWorkload('stalwart', 'send-example-com')],
        [[
            'metadata' => ['annotations' => ['traefik.ingress.kubernetes.io/router.middlewares' => 'larakube-shared-stalwart-vpn-only-send-example-com@kubernetescrd']],
            'spec' => ['rules' => [['host' => 'send.example.com']]],
        ]],
    ));

    $command = toolUpdateDryRun($this, '--all')[0]['command'];

    expect($command)->toContain('--admin-email=admin@example.com')
        ->and($command)->toContain('--vpn-only')
        ->and($command)->toContain('--domain=send.example.com');
});

test('a public untracked install is not made VPN-only', function (): void {
    Process::fake(toolUpdateFakes(
        [['tool' => 'outline', 'instance' => 'notes-example-com', 'host' => 'notes.example.com']],
        [toolUpdateWorkload('outline', 'notes-example-com')],
        [['metadata' => ['annotations' => []], 'spec' => ['rules' => [['host' => 'notes.example.com']]]]],
    ));

    expect(toolUpdateDryRun($this, '--all')[0]['command'])->not->toContain('--vpn-only');
});

test('--tool re-applies even an up-to-date instance, to repair drift', function (): void {
    Process::fake(toolUpdateFakes(
        [['tool' => 'n8n', 'instance' => 'flow-example-com', 'host' => 'flow.example.com']],
        [toolUpdateWorkload('n8n', 'flow-example-com', [Labels::REVISION => ToolRevision::for(ClusterTool::N8N)])],
    ));

    $results = toolUpdateDryRun($this, '--tool=n8n');

    expect($results[0]['was'])->toBe('current')
        ->and($results[0]['command'])->toContain('--tool=n8n --domain=flow.example.com');
});

test('--tool refuses to guess between instances', function (): void {
    Process::fake(toolUpdateFakes([
        ['tool' => 'n8n', 'instance' => 'a-example-com', 'host' => 'a.example.com'],
        ['tool' => 'n8n', 'instance' => 'b-example-com', 'host' => 'b.example.com'],
    ]));

    $this->artisan('tool:update --context=test --tool=n8n')
        ->assertExitCode(1)
        ->expectsOutputToContain('Pick one with --domain=');
});

test('--all with nothing behind says so and runs nothing', function (): void {
    Process::fake(toolUpdateFakes(
        [['tool' => 'n8n', 'instance' => 'flow-example-com', 'host' => 'flow.example.com']],
        [toolUpdateWorkload('n8n', 'flow-example-com', [Labels::REVISION => ToolRevision::for(ClusterTool::N8N)])],
    ));

    $this->artisan('tool:update --context=test --all')
        ->assertExitCode(0)
        ->expectsOutputToContain('Every installed tool is up to date.');
});

test('--all carries on past an instance whose re-run throws, and reports it as failed', function (): void {
    Process::fake(toolUpdateFakes(
        [
            ['tool' => 'outline', 'instance' => 'notes-example-com', 'host' => 'notes.example.com'],
            ['tool' => 'vaultwarden', 'instance' => 'vault-example-com', 'host' => 'vault.example.com'],
        ],
        [toolUpdateWorkload('outline', 'notes-example-com'), toolUpdateWorkload('vaultwarden', 'vault-example-com')],
    ));

    // A stand-in tool:init: Outline throws the way a missing --admin-email did live.
    $stub = new class extends Illuminate\Console\Command
    {
        /** @var list<string> */
        public array $ran = [];

        protected $signature = 'tool:init {--tool=} {--domain=} {--context=} {--force}';

        public function handle(): int
        {
            $this->ran[] = (string) $this->option('tool');

            if ($this->option('tool') === 'outline') {
                throw new App\Exceptions\MissingFlagException('admin-email', 'Primary administrator email for Outline');
            }

            return self::SUCCESS;
        }
    };
    app(Illuminate\Contracts\Console\Kernel::class)->registerCommand($stub);

    expect(Artisan::call('tool:update --context=test --all --json'))->toBe(1);

    $results = collect(json_decode(Artisan::output(), true))->keyBy('tool');

    expect($stub->ran)->toBe(['outline', 'vaultwarden'])
        ->and($results['outline']['outcome'])->toBe('failed')
        ->and($results['outline']['error'])->toContain('admin-email')
        ->and($results['vaultwarden']['outcome'])->toBe('updated');
});
