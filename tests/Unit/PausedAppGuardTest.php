<?php

use App\Commands\Tool\AbstractToolInitCommand;
use App\Facades\State;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function pausedGuardManifest(): string
{
    return <<<'YAML'
    apiVersion: apps/v1
    kind: Deployment
    metadata:
      name: documenso-sign-example-com
      namespace: larakube-shared
    spec:
      replicas: 1
      template:
        spec:
          containers:
            - name: app
              resources:
                requests:
                  cpu: 100m
                  memory: 128Mi
    YAML;
}

/** @param  array<string, mixed>  $liveDeployment */
function pausedGuardFakes(array $liveDeployment): array
{
    return [
        '*get deployment documenso-sign-example-com -n larakube-shared -o json*' => Process::result(output: json_encode($liveDeployment)),
        // Plenty of room, so only the paused check can refuse.
        '*get nodes -o json*' => Process::result(output: json_encode(['items' => [
            ['status' => ['allocatable' => ['cpu' => '8', 'memory' => '16Gi']]],
        ]])),
        '*get pods -A -o json*' => Process::result(output: json_encode(['items' => []])),
        '*' => Process::result(output: ''),
    ];
}

function pausedGuardCommand(BufferedOutput $buffer): AbstractToolInitCommand
{
    $command = new class extends AbstractToolInitCommand
    {
        protected $signature = 'test:paused-guard {--force} {--no-interaction}';

        public function guard(string $kubectl, string $manifest): bool
        {
            return $this->guardPreInstall($kubectl, $manifest);
        }

        protected function runInit(): int
        {
            return 0;
        }
    };

    // --force, as tool:update passes: it must not get past a paused app.
    $in = new ArrayInput(['--force' => true]);
    $in->bind($command->getDefinition());
    $in->setInteractive(false);
    $command->setInput($in);
    $command->setOutput(new OutputStyle($in, $buffer));

    return $command;
}

test('a paused app is never re-applied, even with --force', function (): void {
    Process::fake(pausedGuardFakes([
        'kind' => 'Deployment',
        'metadata' => ['name' => 'documenso-sign-example-com', 'annotations' => ['larakube.app/paused-replicas' => '1']],
        'spec' => ['replicas' => 0],
    ]));

    expect(pausedGuardCommand(new BufferedOutput)->guard('kubectl', pausedGuardManifest()))->toBeFalse()
        ->and(State::lastError())->toContain('is paused (documenso-sign-example-com), so it was left untouched');
});

test('a running app, or one with only a stale pause marker, is applied as usual', function (array $live): void {
    Process::fake(pausedGuardFakes($live));

    expect(pausedGuardCommand(new BufferedOutput)->guard('kubectl', pausedGuardManifest()))->toBeTrue();
})->with([
    'running' => [['kind' => 'Deployment', 'metadata' => ['name' => 'documenso-sign-example-com'], 'spec' => ['replicas' => 1]]],
    'stale marker' => [['kind' => 'Deployment', 'metadata' => ['name' => 'documenso-sign-example-com', 'annotations' => ['larakube.app/paused-replicas' => '1']], 'spec' => ['replicas' => 1]]],
]);

test('a first install, with nothing live yet, is applied as usual', function (): void {
    Process::fake(['*get deployment documenso-sign-example-com*' => Process::result(output: '')] + pausedGuardFakes([]));

    expect(pausedGuardCommand(new BufferedOutput)->guard('kubectl', pausedGuardManifest()))->toBeTrue();
});
