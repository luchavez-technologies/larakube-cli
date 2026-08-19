<?php

/**
 * Tests for the shared environment-context resolution — the bit that lets
 * commands target an env's OWN context (larakube-<ip>) via `kubectl --context`
 * instead of switching the global context.
 *
 * The reachability/discovery methods shell out to real kubectl via the Process
 * facade, which Process::fake() intercepts at the facade level — no more
 * "belongs in a cluster smoke test" (the previous version of this docblock);
 * that caveat only existed because raw exec()/shell_exec() had no equivalent.
 * The prompt/persist paths (promptCloudTarget() and friends) still involve
 * real Prompts I/O and stay out of scope here.
 *
 * Every kubectl call is pinned to ~/.kube/config (contextKubectl()) — a bare
 * `kubectl` would otherwise follow the shell's own $KUBECONFIG if one is set.
 */

use App\Traits\ResolvesEnvironmentContext;
use Illuminate\Support\Facades\Process;

function envContext(): object
{
    return new class
    {
        use ResolvesEnvironmentContext;

        public function reachable(?string $context): bool
        {
            return $this->environmentContextReachable($context);
        }

        public function nodeCount(string $context): int
        {
            return $this->clusterNodeCount($context);
        }

        public function contexts(): array
        {
            return $this->availableKubeContexts();
        }

        public function currentContext(): string
        {
            return $this->currentKubeContext();
        }
    };
}

function envContextKubectl(): string
{
    return 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';
}

test('environmentContextName matches the name cloud:init creates', function (): void {
    expect(envContext()->environmentContextName('159.223.43.95'))->toBe('larakube-159.223.43.95');
});

test('contextKubectl scopes kubectl to a context, or stays plain when null/empty', function (): void {
    $e = envContext();
    $kubectl = envContextKubectl();

    expect($e->contextKubectl('larakube-159.223.43.95'))->toBe("{$kubectl} --context 'larakube-159.223.43.95'")
        ->and($e->contextKubectl(null))->toBe($kubectl)
        ->and($e->contextKubectl(''))->toBe($kubectl);
});

test('environmentContextReachable is true when cluster-info succeeds and false when it fails', function (): void {
    $kubectl = envContextKubectl();

    Process::fake([
        "{$kubectl} --context 'larakube-1.2.3.4' cluster-info --request-timeout=5s" => Process::result(exitCode: 0),
        "{$kubectl} --context 'larakube-9.9.9.9' cluster-info --request-timeout=5s" => Process::result(exitCode: 1),
    ]);

    $e = envContext();

    expect($e->reachable('larakube-1.2.3.4'))->toBeTrue()
        ->and($e->reachable('larakube-9.9.9.9'))->toBeFalse();

    Process::assertRan("{$kubectl} --context 'larakube-1.2.3.4' cluster-info --request-timeout=5s");
});

test('availableKubeContexts trims and filters blank lines from kubectl config get-contexts', function (): void {
    Process::fake([
        envContextKubectl().' config get-contexts -o name' => "ctx-a\nctx-b\n\n",
    ]);

    expect(envContext()->contexts())->toBe(['ctx-a', 'ctx-b']);
});

test('availableKubeContexts is empty when kubectl has no contexts (or is not installed)', function (): void {
    Process::fake([envContextKubectl().' config get-contexts -o name' => Process::result(output: '', exitCode: 1)]);

    expect(envContext()->contexts())->toBe([]);
});

test('currentKubeContext trims the active context, empty string when there is none', function (): void {
    $kubectl = envContextKubectl();

    Process::fake(["{$kubectl} config current-context" => "k3s-larakube\n"]);
    expect(envContext()->currentContext())->toBe('k3s-larakube');

    Process::fake(["{$kubectl} config current-context" => Process::result(output: '', exitCode: 1)]);
    expect(envContext()->currentContext())->toBe('');
});

test('clusterNodeCount counts whitespace-separated node names, zero when unreachable', function (): void {
    $kubectl = envContextKubectl();

    Process::fake([
        "{$kubectl} --context 'prod' get nodes -o jsonpath='{.items[*].metadata.name}'" => 'node-1 node-2 node-3',
        "{$kubectl} --context 'unreachable' get nodes -o jsonpath='{.items[*].metadata.name}'" => Process::result(output: '', exitCode: 1),
    ]);

    $e = envContext();

    expect($e->nodeCount('prod'))->toBe(3)
        ->and($e->nodeCount('unreachable'))->toBe(0);
});
