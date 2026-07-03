<?php

/**
 * EnsuresRealHosts::ensureHosts() is the single "no local/placeholder hosts"
 * guard shared by cloud:configure (base/gha/gitlab) and cloud:deploy — before
 * this de-dup, cloud:deploy's own copy of this guard was missing the
 * isLocalDomain() check entirely (it only caught an empty host or the literal
 * "{name}.com" placeholder), so a leftover "*.kube"/"*.dev.test" host could
 * ship straight to a remote deploy. Laravel Prompts' non-interactive mode
 * just echoes back whatever `default:` a prompt was given, so these tests
 * can't observe "the value got corrected" — instead they observe whether the
 * re-prompt (the " ARCHITECTURAL ALIGNMENT" guard) fires at all, which is
 * exactly the condition that was buggy.
 */

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\LaravelFeature;
use App\Traits\EnsuresRealHosts;
use Laravel\Prompts\Exceptions\NonInteractiveValidationException;
use Laravel\Prompts\Prompt;

function ensuresRealHostsRunner(): object
{
    return new class
    {
        use EnsuresRealHosts;

        public array $lines = [];

        public function newLine($count = 1) {}

        public function info($text = null)
        {
            $this->lines[] = (string) $text;
        }

        public function line($text = null)
        {
            $this->lines[] = (string) $text;
        }

        public function run(ConfigData $config, string $environment): string
        {
            return $this->ensureHosts($config, $environment);
        }

        public function checkLocalDomain(string $host): bool
        {
            return $this->isLocalDomain($host);
        }
    };
}

beforeEach(function () {
    Prompt::interactive(false);
});

test('a real, already-configured web host does not trigger the re-prompt', function () {
    $config = ConfigData::from(['name' => 'acme', 'database' => 'sqlite']);
    $config->setHost('production', 'web', 'acme.example.com');

    $runner = ensuresRealHostsRunner();
    $host = $runner->run($config, 'production');

    expect($host)->toBe('acme.example.com')
        ->and($runner->lines)->toBeEmpty();
});

test('a missing web host triggers the re-prompt', function () {
    $config = ConfigData::from(['name' => 'acme', 'database' => 'sqlite']);

    $runner = ensuresRealHostsRunner();

    // The web prompt is required with no valid default when the host is
    // missing, so non-interactive mode can't fabricate an answer — it throws
    // instead of silently passing. That's fine here: the guard's alignment
    // message (proof the local-domain condition fired) is printed first.
    try {
        $runner->run($config, 'production');
    } catch (NonInteractiveValidationException) {
        // expected in this non-interactive test context
    }

    expect($runner->lines)->not->toBeEmpty();
});

test('the "{name}.com" placeholder is treated as unset and triggers the re-prompt', function () {
    $config = ConfigData::from(['name' => 'acme', 'database' => 'sqlite']);
    $config->setHost('production', 'web', 'acme.com');

    $runner = ensuresRealHostsRunner();
    $runner->run($config, 'production');

    expect($runner->lines)->not->toBeEmpty();
});

test('a local-TLD host triggers the re-prompt — the exact gap cloud:deploy used to have', function () {
    $config = ConfigData::from(['name' => 'acme', 'database' => 'sqlite']);
    $config->setHost('production', 'web', 'acme.kube');

    $runner = ensuresRealHostsRunner();
    $runner->run($config, 'production');

    expect($runner->lines)->not->toBeEmpty();
});

test('a .dev.test host is also caught as local and triggers the re-prompt', function () {
    $config = ConfigData::from(['name' => 'acme', 'database' => 'sqlite']);
    $config->setHost('production', 'web', 'acme.dev.test');

    $runner = ensuresRealHostsRunner();
    $runner->run($config, 'production');

    expect($runner->lines)->not->toBeEmpty();
});

test('a real promptable host (e.g. Reverb) is left completely untouched — the loop never re-prompts it', function () {
    $config = ConfigData::from(['name' => 'acme', 'database' => 'sqlite']);
    $config->addFeature(LaravelFeature::REVERB);
    $config->setHost('production', 'web', 'acme.example.com');
    $config->setHost('production', 'reverb', 'ws.acme.example.com');

    ensuresRealHostsRunner()->run($config, 'production');

    expect($config->getHost('production', 'reverb'))->toBe('ws.acme.example.com');
});

test('isLocalDomain recognizes every allowed local TLD and .dev.test, and rejects a real domain', function () {
    $runner = ensuresRealHostsRunner();

    foreach (GlobalConfigData::ALLOWED_TLDS as $tld) {
        expect($runner->checkLocalDomain("acme.{$tld}"))->toBeTrue();
    }

    expect($runner->checkLocalDomain('acme.dev.test'))->toBeTrue()
        ->and($runner->checkLocalDomain('acme.com'))->toBeFalse()
        ->and($runner->checkLocalDomain('staging.acme.com'))->toBeFalse();
});
