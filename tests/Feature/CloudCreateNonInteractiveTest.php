<?php

/**
 * cloud:create headless behavior (LaraKube Cloud job containers drive this
 * command with flags + --no-interaction): kind-defining inputs must fail
 * clearly instead of hanging or silently defaulting, flags must bypass their
 * prompts, --json must emit one parseable result, and a --do-token must never
 * be persisted to the global config.
 *
 * Full-run tests go through artisan(); flag-parsing tests use a direct
 * harness (CloudCreateNamingTest-style) with a bound ArrayInput so
 * $this->option() works without a console run.
 */

use App\Commands\Cloud\CloudCreateCommand;
use App\Data\GlobalConfigData;
use App\State;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Input\ArrayInput;

beforeEach(function () {
    Prompt::interactive(false);
});

function cloudCreateFlagRunner(array $options = []): CloudCreateCommand
{
    $command = new class extends CloudCreateCommand
    {
        public GlobalConfigData $fakeGlobalConfig;

        public function __construct()
        {
            parent::__construct();
            $this->fakeGlobalConfig = new GlobalConfigData;
        }

        public function line($string, $style = null, $verbosity = null) {}

        public function newLine($count = 1) {}

        public function bindOptions(array $options): void
        {
            $this->input = new ArrayInput($options, $this->getDefinition());
        }

        // --- public wrappers around the protected methods under test ---

        public function adminCidr(): string|false|null
        {
            return $this->promptAdminCidr();
        }

        public function stackName(?string $nameBase, string $kind): string
        {
            return $this->promptStackName($nameBase, $kind);
        }

        public function doToken(): bool
        {
            return $this->ensureDoToken();
        }

        protected function getGlobalConfig(): GlobalConfigData
        {
            return $this->fakeGlobalConfig;
        }
    };

    $command->bindOptions($options);

    return $command;
}

test('bare --no-interaction fails fast with a clear provider error instead of hanging', function () {
    $this->artisan('cloud:create', ['--no-interaction' => true])->assertExitCode(1);

    expect(State::$lastError)->toContain('--provider=');
});

test('--provider without a kind fails clearly under --no-interaction', function () {
    $this->artisan('cloud:create', ['--provider' => 'do', '--no-interaction' => true])->assertExitCode(1);

    expect(State::$lastError)->toContain('--vps or --managed');
});

test('--vps and --managed together are rejected', function () {
    $this->artisan('cloud:create', ['--provider' => 'do', '--vps' => true, '--managed' => true, '--no-interaction' => true])
        ->assertExitCode(1);

    expect(State::$lastError)->toContain('not both');
});

test('an unknown --provider is rejected', function () {
    $this->artisan('cloud:create', ['--provider' => 'bogus', '--no-interaction' => true])->assertExitCode(1);

    expect(State::$lastError)->toContain("Unknown provider: 'bogus'");
});

test('--json emits one parseable failure object on failure', function () {
    $this->artisan('cloud:create', ['--provider' => 'bogus', '--json' => true, '--no-interaction' => true])
        ->expectsOutputToContain('"success":false')
        ->assertExitCode(1);
});

test('a missing DO token fails clearly under --no-interaction instead of prompting', function () {
    $this->artisan('cloud:create', ['--provider' => 'do', '--vps' => true, '--no-interaction' => true])
        ->assertExitCode(1);

    expect(State::$lastError)->toContain('--do-token=');
});

test('--do-token becomes a run-only transient token and never touches the global config', function () {
    $runner = cloudCreateFlagRunner(['--do-token' => 'dop_v1_headless-job-token']);

    expect($runner->doToken())->toBeTrue()
        ->and(State::$transientDoToken)->toBe('dop_v1_headless-job-token')
        ->and($runner->fakeGlobalConfig->getDoToken())->toBeNull();
});

test('--stack-name skips the prompt and is slugified', function () {
    expect(cloudCreateFlagRunner(['--stack-name' => 'My App Stack!'])->stackName('ignored', 'vps'))
        ->toBe('my-app-stack');
});

test('--admin-cidr accepts a bare IPv4 (auto /32), a CIDR, and rejects garbage', function () {
    expect(cloudCreateFlagRunner(['--admin-cidr' => '203.0.113.7'])->adminCidr())->toBe('203.0.113.7/32')
        ->and(cloudCreateFlagRunner(['--admin-cidr' => '203.0.113.0/24'])->adminCidr())->toBe('203.0.113.0/24')
        ->and(cloudCreateFlagRunner(['--admin-cidr' => 'not-an-ip'])->adminCidr())->toBeFalse();
});

test('no --admin-cidr headlessly means open (matches the confirm default of false)', function () {
    // The command's own definition has no no-interaction option (it's an
    // application-level flag) — flag() must tolerate that, and the confirm
    // falls back to its `false` default under Prompt::interactive(false).
    expect(cloudCreateFlagRunner()->adminCidr())->toBeNull();
});
