<?php

/**
 * Drives the `init` wizard (GathersInfrastructureConfig::gatherConfig) so the
 * interactive code path ACTUALLY executes — the path none of the generation
 * tests touch, and where the v0.9.0/.1/.2 crashes lived (e.g.
 * `IngressController::getSelectOptions($config)` at the ingress step).
 *
 * In a test there's no TTY, so each Laravel Prompt takes its non-interactive
 * path: it builds its options (this is where the missing-import fatals occur)
 * and returns its default. We pre-fill the config so every prompt has a valid
 * default and the guarded steps are skipped — the wizard then runs end to end.
 * If any prompt step references an unimported class/function again, this fatals.
 */

use App\Data\ConfigData;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\DeploymentStrategy;
use App\Enums\FrontendStack;
use App\Enums\OperatingSystem;
use App\Enums\PhpVersion;
use App\Enums\ServerVariation;
use App\Enums\StorageDriver;
use App\Traits\GathersInfrastructureConfig;
use Laravel\Prompts\Prompt;

test('the init wizard runs every prompt step without a missing-symbol crash', function () {
    // 🔍 FIX: Force Laravel Prompts to use its non-interactive fallback mode
    Prompt::interactive(false);

    $config = new ConfigData(name: 'wizardtest');
    $config->setServerVariation(ServerVariation::FPM_NGINX)
        ->setPhpVersion(PhpVersion::PHP_8_5)
        ->setOs(OperatingSystem::ALPINE)
        ->setFrontend(FrontendStack::REACT)
        ->setDatabase(DatabaseDriver::SQLITE)
        ->setCacheDriver(CacheDriver::DATABASE)
        ->setObjectStorage(StorageDriver::MINIO) // its "None" option is null → give a valid default
        ->setStrategy(DeploymentStrategy::SINGLE_NODE)
        ->setAdditionalExtensions(['gd']);
    $config->setEmail('wizard@example.test');

    // A throwaway object that mixes in the wizard trait and stubs its output.
    $runner = new class
    {
        use GathersInfrastructureConfig;

        public function run(ConfigData $config): ConfigData
        {
            return $this->gatherConfig($config);
        }

        public function laraKubeInfo($text = null) {}
    };

    $result = $runner->run($config);

    // gatherConfig no longer touches a "production" env at all — cloud
    // environments are created on demand via `larakube env`/`cloud:configure`,
    // never scaffolded here. The regression guard is simply that every
    // prompt step (the one that fataled in v0.9.0) — including the final
    // GitHub Actions prompt — ran to completion without a missing-symbol fatal.
    expect($result)->toBeInstanceOf(ConfigData::class)
        ->and($result->hasGithubActions())->toBeTrue();
});
