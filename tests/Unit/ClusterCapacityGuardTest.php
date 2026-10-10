<?php

use App\Commands\Tool\AbstractToolInitCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** A tight-but-real manifest: one container, easy to size exactly against a fake cluster snapshot. */
function guardManifest(string $cpu = '500m', string $memory = '512Mi'): string
{
    return <<<YAML
    apiVersion: apps/v1
    kind: Deployment
    metadata:
      name: app
      namespace: larakube-shared
    spec:
      replicas: 1
      template:
        spec:
          containers:
            - name: app
              resources:
                requests:
                  cpu: {$cpu}
                  memory: {$memory}
    YAML;
}

/** One node, 1 CPU / 1Gi allocatable, nothing else scheduled on it. */
function guardClusterFakes(): array
{
    return [
        '*get nodes -o json*' => Process::result(output: json_encode(['items' => [
            ['status' => ['allocatable' => ['cpu' => '1', 'memory' => '1Gi']]],
        ]])),
        '*get pods -A -o json*' => Process::result(output: json_encode(['items' => []])),
    ];
}

function guardCommand(bool $interactive, array $input = []): AbstractToolInitCommand
{
    $command = new class extends AbstractToolInitCommand
    {
        // --no-interaction is normally merged in by the console Application
        // for a real registered command; this bare test double needs it
        // declared explicitly so cannotPrompt() can read it.
        protected $signature = 'test:capacity-guard {--force} {--no-interaction}';

        public function guard(string $kubectl, string $manifest, float $margin = 0.10): bool
        {
            return $this->guardClusterCapacity($kubectl, $manifest, $margin);
        }

        protected function runInit(): int
        {
            return 0;
        }
    };

    $in = new ArrayInput($input);
    $in->bind($command->getDefinition());
    $in->setInteractive($interactive);
    $command->setInput($in);
    $command->setOutput(new OutputStyle($in, new BufferedOutput));

    return $command;
}

test('a manifest that fits within free headroom passes silently', function (): void {
    Process::fake(guardClusterFakes());

    // 500m/512Mi against a 1-core/1Gi node with a 10% margin (900m/~0.9Gi
    // usable) comfortably fits.
    expect(guardCommand(interactive: true)->guard('kubectl', guardManifest()))->toBeTrue();
});

test('a manifest sized right at the edge of free headroom still passes', function (): void {
    Process::fake(guardClusterFakes());

    // 900m against exactly the 90% usable (1000m * 0.9 margin) headroom.
    expect(guardCommand(interactive: true)->guard('kubectl', guardManifest(cpu: '900m', memory: '100Mi')))->toBeTrue();
});

test('--force proceeds past an undersized cluster without prompting', function (): void {
    Process::fake(guardClusterFakes());

    $command = guardCommand(interactive: true, input: ['--force' => true]);

    expect($command->guard('kubectl', guardManifest(cpu: '5000m', memory: '5Gi')))->toBeTrue();
});

// cannotPrompt() treats app()->runningUnitTests() as always non-interactive
// (so Prompts never blocks on a real terminal under Pest), which means the
// "ask and the operator accepts/declines" branch can't be driven from a
// unit test — only --force and the refusal path are reachable here. Same
// limitation the rest of this codebase already lives with (e.g.
// SsoPruneCommandTest only ever drives --force, never an answered prompt).
test('a non-interactive run with no --force refuses instead of guessing', function (): void {
    Process::fake(guardClusterFakes());

    $command = guardCommand(interactive: false);

    expect($command->guard('kubectl', guardManifest(cpu: '5000m', memory: '5Gi')))->toBeFalse();
});

test('an unreadable cluster state does not block the install', function (): void {
    Process::fake([
        '*get nodes -o json*' => Process::result(output: '', exitCode: 1),
        '*get pods -A -o json*' => Process::result(output: json_encode(['items' => []])),
    ]);

    // Even a wildly oversized demand can't be evaluated without the live
    // read, so the guard steps aside rather than guessing.
    expect(guardCommand(interactive: false)->guard('kubectl', guardManifest(cpu: '5000m', memory: '5Gi')))->toBeTrue();
});
