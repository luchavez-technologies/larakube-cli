<?php

/**
 * Regression coverage for two `init` re-run bugs:
 *
 *   1. buildConfigFromFlags() always built a blank ConfigData, so gatherConfig()'s
 *      wizard prompts defaulted to hardcoded fallbacks instead of the project's
 *      actual current choices on a re-init.
 *   2. InitCommand unconditionally did setEnvironments(['local']), wiping any
 *      previously-configured cloud environment (e.g. production) from
 *      .larakube.json the moment orchestrateProjectScaffolding() saved.
 *
 * Also covers diffConfigs(), the pure diff used to replay a wizard pass as
 * add/remove/update side effects instead of a blind config overwrite.
 */

use App\Data\ConfigData;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\DeploymentStrategy;
use App\Enums\FrontendStack;
use App\Enums\LaravelFeature;
use App\Enums\OperatingSystem;
use App\Enums\PhpVersion;
use App\Enums\ServerVariation;
use App\Enums\StorageDriver;
use App\Traits\DiffsProjectConfig;
use App\Traits\GathersInfrastructureConfig;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

afterEach(function () {
    // Prompt::fake() forces interactive mode and installs a mocked terminal;
    // reset it so a later test/file in the same process doesn't inherit a
    // fake, already-exhausted terminal and block on a real prompt.
    Prompt::interactive(false);
});

function diffRunner(): object
{
    return new class
    {
        use DiffsProjectConfig;

        public function diff(ConfigData $old, ConfigData $new): array
        {
            return $this->diffConfigs($old, $new);
        }
    };
}

test('diffConfigs detects a database swap', function () {
    $old = new ConfigData(name: 'diff-test');
    $old->setDatabase(DatabaseDriver::MYSQL);
    $new = clone $old;
    $new->setDatabase(DatabaseDriver::POSTGRESQL);

    $diff = diffRunner()->diff($old, $new);

    expect($diff['database'])->toBe([
        'old' => DatabaseDriver::MYSQL,
        'new' => DatabaseDriver::POSTGRESQL,
        'changed' => true,
    ]);
});

test('diffConfigs reports no database change when untouched', function () {
    $old = new ConfigData(name: 'diff-test');
    $old->setDatabase(DatabaseDriver::SQLITE);
    $new = clone $old;

    expect(diffRunner()->diff($old, $new)['database']['changed'])->toBeFalse();
});

test('diffConfigs compares the raw cache property, not the DATABASE-defaulting getter', function () {
    // getCacheDriver() substitutes CacheDriver::DATABASE for null. If the diff
    // compared that getter instead of the raw property, a never-configured
    // cache driver would look identical to an explicit "database" choice.
    $old = new ConfigData(name: 'diff-test');
    $new = clone $old;
    $new->setCacheDriver(CacheDriver::DATABASE);

    $diff = diffRunner()->diff($old, $new);

    expect($diff['cache'])->toBe([
        'old' => null,
        'new' => CacheDriver::DATABASE,
        'changed' => true,
    ]);
});

test('diffConfigs treats picking "None" as clearing an existing cache driver', function () {
    $old = new ConfigData(name: 'diff-test');
    $old->setCacheDriver(CacheDriver::REDIS);
    $new = clone $old;
    $new->setCacheDriver(null);

    $diff = diffRunner()->diff($old, $new);

    expect($diff['cache'])->toBe([
        'old' => CacheDriver::REDIS,
        'new' => null,
        'changed' => true,
    ]);
});

test('diffConfigs computes add/remove lists for features and blueprints', function () {
    $old = new ConfigData(name: 'diff-test');
    $old->addFeature(LaravelFeature::HORIZON);
    $new = clone $old;
    $new->setFeatures([LaravelFeature::REVERB]);

    $diff = diffRunner()->diff($old, $new);

    expect($diff['features']['add'])->toBe([LaravelFeature::REVERB])
        ->and($diff['features']['remove'])->toBe([LaravelFeature::HORIZON]);
});

test('addEnvironment("local") preserves an existing cloud environment, unlike setEnvironments', function () {
    // This is the exact fix for bug #2: InitCommand::handle() must call
    // addEnvironment('local') (idempotent) on a re-init, never
    // setEnvironments(['local']) (a destructive full replace), or a
    // previously-configured `production` environment silently disappears
    // from .larakube.json the next time the config is saved.
    $config = ConfigData::from([
        'name' => 'reinit-test',
        'database' => 'sqlite',
        'environments' => ['local' => [], 'production' => []],
    ]);

    expect($config->getEnvironments())->toContain('production');

    $config->addEnvironment('local');

    expect($config->getEnvironments())->toContain('production')
        ->and($config->getEnvironments())->toContain('local');
});

test('forcePrompts re-opens PHP version, OS, strategy, and GitHub Actions prompts with the current value as default', function () {
    // Regression guard for a gap found while wiring forcePrompts: these four
    // prompts previously defaulted to hardcoded fallbacks (PHP_8_5, ALPINE,
    // SINGLE_NODE, true) instead of the project's actual current value, which
    // would have silently reset them the moment a re-init forced the prompt
    // back open. Laravel Prompts' non-interactive fallback always returns
    // `default:`, so this proves the default itself is correct.
    Prompt::interactive(false);

    $config = new ConfigData(name: 'reinit-test');
    $config->setServerVariation(ServerVariation::FPM_NGINX)
        ->setPhpVersion(PhpVersion::PHP_8_3)
        ->setOs(OperatingSystem::DEBIAN)
        ->setFrontend(FrontendStack::REACT)
        ->setDatabase(DatabaseDriver::SQLITE)
        ->setCacheDriver(CacheDriver::DATABASE)
        ->setObjectStorage(StorageDriver::MINIO) // its "None" option is null → give a valid default
        ->setStrategy(DeploymentStrategy::MULTI_NODE_HA)
        ->setGithubActions(false)
        ->setAdditionalExtensions(['gd']);
    $config->setEmail('reinit@example.test');

    $runner = new class
    {
        use GathersInfrastructureConfig;

        public function run(ConfigData $config): ConfigData
        {
            return $this->gatherConfig($config, forcePrompts: true);
        }

        public function laraKubeInfo($text = null) {}
    };

    $result = $runner->run($config);

    expect($result->getPhpVersion())->toBe(PhpVersion::PHP_8_3)
        ->and($result->getOs())->toBe(OperatingSystem::DEBIAN)
        ->and($result->getStrategy())->toBe(DeploymentStrategy::MULTI_NODE_HA)
        ->and($result->getGithubActions())->toBeFalse();
});

/**
 * Builds a config pre-filled enough that every gatherConfig() prompt EXCEPT
 * blueprints / server variation / features / package manager / storage /
 * database / cache is guarded off (already set, forcePrompts not used here).
 * Those seven always prompt regardless of prior state, in that exact order —
 * see GathersInfrastructureConfig::gatherConfig(). Callers only need to fake
 * keys for those seven steps, in order.
 */
function reinitWizardConfig(): ConfigData
{
    $config = new ConfigData(name: 'reinit-wizard-test');
    $config->setServerVariation(ServerVariation::FPM_NGINX)
        ->setPhpVersion(PhpVersion::PHP_8_5)
        ->setOs(OperatingSystem::ALPINE)
        ->setFrontend(FrontendStack::REACT)
        ->setDatabase(DatabaseDriver::SQLITE)
        ->setCacheDriver(CacheDriver::REDIS)
        ->setObjectStorage(StorageDriver::MINIO)
        ->setStrategy(DeploymentStrategy::SINGLE_NODE)
        ->setGithubActions(true)
        ->setAdditionalExtensions(['gd']);
    $config->setEmail('reinit-wizard@example.test');

    return $config;
}

function runReinitWizard(ConfigData $config): ConfigData
{
    $runner = new class
    {
        use GathersInfrastructureConfig;

        public function run(ConfigData $config): ConfigData
        {
            return $this->gatherConfig($config);
        }

        public function laraKubeInfo($text = null) {}
    };

    return $runner->run($config);
}

test('picking "None" for storage in the wizard clears a previously-configured driver', function () {
    // Options are ['' => None, 'seaweedfs', 'minio', 'garage'] (StorageDriver::cases()
    // order) with default 'minio' pre-highlighted at index 2 — two UPs reach "None".
    Prompt::fake([
        Key::ENTER,          // blueprints multiselect — leave unchanged
        Key::ENTER,          // server variation — leave unchanged
        Key::ENTER,          // features multiselect — leave unchanged
        Key::ENTER,          // package manager — leave unchanged
        Key::UP, Key::UP, Key::ENTER, // storage — move off "minio" onto "None" and submit
        Key::ENTER,          // database — leave unchanged
        Key::ENTER,          // cache — leave unchanged (stays redis)
    ]);

    $result = runReinitWizard(reinitWizardConfig());

    expect($result->getObjectStorage())->toBeNull()
        ->and($result->cacheDriver)->toBe(CacheDriver::REDIS);
});

test('picking "None" for cache in the wizard clears a previously-configured driver', function () {
    // Same shape as storage: options are ['' => None, 'redis', 'memcached', 'database']
    // with default 'redis' pre-highlighted at index 1 — one UP reaches "None".
    Prompt::fake([
        Key::ENTER,          // blueprints multiselect — leave unchanged
        Key::ENTER,          // server variation — leave unchanged
        Key::ENTER,          // features multiselect — leave unchanged
        Key::ENTER,          // package manager — leave unchanged
        Key::ENTER,          // storage — leave unchanged (stays minio)
        Key::ENTER,          // database — leave unchanged
        Key::UP, Key::ENTER, // cache — move off "redis" onto "None" and submit
    ]);

    $result = runReinitWizard(reinitWizardConfig());

    expect($result->cacheDriver)->toBeNull()
        ->and($result->getObjectStorage())->toBe(StorageDriver::MINIO);
});
