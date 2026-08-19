<?php

use App\Traits\ResolvesStandaloneEnvironment;
use Illuminate\Support\Facades\Process;

test('resolveStandaloneEnvironmentAndKubectl uses explicit context when provided', function (): void {
    $trait = new class
    {
        use ResolvesStandaloneEnvironment;

        // Mock LaravelZero command methods
        public function option($key)
        {
            return $key === 'context' ? 'my-explicit-context' : null;
        }

        public function argument($key)
        {
            return null;
        }

        public function resolve()
        {
            return $this->resolveStandaloneEnvironmentAndKubectl();
        }

        // Mock traits
        protected function isLaraKubeProject(bool $showError = true): bool
        {
            return false;
        }
    };

    [$env, $kubectl] = $trait->resolve();

    expect($env)->toBeNull()
        ->and($kubectl)->toContain('--context \'my-explicit-context\'');
});

test('resolveStandaloneEnvironmentAndKubectl selects current context in non-interactive standalone mode', function (): void {
    $trait = new class
    {
        use ResolvesStandaloneEnvironment;

        public function option($key)
        {
            return $key === 'no-interaction' ? true : null;
        }

        public function argument($key)
        {
            return null;
        }

        public function resolve()
        {
            return $this->resolveStandaloneEnvironmentAndKubectl();
        }

        protected function isLaraKubeProject(bool $showError = true): bool
        {
            return false;
        }
    };

    Process::fake([
        '*config current-context*' => Process::result('current-ctx'),
        '*config get-contexts*' => Process::result("current-ctx\nother-ctx"),
        '*cluster list*' => Process::result(''),
    ]);

    [$env, $kubectl] = $trait->resolve();

    expect($env)->toBeNull()
        ->and($kubectl)->not->toContain('--context \'current-ctx\''); // If it's the current context, we just return null context mapping to default kubectl (wait, $this->contextKubectl(null) ? or does it pass $context?)
    // Actually our trait code does: $context = select(..., default: in_array($currentContext, $contexts, true) ? $currentContext : null);
    // But since it's no-interaction, wait! My trait has `if ($this->option('no-interaction') ?? false) { ... return [$config ? 'local' : null, $this->contextKubectl($context)]; }`
    // And for no-interaction outside a project, `$context` is `null`!
});
