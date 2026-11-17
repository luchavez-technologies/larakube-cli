<?php

use Illuminate\Support\Facades\Process;
use Tests\Support\FakeToolRegistry;

/**
 * tool:pause / tool:resume scale an instance's own Deployments to/from zero,
 * remembering the prior replica count as a Deployment annotation. $calls
 * collects the annotate/scale commands the fake cluster receives.
 */
/**
 * Laravel's FakeProcessResult::normalizeOutput() runs the faked output
 * through empty($output), and PHP's empty('0') is true — so faking the
 * literal string "0" silently becomes "". Pad it; liveReplicas()/
 * pausedReplicas() trim() the real output anyway.
 */
function fakeReplicaOutput(?int $value): string
{
    return $value === null ? '' : " {$value} ";
}

function toolPauseCluster(?array &$calls, int $liveReplicas, ?int $pausedAnnotation = null): void
{
    $calls = [];
    Process::fake(function ($process) use (&$calls, $liveReplicas, $pausedAnnotation) {
        $cmd = (string) $process->command;

        return match (true) {
            str_contains($cmd, "jsonpath='{.spec.replicas}'") => Process::result(output: fakeReplicaOutput($liveReplicas)),
            str_contains($cmd, 'annotations.larakube\.app/paused-replicas') => Process::result(output: fakeReplicaOutput($pausedAnnotation)),
            str_contains($cmd, ' annotate deployment ') || str_contains($cmd, ' scale deployment') => (function () use (&$calls, $cmd) {
                $calls[] = $cmd;

                return Process::result(output: 'ok');
            })(),
            default => Process::result(output: ''),
        };
    });
}

function toolPauseRegistry(): FakeToolRegistry
{
    return FakeToolRegistry::install([[
        'tool' => 'n8n', 'instance' => 'flow-example-com', 'host' => 'flow.example.com', 'engine' => 'n8n',
    ]]);
}

test('tool:pause records the live replica count and scales to zero', function (): void {
    toolPauseCluster($calls, liveReplicas: 3);
    toolPauseRegistry();

    $this->artisan('tool:pause production --domain=flow.example.com --context=ctx')->assertExitCode(0);

    expect($calls)->toHaveCount(2)
        ->and($calls[0])->toContain('annotate deployment n8n-flow-example-com -n larakube-shared larakube.app/paused-replicas=')
        ->and($calls[0])->toContain('--overwrite')
        ->and($calls[1])->toContain('scale deployment/n8n-flow-example-com -n larakube-shared --replicas=0');
});

test('tool:pause on an already-paused tool is a no-op', function (): void {
    toolPauseCluster($calls, liveReplicas: 0);
    toolPauseRegistry();

    $this->artisan('tool:pause production --domain=flow.example.com --context=ctx')->assertExitCode(0);

    expect($calls)->toBe([]);
});

test('tool:resume restores the remembered replica count and clears the marker', function (): void {
    toolPauseCluster($calls, liveReplicas: 0, pausedAnnotation: 3);
    toolPauseRegistry();

    $this->artisan('tool:resume production --domain=flow.example.com --context=ctx')->assertExitCode(0);

    expect($calls)->toHaveCount(2)
        ->and($calls[0])->toContain('scale deployment/n8n-flow-example-com -n larakube-shared --replicas=3')
        ->and($calls[1])->toContain('annotate deployment n8n-flow-example-com -n larakube-shared larakube.app/paused-replicas-');
});

test('tool:resume with no remembered count defaults to 1', function (): void {
    toolPauseCluster($calls, liveReplicas: 0, pausedAnnotation: null);
    toolPauseRegistry();

    $this->artisan('tool:resume production --domain=flow.example.com --context=ctx')->assertExitCode(0);

    expect($calls[0])->toContain('scale deployment/n8n-flow-example-com -n larakube-shared --replicas=1');
});

test('tool:resume on an already-running tool is a no-op', function (): void {
    toolPauseCluster($calls, liveReplicas: 2);
    toolPauseRegistry();

    $this->artisan('tool:resume production --domain=flow.example.com --context=ctx')->assertExitCode(0);

    expect($calls)->toBe([]);
});

test('an unknown host names the registered ones', function (): void {
    toolPauseCluster($calls, liveReplicas: 3);
    toolPauseRegistry();

    $this->artisan('tool:pause production --domain=typo.example.com --context=ctx')->assertExitCode(1);

    expect($calls)->toBe([]);
});
