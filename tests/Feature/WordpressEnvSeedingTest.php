<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\PhpVersion;
use App\Enums\ServerVariation;
use App\Enums\StorageDriver;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithArchitecturalEngine;
use Laravel\Prompts\Prompt;
use Spatie\TemporaryDirectory\TemporaryDirectory;

const WORDPRESS_ENV_SEEDING_SALTS = ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'];

/** Runs the scaffolding pipeline and the salt seeding without a command around them. */
function wordpressEnvSeedingHost(): object
{
    Prompt::fallbackUsing(fn () => true);

    return new class
    {
        use GeneratesProjectInfrastructure, InteractsWithArchitecturalEngine;

        public function scaffold(ConfigData $config): void
        {
            $this->orchestrateProjectScaffolding($config, installFeatures: false, buildImage: false);
        }

        public function seed(ConfigData $config): void
        {
            $this->seedBedrockSalts($config);
        }

        /** @return array<string, string> */
        public function env(string $file): array
        {
            return $this->readDotEnv($file);
        }

        public function line($string, $style = null, $verbosity = null) {}

        public function info($string, $verbosity = null) {}

        public function warn($string, $verbosity = null) {}

        public function error($string, $verbosity = null) {}

        public function newLine($count = 1) {}

        public function withSpin($text, $callback)
        {
            return $callback();
        }

        public function laraKubeInfo($text) {}
    };
}

function wordpressEnvSeedingConfig(TemporaryDirectory $dir, AppFramework $framework, array $environments = ['local']): ConfigData
{
    $config = new ConfigData;
    $config->framework = $framework;
    $config->phpVersion = PhpVersion::PHP_8_4;
    $config->serverVariation = ServerVariation::FPM_NGINX;
    $config->setName('wp');
    $config->setPath($dir->path());
    $config->setEnvironments($environments);
    $config->setDatabase(DatabaseDriver::MYSQL);
    $config->setCacheDriver(CacheDriver::REDIS);
    $config->setObjectStorage(StorageDriver::MINIO);

    return $config;
}

/** @return array<string, string> */
function wordpressEnvSeedingSalts(array $env): array
{
    return array_intersect_key($env, array_flip(WORDPRESS_ENV_SEEDING_SALTS));
}

test('a fresh Bedrock project gets a .env with its URLs and eight generated salts', function (): void {
    $dir = TemporaryDirectory::make();
    $host = wordpressEnvSeedingHost();
    $config = wordpressEnvSeedingConfig($dir, AppFramework::WORDPRESS);

    $host->scaffold($config);
    $env = $host->env($dir->path().'/.env');
    $salts = wordpressEnvSeedingSalts($env);

    expect($env['WP_HOME'])->toBe($config->getAppUrl('local'))
        ->and($env['WP_SITEURL'])->toBe($config->getAppUrl('local').'/wp')
        ->and($env['WP_ENV'])->toBe('development')
        ->and(array_keys($salts))->toEqualCanonicalizing(WORDPRESS_ENV_SEEDING_SALTS)
        ->and(array_unique($salts))->toHaveCount(8)
        ->and($salts)->each->toMatch('/^[0-9a-f]{64}$/');

    $dir->delete();
});

test('salts already set are kept, and only placeholders or blanks are generated', function (): void {
    $dir = TemporaryDirectory::make();
    $host = wordpressEnvSeedingHost();
    file_put_contents($dir->path().'/.env', "AUTH_KEY='keep-me'\nNONCE_SALT='generateme'\nLOGGED_IN_KEY=\n");

    $host->seed(wordpressEnvSeedingConfig($dir, AppFramework::WORDPRESS));
    $first = $host->env($dir->path().'/.env');

    $host->seed(wordpressEnvSeedingConfig($dir, AppFramework::WORDPRESS));
    $second = $host->env($dir->path().'/.env');

    expect($first['AUTH_KEY'])->toBe('keep-me')
        ->and($first['NONCE_SALT'])->not->toBe('generateme')
        ->and($first['LOGGED_IN_KEY'])->not->toBe('')
        ->and(wordpressEnvSeedingSalts($second))->toBe(wordpressEnvSeedingSalts($first));

    $dir->delete();
});

test('a cloud env file stops sharing salts with .env, and keeps its own afterwards', function (): void {
    $dir = TemporaryDirectory::make();
    $host = wordpressEnvSeedingHost();
    $config = wordpressEnvSeedingConfig($dir, AppFramework::WORDPRESS, ['local', 'production']);
    file_put_contents($dir->path().'/.env', '');

    $host->seed($config);
    copy($dir->path().'/.env', $dir->path().'/.env.production');
    $host->seed($config);

    $local = wordpressEnvSeedingSalts($host->env($dir->path().'/.env'));
    $production = wordpressEnvSeedingSalts($host->env($dir->path().'/.env.production'));

    $host->seed($config);

    expect($production)->toHaveCount(8)
        ->and(array_intersect_assoc($production, $local))->toBeEmpty()
        ->and(wordpressEnvSeedingSalts($host->env($dir->path().'/.env')))->toBe($local)
        ->and(wordpressEnvSeedingSalts($host->env($dir->path().'/.env.production')))->toBe($production);

    $dir->delete();
});

test('a Laravel project gets no .env created and no salts', function (): void {
    $dir = TemporaryDirectory::make();

    wordpressEnvSeedingHost()->scaffold(wordpressEnvSeedingConfig($dir, AppFramework::LARAVEL));

    expect(file_exists($dir->path().'/.env'))->toBeFalse();

    $dir->delete();
});
