<?php

use App\Enums\ClusterTool;
use App\Support\Labels;
use App\Support\ToolRevision;
use Illuminate\Support\Facades\Process;

function toolRevisionStampFakes(): array
{
    return [
        '*get secret *' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*wait *' => Process::result(output: 'wait success'),
        '*exec *' => Process::result(output: 'success'),
        '*annotate *' => Process::result(output: 'annotated'),
    ];
}

/** The `kubectl annotate` the install ran, or null. */
function toolRevisionStampCommand(): ?string
{
    $stamp = null;

    Process::assertRan(function ($process) use (&$stamp): bool {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        if (str_contains($command, ' annotate ') && str_contains($command, Labels::REVISION)) {
            $stamp = $command;

            return true;
        }

        return false;
    });

    return $stamp;
}

test('an install stamps its workloads with the revision that rendered them and the options it ran with', function (): void {
    Process::fake(toolRevisionStampFakes());

    $this->artisan('tool:init --tool=headlamp local --vpn-only --no-interaction')->assertExitCode(0);

    $stamp = toolRevisionStampCommand();

    expect($stamp)->not->toBeNull()
        ->and($stamp)->toContain('deployment,statefulset,daemonset')
        ->and($stamp)->toContain(Labels::TOOL.'=headlamp')
        ->and($stamp)->toContain(Labels::REVISION.'='.ToolRevision::for(ClusterTool::HEADLAMP))
        ->and($stamp)->toContain('"vpn-only":true')
        ->and($stamp)->toContain('--overwrite')
        // Mechanics are never replayed.
        ->and($stamp)->not->toContain('"force"')
        ->and($stamp)->not->toContain('"context"');
});

test('a revision changes when the tool\'s templates or pins would render differently, and only then', function (): void {
    $headlamp = ToolRevision::for(ClusterTool::HEADLAMP);

    expect($headlamp)->toMatch('/^[0-9a-f]{12}$/')
        ->and(ToolRevision::for(ClusterTool::HEADLAMP))->toBe($headlamp)
        ->and(ToolRevision::for(ClusterTool::N8N))->not->toBe($headlamp);
});

test('credentials passed at install are never recorded in the install-options annotation', function (): void {
    $command = new class extends Illuminate\Console\Command
    {
        use App\Traits\DeploysClusterTool;

        protected $signature = 'stamp:probe {--provider=} {--cloudflare-token=} {--aws-access-key-id=} {--aws-secret-access-key=} {--gcp-credentials=} {--zone=*} {--context=} {--force}';

        public function handle(): int
        {
            return self::SUCCESS;
        }

        public function recorded(): array
        {
            return $this->recordableInstallOptions(ClusterTool::EXTERNAL_DNS);
        }
    };

    $command->setLaravel(app());
    $command->run(new Symfony\Component\Console\Input\ArrayInput([
        '--provider' => 'route53',
        '--cloudflare-token' => 'cf-secret',
        '--aws-access-key-id' => 'AKIAEXAMPLE',
        '--aws-secret-access-key' => 'aws-secret',
        '--gcp-credentials' => '/tmp/key.json',
        '--zone' => ['example.com'],
        '--context' => 'prod',
        '--force' => true,
    ]), new Symfony\Component\Console\Output\NullOutput);

    $recorded = $command->recorded();

    expect($recorded)->toHaveKey('provider', 'route53')
        ->and(json_encode($recorded))->not->toContain('cf-secret')
        ->and(json_encode($recorded))->not->toContain('AKIAEXAMPLE')
        ->and(json_encode($recorded))->not->toContain('aws-secret')
        ->and(json_encode($recorded))->not->toContain('key.json')
        ->and($recorded)->not->toHaveKey('context')
        ->and($recorded)->not->toHaveKey('force');
});

test('the stamp records what an install settled on, not only what was typed', function (): void {
    // No component flags: Grafana settles on metrics only. Both choices are
    // recorded explicitly, so a later update never falls back to a default.
    Process::fake(toolRevisionStampFakes());

    $this->artisan('tool:init --tool=grafana local --no-plex --no-interaction')->assertExitCode(0);

    expect(toolRevisionStampCommand())->toContain('"no-logs":true')
        ->and(toolRevisionStampCommand())->toContain('"no-traces":true')
        ->and(toolRevisionStampCommand())->not->toContain('with-logs');
});

test('a Grafana install that runs logs records it, so an update keeps Loki', function (): void {
    Process::fake(toolRevisionStampFakes());

    $this->artisan('tool:init --tool=grafana local --no-plex --with-logs --no-interaction')->assertExitCode(0);

    expect(toolRevisionStampCommand())->toContain('"with-logs":true')
        ->and(toolRevisionStampCommand())->toContain('"no-traces":true')
        ->and(toolRevisionStampCommand())->not->toContain('"no-logs"');
});
