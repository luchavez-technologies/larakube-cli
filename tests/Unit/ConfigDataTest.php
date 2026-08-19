<?php

namespace Tests\Unit;

use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Data\GlobalConfigData;
use App\Enums\Blueprint;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\DeploymentStrategy;
use App\Enums\FrontendStack;
use App\Enums\IngressController;
use App\Enums\LaravelFeature;
use App\Enums\OperatingSystem;
use App\Enums\PhpVersion;
use App\Enums\SearchDriver;
use App\Enums\ServerVariation;
use App\Enums\SharedClusterService;
use App\Enums\StorageDriver;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Tests\TestCase;

class ConfigDataTest extends TestCase
{
    public function test_it_casts_strings_to_enums_correctly()
    {
        $data = [
            'blueprints' => ['filament'],
            'serverVariation' => 'fpm-nginx',
            'frontend' => 'react',
            'phpVersion' => '8.5',
            'os' => 'alpine',
            'strategy' => 'single-node',
        ];

        $config = ConfigData::from($data);

        // Single Enums
        expect($config->serverVariation)->toEqual(ServerVariation::FPM_NGINX);
        expect($config->frontend)->toEqual(FrontendStack::REACT)
            ->and($config->phpVersion)->toEqual(PhpVersion::PHP_8_5)
            ->and($config->os)->toEqual(OperatingSystem::ALPINE)
            ->and($config->strategy)->toEqual(DeploymentStrategy::SINGLE_NODE);

        // Array of Enums
        expect($config->blueprints)->toBeArray();
        expect($config->blueprints[0])->toEqual(Blueprint::FILAMENT);
    }

    public function test_it_handles_multiple_enum_values_in_arrays()
    {
        $data = [
            'databases' => ['mysql', 'postgres'],
            'cacheDrivers' => ['redis'],
            'features' => ['horizon', 'reverb'],
        ];

        $config = ConfigData::from($data);

        expect($config->databases)->toHaveCount(2)
            ->toMatchArray([0 => DatabaseDriver::MYSQL, 1 => DatabaseDriver::POSTGRESQL])
            ->and($config->cacheDrivers)->toHaveCount(1)
            ->and($config->cacheDrivers[0])->toEqual(CacheDriver::REDIS)
            ->and($config->features)->toHaveCount(2)
            ->toMatchArray([0 => LaravelFeature::HORIZON, 1 => LaravelFeature::REVERB]);
    }

    public function test_it_maintains_default_values()
    {
        $config = ConfigData::from([]);

        expect($config->strategy)->toEqual(DeploymentStrategy::SINGLE_NODE)
            ->and($config->getEnvironments())->toBe(['local', 'production'])
            ->and($config->getEnvironment('local'))->toBeInstanceOf(EnvironmentData::class)
            ->and($config->getEnvironment('production'))->toBeInstanceOf(EnvironmentData::class)
            ->and($config->getIngress('local'))->toEqual(IngressController::TRAEFIK)
            ->and($config->getIngress('production'))->toEqual(IngressController::TRAEFIK)
            ->and($config->githubActions)->toBeTrue()
            ->and($config->isSystem)->toBeFalse()
            ->and($config->isScaffolding)->toBeFalse();
    }

    public function test_environments_are_promoted_from_json_array_shape()
    {
        $config = ConfigData::from([
            'environments' => [
                'local' => ['managed' => [], 'hosts' => []],
                'production' => [
                    'managed' => ['postgres', 'redis'],
                    'hosts' => ['web' => 'example.com'],
                ],
            ],
        ]);

        expect($config->getEnvironment('production'))->toBeInstanceOf(EnvironmentData::class)
            ->and($config->getManaged('production'))->toBe(['postgres', 'redis'])
            ->and($config->getManaged('local'))->toBeEmpty()
            ->and($config->getEnvironment('production')->hosts['web'])->toBe('example.com')
            ->and($config->getAppUrl('production'))->toBe('https://example.com');
    }

    public function test_cloud_envs_deploy_production_safe_app_env_and_debug()
    {
        $config = ConfigData::from([
            'name' => 'demo',
            'environments' => [
                'local' => ['hosts' => []],
                'production' => ['hosts' => ['web' => 'example.com']],
                'staging' => ['hosts' => ['web' => 'staging.example.com']],
            ],
        ]);

        // Cloud envs report APP_ENV=production (hardcoded — Laravel keys its
        // production safeguards on exactly "production") + debug OFF.
        $prod = $config->getAllPublicEnvironmentVariables('production');
        expect($prod)->toMatchArray(['APP_ENV' => 'production', 'APP_DEBUG' => 'false']);

        // Even a non-production cloud env (staging) reports "production".
        $staging = $config->getAllPublicEnvironmentVariables('staging');
        expect($staging)->toMatchArray(['APP_ENV' => 'production', 'APP_DEBUG' => 'false']);

        // Local is left alone (keeps Laravel's own APP_ENV=local / APP_DEBUG=true).
        $local = $config->getAllPublicEnvironmentVariables('local');
        expect($local)->not->toHaveKey('APP_DEBUG');
    }

    public function test_features_filter_by_env_with_enum_defaults()
    {
        // BOOST + MCP default to local only; HORIZON to all envs; SSR to prod only.
        $config = ConfigData::from([
            'features' => ['boost', 'mcp', 'horizon', 'ssr'],
        ]);

        $local = $config->getFeatures('local');
        expect($local)->toContain(LaravelFeature::BOOST)
            ->toContain(LaravelFeature::MCP)
            ->toContain(LaravelFeature::HORIZON)->not->toContain(LaravelFeature::SSR);

        $prod = $config->getFeatures('production');
        expect($prod)->toContain(LaravelFeature::HORIZON)
            ->toContain(LaravelFeature::SSR)->not->toContain(LaravelFeature::BOOST)->not->toContain(LaravelFeature::MCP);
    }

    public function test_environment_overrides_can_add_or_exclude_features()
    {
        $config = ConfigData::from([
            'features' => ['horizon', 'boost'],
            'environments' => [
                'local' => [
                    'excludeFeatures' => ['horizon'],
                ],
                'production' => [
                    'addFeatures' => ['boost'],
                ],
            ],
        ]);

        expect($config->getFeatures('local'))->not->toContain(LaravelFeature::HORIZON)
            ->and($config->getFeatures('local'))->toContain(LaravelFeature::BOOST)
            ->and($config->getFeatures('production'))->toContain(LaravelFeature::HORIZON)
            ->and($config->getFeatures('production'))->toContain(LaravelFeature::BOOST);
    }

    public function test_save_to_file_omits_transient_fields()
    {
        $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
        $tmp = $temporaryDirectory->path();

        try {
            $config = ConfigData::from(['name' => 'demo', 'isScaffolding' => true]);
            $config->setPath($tmp);
            $config->saveToFile($tmp);

            $json = json_decode(file_get_contents("{$tmp}/.larakube.json"), true);

            // Transient/machine-specific fields are not persisted...
            expect($json)->not->toHaveKey('isScaffolding');
            expect($json)->not->toHaveKey('path');
            // ...but real fields are.
            expect($json['name'])->toBe('demo');

            // And it still round-trips with sane defaults.
            $reloaded = ConfigData::from($json);
            expect($reloaded->isScaffolding())->toBeFalse();
        } finally {
            $temporaryDirectory->delete();
        }
    }

    public function test_add_environment_creates_a_new_env_idempotently()
    {
        $config = ConfigData::from([]);

        expect($config->hasEnvironment('staging'))->toBeFalse();

        $config->addEnvironment('staging');
        expect($config->hasEnvironment('staging'))->toBeTrue()
            ->and($config->getEnvironment('staging'))->toBeInstanceOf(EnvironmentData::class);

        $config->getEnvironment('staging')->managed = ['postgres'];
        $config->addEnvironment('staging');
        expect($config->getManaged('staging'))->toBe(['postgres']);
    }

    public function test_remove_environment_drops_it_from_the_map()
    {
        $config = ConfigData::from([]);
        $config->addEnvironment('staging');
        expect($config->hasEnvironment('staging'))->toBeTrue();

        $config->removeEnvironment('staging');
        expect($config->hasEnvironment('staging'))->toBeFalse()
            ->and($config->getEnvironments())->not->toContain('staging');
    }

    public function test_set_host_writes_per_env_and_per_service()
    {
        $config = ConfigData::from([]);

        $config->setHost('staging', 'web', 'staging.example.com');
        $config->setHost('staging', 'reverb', 'ws-stg.example.com');

        expect($config->getHost('staging', 'web'))->toBe('staging.example.com')
            ->and($config->getHost('staging', 'reverb'))->toBe('ws-stg.example.com')
            ->and($config->getHost('staging', 'mailpit'))->toBeNull()
            ->and($config->getHost('production', 'web'))->toBeNull();
    }

    public function test_get_service_host_honours_explicit_per_service_override()
    {
        // Reverb on its own subdomain should not get prefixed off the web host.
        $config = ConfigData::from([
            'name' => 'demo',
            'environments' => [
                'production' => [
                    'hosts' => [
                        'web' => 'example.com',
                        'reverb' => 'ws.example.com',
                    ],
                ],
            ],
        ]);

        expect($config->getServiceHost('reverb', 'production'))->toBe('ws.example.com');
        // Services without explicit overrides still derive from the web host.
        expect($config->getServiceHost('vite', 'production'))->toBe('vite-example.com');
    }

    public function test_get_service_host_works_for_any_non_local_env_not_just_production()
    {
        // If the user renames "production" to "main" or adds a "qa" env,
        // service-host resolution must still honour the configured web host.
        $config = ConfigData::from([
            'name' => 'demo',
            'environments' => [
                'qa' => ['hosts' => ['web' => 'qa.example.com']],
                'main' => ['hosts' => ['web' => 'example.com']],
            ],
        ]);

        expect($config->getServiceHost('vite', 'qa'))->toBe('vite-qa.example.com')
            ->and($config->getServiceHost('vite', 'main'))->toBe('vite-example.com')
            ->and($config->getAppUrl('qa'))->toBe('https://qa.example.com')
            ->and($config->getAppUrl('main'))->toBe('https://example.com');
    }

    public function test_get_shared_service_host_honours_a_larakube_json_override()
    {
        // A cloud Grafana host lives in the env hosts map like web/reverb/s3.
        $config = ConfigData::from([
            'name' => 'demo',
            'environments' => [
                'production' => [
                    'hosts' => [
                        'web' => 'app.example.com',
                        'grafana' => 'metrics.example.com',
                    ],
                ],
            ],
        ]);

        expect($config->getSharedServiceHost(SharedClusterService::GRAFANA, 'production'))->toBe('metrics.example.com');
    }

    public function test_get_shared_service_host_derives_from_web_host_on_cloud_without_override()
    {
        $config = ConfigData::from([
            'name' => 'demo',
            'environments' => [
                'production' => ['hosts' => ['web' => 'app.example.com']],
            ],
        ]);

        // Name-less global host, derived off the web host like other services —
        // no project-name segment, unlike getServiceHost().
        expect($config->getSharedServiceHost(SharedClusterService::GRAFANA, 'production'))->toBe('grafana-app.example.com');
    }

    public function test_get_shared_service_host_uses_global_tld_locally_without_project_name()
    {
        $config = ConfigData::from(['name' => 'demo', 'localTld' => 'test']);

        // Shared cluster hosts are name-less and follow the GLOBAL dev TLD, not
        // the project's localTld override (they're shared across all projects).
        $globalTld = GlobalConfigData::load()->getLocalTld();
        $host = $config->getSharedServiceHost(SharedClusterService::GRAFANA, 'local');

        expect($host)->toBe("grafana.{$globalTld}")->not->toContain('demo');
    }

    public function test_get_manageable_services_lists_all_externalizable_backing_services()
    {
        $config = ConfigData::from([
            'database' => 'postgres',
            'cacheDriver' => 'redis',
            'scoutDriver' => 'meilisearch',
            'objectStorage' => 'minio',
        ]);

        $services = $config->getManageableServices();

        // DB, cache, search, and object storage are all offload-able.
        expect($services)->toHaveKey('postgres');
        expect($services)->toHaveKeys(['redis', 'meilisearch', 'minio']);
    }

    public function test_get_manageable_services_excludes_drivers_with_no_network_service()
    {
        // SQLite, database-backed cache, and database-backed scout have nothing
        // to offload to a managed provider — they must not appear.
        $config = ConfigData::from([
            'database' => 'sqlite',
            'cacheDriver' => 'database',
            'scoutDriver' => 'database',
        ]);

        expect($config->getManageableServices())->toBeEmpty();
    }

    public function test_set_host_creates_the_environment_and_writes_into_the_environment_map()
    {
        $config = ConfigData::from(['environments' => ['local' => []]]);
        expect($config->hasEnvironment('production'))->toBeFalse();

        $config->setHost('production', 'web', 'app.example.com');

        expect($config->getWebHost('production'))->toBe('app.example.com')
            ->and($config->getEnvironment('production')->hosts['web'])->toBe('app.example.com');
    }

    public function test_get_web_hosts_returns_just_the_primary_when_no_additional_hosts_are_set()
    {
        $config = ConfigData::from([
            'name' => 'myapp',
            'environments' => ['production' => ['hosts' => ['web' => 'app.example.com']]],
        ]);

        expect($config->getWebHosts('production'))->toBe(['app.example.com']);
    }

    public function test_get_web_hosts_lists_the_primary_first_then_additional_hosts_in_order()
    {
        $config = ConfigData::from([
            'name' => 'myapp',
            'environments' => [
                'production' => [
                    'hosts' => ['web' => 'app.example.com'],
                    'additionalWebHosts' => ['admin.example.com', 'mybrand.io'],
                ],
            ],
        ]);

        expect($config->getWebHosts('production'))->toBe(['app.example.com', 'admin.example.com', 'mybrand.io']);
    }

    public function test_get_web_hosts_dedupes_an_additional_host_that_matches_the_primary()
    {
        $config = ConfigData::from([
            'name' => 'myapp',
            'environments' => [
                'production' => [
                    'hosts' => ['web' => 'app.example.com'],
                    'additionalWebHosts' => ['app.example.com', 'admin.example.com'],
                ],
            ],
        ]);

        expect($config->getWebHosts('production'))->toBe(['app.example.com', 'admin.example.com']);
    }

    public function test_add_additional_web_host_is_idempotent_and_creates_the_environment_if_missing()
    {
        $config = ConfigData::from(['name' => 'myapp', 'environments' => ['local' => []]]);
        expect($config->hasEnvironment('production'))->toBeFalse();

        $config->addAdditionalWebHost('production', 'admin.example.com');
        $config->addAdditionalWebHost('production', 'admin.example.com');

        expect($config->getEnvironment('production')->additionalWebHosts)->toBe(['admin.example.com']);
    }

    public function test_remove_additional_web_host_is_a_no_op_when_the_environment_or_host_is_missing()
    {
        $config = ConfigData::from(['name' => 'myapp', 'environments' => ['local' => []]]);

        // No exception, no side effect, for an environment that doesn't exist.
        $config->removeAdditionalWebHost('production', 'admin.example.com');
        expect($config->hasEnvironment('production'))->toBeFalse();

        $config->addAdditionalWebHost('production', 'admin.example.com');
        $config->removeAdditionalWebHost('production', 'someone-else.example.com');

        expect($config->getEnvironment('production')->additionalWebHosts)->toBe(['admin.example.com']);

        $config->removeAdditionalWebHost('production', 'admin.example.com');
        expect($config->getEnvironment('production')->additionalWebHosts)->toBeEmpty();
    }

    public function test_each_environment_can_choose_its_own_ingress_controller()
    {
        $config = ConfigData::from([
            'environments' => [
                'local' => [],
                'staging' => ['ingress' => 'traefik'],
                'qa' => ['ingress' => 'nginx'],
                'production' => ['ingress' => 'aws-alb'],
            ],
        ]);

        expect($config->getIngress('local'))->toEqual(IngressController::TRAEFIK)
            ->and($config->getIngress('staging'))->toEqual(IngressController::TRAEFIK)
            ->and($config->getIngress('qa'))->toEqual(IngressController::NGINX)
            ->and($config->getIngress('production'))->toEqual(IngressController::AWS_ALB);
    }

    public function test_get_ingress_defaults_to_traefik_for_unconfigured_environments()
    {
        $config = ConfigData::from(['environments' => ['local' => [], 'production' => []]]);

        expect($config->getIngress('local'))->toEqual(IngressController::TRAEFIK)
            ->and($config->getIngress('production'))->toEqual(IngressController::TRAEFIK);
    }

    public function test_build_wait_for_command()
    {
        // 1. System projects skip external TCP checks but still return null without waitForWeb
        $config = ConfigData::from(['isSystem' => true]);
        expect($config->buildWaitForCommand([DatabaseDriver::MYSQL]))->toBeNull();

        // 2. System project WITH waitForWeb=true returns curl check (not TCP check)
        $command = $config->buildWaitForCommand([DatabaseDriver::MYSQL], waitForWeb: true);
        expect($command)->toContain('curl -sf http://web/up')->not->toContain('mysql');

        // 3. Normal project with MySQL returns nc command
        $config = ConfigData::from(['isSystem' => false]);
        $command = $config->buildWaitForCommand([DatabaseDriver::MYSQL]);
        expect($command)->toContain('mysql 3306');

        // 4. Normal project with MySQL + waitForWeb includes BOTH checks
        $command = $config->buildWaitForCommand([DatabaseDriver::MYSQL], waitForWeb: true);
        expect($command)->toContain('curl -sf http://web/up')
            ->toContain('mysql 3306');

        // 5. SQLite returns null (no external service)
        expect($config->buildWaitForCommand([DatabaseDriver::SQLITE]))->toBeNull();

        // 6. SQLite + waitForWeb returns only curl check
        $command = $config->buildWaitForCommand([DatabaseDriver::SQLITE], waitForWeb: true);
        expect($command)->toContain('curl -sf http://web/up')->not->toContain('sqlite');

        // 7. Redis cache returns command on port 6379
        $command = $config->buildWaitForCommand([CacheDriver::REDIS]);
        expect($command)->toContain('redis 6379');

        // 8. Memcached cache returns command on port 11211
        $command = $config->buildWaitForCommand([CacheDriver::MEMCACHED]);
        expect($command)->toContain('memcached 11211');

        // 9. Database cache returns null
        expect($config->buildWaitForCommand([CacheDriver::DATABASE]))->toBeNull();

        // 10. Meilisearch scout returns command on port 7700
        $command = $config->buildWaitForCommand([SearchDriver::MEILISEARCH]);
        expect($command)->toContain('meilisearch 7700');

        // 11. Typesense scout returns command on port 8108
        $command = $config->buildWaitForCommand([SearchDriver::TYPESENSE]);
        expect($command)->toContain('typesense 8108');

        // 12. Database scout returns null
        expect($config->buildWaitForCommand([SearchDriver::DATABASE]))->toBeNull();

        // 13. Storage drivers return command on correct ports
        $command = $config->buildWaitForCommand([StorageDriver::MINIO]);
        expect($command)->toContain('minio 9000');

        $command = $config->buildWaitForCommand([StorageDriver::SEAWEEDFS]);
        expect($command)->toContain('seaweedfs 8333');

        $command = $config->buildWaitForCommand([StorageDriver::GARAGE]);
        expect($command)->toContain('garage 3900');
    }

    public function test_is_scaffolding_getter_works_as_method()
    {
        // Ensures isScaffolding() can be called as a method (not just as a property).
        // This would have caught the BadMethodCallException thrown during `larakube new`.
        $config = ConfigData::from(['isScaffolding' => false]);
        expect($config->isScaffolding())->toBeFalse();

        $config->setIsScaffolding(true);
        expect($config->isScaffolding())->toBeTrue();
    }

    public function test_php_version_is_hidden_respects_scaffolding()
    {
        // Ensures PhpVersion::isHidden() can call $config->isScaffolding() without crashing.
        // This would have caught the BadMethodCallException thrown during `larakube new`.
        $scaffolding = ConfigData::from(['isScaffolding' => true]);

        // Old versions should be hidden when scaffolding a new project (Laravel 13 requires 8.3+)
        expect(PhpVersion::PHP_8_2->isHidden($scaffolding))->toBeTrue();
        expect(PhpVersion::PHP_8_1->isHidden($scaffolding))->toBeTrue()
            ->and(PhpVersion::PHP_8_0->isHidden($scaffolding))->toBeTrue()
            ->and(PhpVersion::PHP_7_4->isHidden($scaffolding))->toBeTrue();

        // Modern versions must remain visible when scaffolding
        expect(PhpVersion::PHP_8_5->isHidden($scaffolding))->toBeFalse();
        expect(PhpVersion::PHP_8_4->isHidden($scaffolding))->toBeFalse()
            ->and(PhpVersion::PHP_8_3->isHidden($scaffolding))->toBeFalse();

        // Without scaffolding, old versions are visible
        $existing = ConfigData::from(['isScaffolding' => false]);
        expect(PhpVersion::PHP_8_2->isHidden($existing))->toBeFalse()
            ->and(PhpVersion::PHP_8_1->isHidden($existing))->toBeFalse();
    }

    public function test_watch_paths_default_to_standard_laravel_dirs()
    {
        $config = ConfigData::from([]);

        expect($config->getWatchPaths())->toBe(['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'composer.lock', '.env']);
    }

    public function test_watch_paths_can_be_overridden_via_blueprint()
    {
        $config = ConfigData::from([
            'watchPaths' => ['app', 'domain', 'modules'],
        ]);

        expect($config->getWatchPaths())->toBe(['app', 'domain', 'modules']);
    }

    public function test_provision_test_db_defaults_to_false()
    {
        $config = ConfigData::from([]);

        expect($config->getProvisionTestDb())->toBeFalse();
    }

    public function test_provision_test_db_can_be_enabled_via_blueprint()
    {
        $config = ConfigData::from(['provisionTestDb' => true]);

        expect($config->getProvisionTestDb())->toBeTrue();
    }

    public function test_local_tld_defaults_to_global_when_project_has_no_override()
    {
        $config = ConfigData::from(['name' => 'demo']);

        expect($config->hasLocalTld())->toBeFalse()
            ->and($config->getLocalTld())->toBe('kube');
    }

    public function test_local_tld_override_wins_over_the_global_default()
    {
        $config = ConfigData::from(['name' => 'demo', 'localTld' => 'test']);

        expect($config->hasLocalTld())->toBeTrue()
            ->and($config->getLocalTld())->toBe('test')
            ->and($config->getAppUrl('local'))->toBe('https://demo.test')
            ->and($config->getServiceHost('vite', 'local'))->toBe('vite.demo.test');
    }

    public function test_set_local_tld_normalizes_and_can_be_cleared()
    {
        $config = ConfigData::from(['name' => 'demo']);

        $config->setLocalTld('.TEST');
        expect($config->getLocalTld())->toBe('test');

        $config->setLocalTld(null);
        expect($config->hasLocalTld())->toBeFalse()
            ->and($config->getLocalTld())->toBe('kube');
    }

    public function test_add_additional_extension_appends_uniquely()
    {
        $config = ConfigData::from(['name' => 'demo']);

        $config->addAdditionalExtension('imagick');
        $config->addAdditionalExtension('gd');
        $config->addAdditionalExtension('imagick'); // duplicate — must not double up

        expect($config->getAdditionalExtensions())->toBe(['imagick', 'gd']);
    }

    public function test_remove_additional_extension_drops_and_reindexes()
    {
        $config = ConfigData::from(['name' => 'demo', 'additionalExtensions' => ['imagick', 'gd', 'redis']]);

        $config->removeAdditionalExtension('gd');
        $config->removeAdditionalExtension('missing'); // no-op — must not error

        expect($config->getAdditionalExtensions())->toBe(['imagick', 'redis']);
    }

    public function test_service_connection_variable_names_include_plex_backed_drivers()
    {
        // Regression guard: `up`'s ConfigMap injection for local uses this list
        // as its sole allowlist for non-secret vars — excluding a plex-backed
        // driver's keys (DB_HOST, REDIS_HOST, …) meant they never made it into
        // the ConfigMap once joined to Plex, silently stranding the pod on a
        // self-hosted host that plex:join had already torn down. The key NAMES
        // are identical whether self-hosted or Commons-backed; only the VALUES
        // (already written into .env by plex:join) differ.
        $selfHosted = ConfigData::from([
            'name' => 'demo',
            'database' => 'postgres',
        ]);

        $plexBacked = ConfigData::from([
            'name' => 'demo',
            'database' => 'postgres',
            'environments' => [
                'local' => ['managed' => ['postgres'], 'plex' => ['postgres']],
            ],
        ]);

        expect($selfHosted->getServiceConnectionVariableNames('local'))->toContain('DB_HOST')
            ->and($plexBacked->getServiceConnectionVariableNames('local'))->toBe($selfHosted->getServiceConnectionVariableNames('local'));
    }
}
