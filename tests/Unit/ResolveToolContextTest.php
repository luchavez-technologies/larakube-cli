<?php

use App\Traits\DeploysClusterTool;
use Illuminate\Support\Facades\Process;

function resolveToolContextHolder(?string $explicit = null): object
{
    return new class($explicit)
    {
        use DeploysClusterTool;

        public array $remembered = [];

        public function __construct(public ?string $explicit) {}

        public function resolve(string $env): ?string
        {
            return $this->resolveToolContext($env, $this->explicit);
        }

        // Keep the machine-wide config file out of the test.
        protected function rememberEnvironmentContext(string $env, string $context): void
        {
            $this->remembered[$env] = $context;
        }

        protected function globalEnvironmentContext(string $env): ?string
        {
            return null;
        }
    };
}

test('local needs no context', function (): void {
    expect(resolveToolContextHolder()->resolve('local'))->toBeNull();
});

test('an explicit --context wins and is remembered', function (): void {
    $holder = resolveToolContextHolder('larakube-1.2.3.4');

    expect($holder->resolve('production'))->toBe('larakube-1.2.3.4')
        ->and($holder->remembered)->toBe(['production' => 'larakube-1.2.3.4']);
});

test('a cloud environment with no recorded context REFUSES rather than using the current one', function (): void {
    // The bug: `data:init production` fell back to the current kube-context and
    // deployed a production tool — real public host and all — onto a local
    // orbstack cluster, then printed a success message.
    Process::fake(['*config get-contexts*' => Process::result(output: '')]);

    expect(fn () => resolveToolContextHolder()->resolve('production'))
        ->toThrow(RuntimeException::class, 'No kube-context recorded');
});

test('the refusal names the flag that fixes it', function (): void {
    Process::fake(['*config get-contexts*' => Process::result(output: '')]);

    try {
        resolveToolContextHolder()->resolve('staging');
        $this->fail('expected a refusal');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('--context=')
            ->toContain("'staging'");
    }
});
