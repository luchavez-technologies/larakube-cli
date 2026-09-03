<?php

use App\Data\CloudData;
use App\Data\ConfigData;
use App\Traits\DeploysClusterTool;
use Illuminate\Support\Facades\Process;

function resolveToolContextHolder(?ConfigData $config, bool $canPrompt = false): object
{
    return new class($config, $canPrompt)
    {
        use DeploysClusterTool;

        public bool $prompted = false;

        public function __construct(public ?ConfigData $projectConfig, public bool $canPrompt) {}

        public function resolve(string $env, ?string $explicit = null): ?string
        {
            return $this->resolveToolContext($env, $explicit);
        }

        protected function getProjectConfig(?string $projectPath = null): ?ConfigData
        {
            return $this->projectConfig;
        }

        protected function canPromptForContext(): bool
        {
            return $this->canPrompt;
        }

        // Must match what resolveToolContext() actually calls. It used to be
        // promptCloudTarget(); when that changed, this override went dead and
        // the REAL method ran — shelling out to kubectl and then blocking
        // forever on a live select() prompt inside the test suite.
        protected function captureToolContext(ConfigData $config, string $env, string $path): ?string
        {
            $this->prompted = true;

            return null;
        }
    };
}

function resolveToolContextProject(?CloudData $cloud): ConfigData
{
    $config = new ConfigData(id: 'demo', name: 'demo', path: '/tmp/demo');
    $config->setEnvironments(['local', 'production']);

    if ($cloud !== null) {
        $config->environments['production']->cloud = $cloud;
    }

    return $config;
}

test('local needs no context', function (): void {
    expect(resolveToolContextHolder(null)->resolve('local'))->toBeNull();
});

test('an explicit --context wins and is never recorded', function (): void {
    // Deliberately not persisted: passing the flag must not trigger the
    // SSH-details capture as a side effect of a one-off run.
    $holder = resolveToolContextHolder(null);

    expect($holder->resolve('production', 'larakube-1.2.3.4'))->toBe('larakube-1.2.3.4')
        ->and($holder->prompted)->toBeFalse();
});

test("a tool reads the context from the PROJECT's own blueprint", function (): void {
    // An environment name is project-relative — the same cluster can be
    // "production" here and "staging" next door — so the answer lives with the
    // project, never machine-wide.
    $holder = resolveToolContextHolder(resolveToolContextProject(new CloudData(ip: '9.9.9.9')));

    expect($holder->resolve('production'))->toBe('larakube-9.9.9.9')
        ->and($holder->prompted)->toBeFalse();
});

test('a managed cluster is reached by its recorded context name', function (): void {
    $holder = resolveToolContextHolder(resolveToolContextProject(new CloudData(context: 'doks-nyc1')));

    expect($holder->resolve('production'))->toBe('doks-nyc1');
});

test('inside a project with nothing recorded, it captures the target once', function (): void {
    // This is what makes `crm:init production` afterwards ask nothing: data:init
    // records into .larakube.local.json, and every sibling tool reads it.
    $holder = resolveToolContextHolder(resolveToolContextProject(null), canPrompt: true);

    try {
        $holder->resolve('production');
    } catch (RuntimeException) {
        // The stub prompt records nothing, so it still ends in the refusal.
    }

    expect($holder->prompted)->toBeTrue();
});

test('outside a project it refuses rather than using the current context', function (): void {
    // The bug this closes: data:init production silently deployed a production
    // tool onto the local orbstack cluster and printed success.
    Process::fake(['*config get-contexts*' => Process::result(output: '')]);

    expect(fn () => resolveToolContextHolder(null)->resolve('production'))
        ->toThrow(RuntimeException::class, 'No kube-context recorded');
});

test('the refusal names the flag that fixes it', function (): void {
    Process::fake(['*config get-contexts*' => Process::result(output: '')]);

    try {
        resolveToolContextHolder(null)->resolve('staging');
        $this->fail('expected a refusal');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('--context=')->toContain("'staging'");
    }
});
