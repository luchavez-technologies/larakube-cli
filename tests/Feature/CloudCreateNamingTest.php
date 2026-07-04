<?php

/**
 * cloud:create stack naming: a stack is explicitly designed to be reused
 * across every environment of a project (StackData's own docblock), so its
 * name must never bake in an environment — only "{name}-{kind}", where kind
 * is always exactly 'vps' or 'managed'. Also covers the bug this surfaced:
 * createManaged() used to register stacks with the literal kind 'doks'
 * instead of 'managed', which silently broke stacksOfKind('managed') —
 * meaning "attach to an existing managed cluster" was unreachable.
 *
 * The harness extends CloudCreateCommand directly (its naming/registry
 * helpers aren't in a reusable trait) and overrides getGlobalConfig()/
 * putStack() to use an in-memory registry instead of the real
 * ~/.larakube/config.json, and no-ops line()/newLine() since a directly
 * instantiated Command has no bound console output.
 */

use App\Commands\Cloud\CloudCreateCommand;
use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Data\StackData;
use Laravel\Prompts\Prompt;

function cloudCreateRunner(): CloudCreateCommand
{
    return new class extends CloudCreateCommand
    {
        private GlobalConfigData $fakeGlobalConfig;

        public function __construct()
        {
            parent::__construct();
            $this->fakeGlobalConfig = new GlobalConfigData;
        }

        public function line($string, $style = null, $verbosity = null) {}

        public function newLine($count = 1) {}

        // --- public wrappers around the protected methods under test ---

        public function stackName(?string $nameBase, string $kind): string
        {
            return $this->promptStackName($nameBase, $kind);
        }

        public function envResolution(?string $rawArgument = null): array
        {
            return $this->resolveEnvironment($rawArgument);
        }

        public function expected(?string $nameBase, string $targetKind): ?StackData
        {
            return $this->findExpectedStack($nameBase, $targetKind);
        }

        public function register(string $name, string $kind): void
        {
            $this->registerStack($name, $kind, 'nyc1', null, null, null, null);
        }

        public function ofKind(string $kind): array
        {
            return $this->stacksOfKind($kind);
        }

        protected function getGlobalConfig(): GlobalConfigData
        {
            return $this->fakeGlobalConfig;
        }

        // Skip the real save() — the in-memory fake above is the registry.
        protected function putStack(StackData $stack): void
        {
            $this->fakeGlobalConfig->putStack($stack);
        }
    };
}

beforeEach(function () {
    Prompt::interactive(false);
});

test('promptStackName defaults to "{name}-{kind}" — no environment, no larakube- prefix', function () {
    $runner = cloudCreateRunner();

    expect($runner->stackName('myapp', 'vps'))->toBe('myapp-vps')
        ->and($runner->stackName('myapp', 'managed'))->toBe('myapp-managed');
});

test('promptStackName falls back to "standalone" when no name base is given', function () {
    expect(cloudCreateRunner()->stackName(null, 'vps'))->toBe('standalone-vps')
        ->and(cloudCreateRunner()->stackName('', 'vps'))->toBe('standalone-vps');
});

test('registerStack stores kind exactly as given — "managed", never the provider-specific "doks"', function () {
    $runner = cloudCreateRunner();
    $runner->register('myapp-managed', 'managed');

    $stacks = $runner->ofKind('managed');

    expect($stacks)->toHaveCount(1)
        ->and($stacks['myapp-managed']->kind)->toBe('managed');
});

test('stacksOfKind actually finds a registered managed stack — the bug this naming fix closes', function () {
    // Before the fix, createManaged() registered kind: 'doks' while
    // stacksOfKind() filtered on 'managed' — this assertion would have failed
    // (empty array) had that mismatch still been in place, since a stack
    // stored under the wrong kind can never be found by the right one.
    $runner = cloudCreateRunner();
    $runner->register('myapp-managed', 'managed');
    $runner->register('myapp-vps', 'vps');

    expect($runner->ofKind('managed'))->toHaveCount(1)
        ->and($runner->ofKind('vps'))->toHaveCount(1)
        ->and(array_key_exists('myapp-managed', $runner->ofKind('managed')))->toBeTrue();
});

test('findExpectedStack returns null when nothing is registered yet, and the exact match once it is', function () {
    $runner = cloudCreateRunner();

    expect($runner->expected('myapp', 'vps'))->toBeNull();

    $runner->register('myapp-vps', 'vps');

    expect($runner->expected('myapp', 'vps'))->not->toBeNull()
        ->and($runner->expected('myapp', 'vps')->name)->toBe('myapp-vps')
        // A differently-kinded stack under the same project name is a
        // different expected stack — never cross-matched.
        ->and($runner->expected('myapp', 'managed'))->toBeNull();
});

test('findExpectedStack returns null with no name base at all', function () {
    expect(cloudCreateRunner()->expected(null, 'vps'))->toBeNull();
});

test('resolveEnvironment outside a project carries the raw argument through as the standalone name instead of discarding it', function () {
    $tempDir = sys_get_temp_dir().'/larakube-cloudcreate-'.uniqid();
    mkdir($tempDir, 0755, true);
    $originalDir = getcwd();
    chdir($tempDir);

    try {
        [$config, $projectPath, $environment, $standaloneName] = cloudCreateRunner()->envResolution(rawArgument: 'mycompany-infra');

        expect($config)->toBeNull()
            ->and($projectPath)->toBeNull()
            ->and($environment)->toBeNull()
            ->and($standaloneName)->toBe('mycompany-infra');
    } finally {
        chdir($originalDir);
        exec('rm -rf '.escapeshellarg($tempDir));
    }
});

test('resolveEnvironment inside a project binds the environment and returns no standalone name', function () {
    $tempDir = sys_get_temp_dir().'/larakube-cloudcreate-'.uniqid();
    mkdir($tempDir, 0755, true);
    $projectConfig = ConfigData::from(['name' => 'myapp', 'environments' => ['local' => []]]);
    $projectConfig->setPath($tempDir);
    $projectConfig->saveToFile($tempDir);

    $originalDir = getcwd();
    chdir($tempDir);

    try {
        [$config, $projectPath, $environment, $standaloneName] = cloudCreateRunner()->envResolution(rawArgument: 'production');

        expect($config)->not->toBeNull()
            ->and($config->getName())->toBe('myapp')
            ->and($projectPath)->toBe($tempDir)
            ->and($environment)->toBe('production')
            ->and($standaloneName)->toBeNull();
    } finally {
        chdir($originalDir);
        exec('rm -rf '.escapeshellarg($tempDir));
    }
});
