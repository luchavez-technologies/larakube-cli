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
use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\Exceptions\NonInteractiveValidationException;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\TextPrompt;

/**
 * Script the answers to individual prompt types. Prompt::shouldFallback() is
 * checked BEFORE the non-interactive branch, so a registered fallback wins over
 * Prompt::interactive(false) — which is what lets these tests drive the
 * "user answered no" path that non-interactive mode can't reach (it only ever
 * echoes a prompt's own default).
 *
 * @param  callable(ConfirmPrompt): bool  $onConfirm
 * @param  callable(TextPrompt): string  $onText
 */
function scriptPromptAnswers(callable $onConfirm, callable $onText): void
{
    ConfirmPrompt::fallbackUsing($onConfirm);
    TextPrompt::fallbackUsing($onText);
    Prompt::fallbackWhen(true);
}

/** Fallbacks live in static properties, so they leak across tests unless reset. */
function resetPromptFallbacks(): void
{
    foreach (['shouldFallback' => false, 'fallbacks' => []] as $property => $value) {
        $ref = new ReflectionProperty(Prompt::class, $property);
        $ref->setAccessible(true);
        $ref->setValue(null, $value);
    }
}

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

beforeEach(function (): void {
    resetPromptFallbacks();
    Prompt::interactive(false);
});

afterEach(function (): void {
    resetPromptFallbacks();
});

test('a real, already-configured web host does not trigger the re-prompt', function (): void {
    $config = ConfigData::from(['name' => 'acme', 'database' => 'sqlite']);
    $config->setHost('production', 'web', 'acme.example.com');

    $runner = ensuresRealHostsRunner();
    $host = $runner->run($config, 'production');

    expect($host)->toBe('acme.example.com')
        ->and($runner->lines)->toBeEmpty();
});

test('a missing web host triggers the re-prompt', function (): void {
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

test('the "{name}.com" placeholder is treated as unset and triggers the re-prompt', function (): void {
    $config = ConfigData::from(['name' => 'acme', 'database' => 'sqlite']);
    $config->setHost('production', 'web', 'acme.com');

    $runner = ensuresRealHostsRunner();
    $runner->run($config, 'production');

    expect($runner->lines)->not->toBeEmpty();
});

test('a local-TLD host triggers the re-prompt — the exact gap cloud:deploy used to have', function (): void {
    $config = ConfigData::from(['name' => 'acme', 'database' => 'sqlite']);
    $config->setHost('production', 'web', 'acme.kube');

    $runner = ensuresRealHostsRunner();
    $runner->run($config, 'production');

    expect($runner->lines)->not->toBeEmpty();
});

test('a .dev.test host is also caught as local and triggers the re-prompt', function (): void {
    $config = ConfigData::from(['name' => 'acme', 'database' => 'sqlite']);
    $config->setHost('production', 'web', 'acme.dev.test');

    $runner = ensuresRealHostsRunner();
    $runner->run($config, 'production');

    expect($runner->lines)->not->toBeEmpty();
});

test('a real promptable host (e.g. Reverb) is confirmed rather than silently skipped', function (): void {
    // It used to `continue` past anything that merely looked real, so a stale
    // host sailed through untouched and unmentioned. Now it asks — and the
    // question must actually reach the user, hence the invocation count.
    $config = ConfigData::from(['name' => 'acme', 'database' => 'sqlite']);
    $config->addFeature(LaravelFeature::REVERB);
    $config->setHost('production', 'web', 'acme.example.com');
    $config->setHost('production', 'reverb', 'ws.acme.example.com');

    $confirmed = [];
    scriptPromptAnswers(
        function (ConfirmPrompt $prompt) use (&$confirmed) {
            $confirmed[] = $prompt->label;

            return true;   // keep everything as configured
        },
        fn (TextPrompt $prompt) => 'should-not-be-reached',
    );

    ensuresRealHostsRunner()->run($config, 'production');

    // Both the web host and Reverb's were put to the user, and keeping them
    // leaves the configured values exactly as they were.
    expect($confirmed)->toHaveCount(2)
        ->and(implode(' ', $confirmed))->toContain('ws.acme.example.com')
        ->and($config->getHost('production', 'reverb'))->toBe('ws.acme.example.com')
        ->and($config->getHost('production', 'web'))->toBe('acme.example.com');
});

test('declining the confirmation re-prompts and replaces the promptable host', function (): void {
    $config = ConfigData::from(['name' => 'acme', 'database' => 'sqlite']);
    $config->addFeature(LaravelFeature::REVERB);
    $config->setHost('production', 'web', 'acme.example.com');
    $config->setHost('production', 'reverb', 'stale.acme.example.com');

    scriptPromptAnswers(
        // Keep the web host, reject the Reverb one.
        fn (ConfirmPrompt $prompt) => ! str_contains($prompt->label, 'stale.acme.example.com'),
        fn (TextPrompt $prompt) => 'ws.acme.example.com',
    );

    ensuresRealHostsRunner()->run($config, 'production');

    expect($config->getHost('production', 'reverb'))->toBe('ws.acme.example.com')
        ->and($config->getHost('production', 'web'))->toBe('acme.example.com');
});

test('hosts are only confirmed once per command run', function (): void {
    // cloud:configure calls this guard twice in one invocation — once in its
    // base step, once in the CI step — which asked every "keep this host?"
    // question twice. The base step saves before the CI step re-reads, so the
    // second pass has nothing to learn.
    $config = ConfigData::from(['name' => 'acme', 'database' => 'sqlite']);
    $config->addFeature(LaravelFeature::REVERB);
    $config->setHost('production', 'web', 'acme.example.com');
    $config->setHost('production', 'reverb', 'ws.acme.example.com');

    $asked = 0;
    scriptPromptAnswers(
        function (ConfirmPrompt $prompt) use (&$asked) {
            $asked++;

            return true;
        },
        fn (TextPrompt $prompt) => 'unused',
    );

    $runner = ensuresRealHostsRunner();
    $first = $runner->run($config, 'production');
    $second = $runner->run($config, 'production');

    // Two hosts → two questions on the first pass, none on the second.
    expect($asked)->toBe(2)
        ->and($second)->toBe($first)
        ->and($second)->toBe('acme.example.com');
});

test('each environment is still confirmed on its own', function (): void {
    $config = ConfigData::from(['name' => 'acme', 'database' => 'sqlite']);
    $config->setHost('production', 'web', 'acme.example.com');
    $config->setHost('staging', 'web', 'stg.acme.example.com');

    $asked = [];
    scriptPromptAnswers(
        function (ConfirmPrompt $prompt) use (&$asked) {
            $asked[] = $prompt->label;

            return true;
        },
        fn (TextPrompt $prompt) => 'unused',
    );

    $runner = ensuresRealHostsRunner();
    $runner->run($config, 'production');
    $runner->run($config, 'staging');

    expect($asked)->toHaveCount(2)
        ->and(implode(' ', $asked))->toContain('acme.example.com')
        ->and(implode(' ', $asked))->toContain('stg.acme.example.com');
});

test('declining the web-host confirmation re-prompts for a new one', function (): void {
    $config = ConfigData::from(['name' => 'acme', 'database' => 'sqlite']);
    $config->setHost('production', 'web', 'old.acme.example.com');

    scriptPromptAnswers(
        fn (ConfirmPrompt $prompt) => false,
        fn (TextPrompt $prompt) => 'new.acme.example.com',
    );

    $host = ensuresRealHostsRunner()->run($config, 'production');

    expect($host)->toBe('new.acme.example.com')
        ->and($config->getHost('production', 'web'))->toBe('new.acme.example.com');
});

test('isLocalDomain recognizes every allowed local TLD and .dev.test, and rejects a real domain', function (): void {
    $runner = ensuresRealHostsRunner();

    foreach (GlobalConfigData::ALLOWED_TLDS as $tld) {
        expect($runner->checkLocalDomain("acme.{$tld}"))->toBeTrue();
    }

    expect($runner->checkLocalDomain('acme.dev.test'))->toBeTrue()
        ->and($runner->checkLocalDomain('acme.com'))->toBeFalse()
        ->and($runner->checkLocalDomain('staging.acme.com'))->toBeFalse();
});
