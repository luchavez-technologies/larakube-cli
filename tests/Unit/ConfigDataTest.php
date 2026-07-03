<?php

namespace Tests\Unit;

use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Enums\Blueprint;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\DeploymentStrategy;
use App\Enums\FrontendStack;
use App\Enums\IngressController;
use App\Enums\LaravelFeature;
use App\Enums\OperatingSystem;
use App\Enums\PhpVersion;
use App\Enums\ScoutDriver;
use App\Enums\ServerVariation;
use App\Enums\SharedClusterService;
use App\Enums\StorageDriver;
use Tests\TestCase;

class ConfigDataTest extends TestCase
{
    public function test_it_casts_strings_to_enums_correctly()
    {
        $data = [
            'blueprints' => ['statamic'],
            'serverVariation' => 'fpm-nginx',
            'frontend' => 'react',
            'phpVersion' => '8.5',
            'os' => 'alpine',
            'strategy' => 'single-node',
        ];

        $config = ConfigData::from($data);

        // Single Enums
        $this->assertEquals(ServerVariation::FPM_NGINX, $config->serverVariation);
        $this->assertEquals(FrontendStack::REACT, $config->frontend);
        $this->assertEquals(PhpVersion::PHP_8_5, $config->phpVersion);
        $this->assertEquals(OperatingSystem::ALPINE, $config->os);
        $this->assertEquals(DeploymentStrategy::SINGLE_NODE, $config->strategy);

        // Array of Enums
        $this->assertIsArray($config->blueprints);
        $this->assertEquals(Blueprint::STATAMIC, $config->blueprints[0]);
    }

    public function test_it_handles_multiple_enum_values_in_arrays()
    {
        $data = [
            'databases' => ['mysql', 'postgres'],
            'cacheDrivers' => ['redis'],
            'features' => ['horizon', 'reverb'],
        ];

        $config = ConfigData::from($data);

        $this->assertCount(2, $config->databases);
        $this->assertEquals(DatabaseDriver::MYSQL, $config->databases[0]);
        $this->assertEquals(DatabaseDriver::POSTGRESQL, $config->databases[1]);

        $this->assertCount(1, $config->cacheDrivers);
        $this->assertEquals(CacheDriver::REDIS, $config->cacheDrivers[0]);

        $this->assertCount(2, $config->features);
        $this->assertEquals(LaravelFeature::HORIZON, $config->features[0]);
        $this->assertEquals(LaravelFeature::REVERB, $config->features[1]);
    }

    public function test_it_maintains_default_values()
    {
        $config = ConfigData::from([]);

        $this->assertEquals(DeploymentStrategy::SINGLE_NODE, $config->strategy);
        $this->assertSame(['local', 'production'], $config->getEnvironments());
        $this->assertInstanceOf(EnvironmentData::class, $config->getEnvironment('local'));
        $this->assertInstanceOf(EnvironmentData::class, $config->getEnvironment('production'));
        $this->assertEquals(IngressController::TRAEFIK, $config->getIngress('local'));
        $this->assertEquals(IngressController::TRAEFIK, $config->getIngress('production'));
        $this->assertTrue($config->githubActions);
        $this->assertFalse($config->isSystem);
        $this->assertFalse($config->isScaffolding);
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

        $this->assertInstanceOf(EnvironmentData::class, $config->getEnvironment('production'));
        $this->assertSame(['postgres', 'redis'], $config->getManaged('production'));
        $this->assertSame([], $config->getManaged('local'));
        $this->assertSame('example.com', $config->getEnvironment('production')->hosts['web']);
        $this->assertSame('https://example.com', $config->getAppUrl('production'));
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
        $this->assertSame('production', $prod['APP_ENV']);
        $this->assertSame('false', $prod['APP_DEBUG']);

        // Even a non-production cloud env (staging) reports "production".
        $staging = $config->getAllPublicEnvironmentVariables('staging');
        $this->assertSame('production', $staging['APP_ENV']);
        $this->assertSame('false', $staging['APP_DEBUG']);

        // Local is left alone (keeps Laravel's own APP_ENV=local / APP_DEBUG=true).
        $local = $config->getAllPublicEnvironmentVariables('local');
        $this->assertArrayNotHasKey('APP_DEBUG', $local);
    }

    public function test_features_filter_by_env_with_enum_defaults()
    {
        // BOOST + MCP default to local only; HORIZON to all envs; SSR to prod only.
        $config = ConfigData::from([
            'features' => ['boost', 'mcp', 'horizon', 'ssr'],
        ]);

        $local = $config->getFeatures('local');
        $this->assertContains(LaravelFeature::BOOST, $local);
        $this->assertContains(LaravelFeature::MCP, $local);
        $this->assertContains(LaravelFeature::HORIZON, $local);
        $this->assertNotContains(LaravelFeature::SSR, $local);

        $prod = $config->getFeatures('production');
        $this->assertContains(LaravelFeature::HORIZON, $prod);
        $this->assertContains(LaravelFeature::SSR, $prod);
        $this->assertNotContains(LaravelFeature::BOOST, $prod);
        $this->assertNotContains(LaravelFeature::MCP, $prod);
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

        $this->assertNotContains(LaravelFeature::HORIZON, $config->getFeatures('local'));
        $this->assertContains(LaravelFeature::BOOST, $config->getFeatures('local'));
        $this->assertContains(LaravelFeature::HORIZON, $config->getFeatures('production'));
        $this->assertContains(LaravelFeature::BOOST, $config->getFeatures('production'));
    }

    public function test_save_to_file_omits_transient_fields()
    {
        $tmp = sys_get_temp_dir().'/larakube-save-'.uniqid();
        mkdir($tmp, 0755, true);

        try {
            $config = ConfigData::from(['name' => 'demo', 'isScaffolding' => true]);
            $config->setPath($tmp);
            $config->saveToFile($tmp);

            $json = json_decode(file_get_contents("{$tmp}/.larakube.json"), true);

            // Transient/machine-specific fields are not persisted...
            $this->assertArrayNotHasKey('isScaffolding', $json);
            $this->assertArrayNotHasKey('path', $json);
            // ...but real fields are.
            $this->assertSame('demo', $json['name']);

            // And it still round-trips with sane defaults.
            $reloaded = ConfigData::from($json);
            $this->assertFalse($reloaded->isScaffolding());
        } finally {
            exec('rm -rf '.escapeshellarg($tmp));
        }
    }

    public function test_add_environment_creates_a_new_env_idempotently()
    {
        $config = ConfigData::from([]);

        $this->assertFalse($config->hasEnvironment('staging'));

        $config->addEnvironment('staging');
        $this->assertTrue($config->hasEnvironment('staging'));
        $this->assertInstanceOf(EnvironmentData::class, $config->getEnvironment('staging'));

        $config->getEnvironment('staging')->managed = ['postgres'];
        $config->addEnvironment('staging');
        $this->assertSame(['postgres'], $config->getManaged('staging'));
    }

    public function test_remove_environment_drops_it_from_the_map()
    {
        $config = ConfigData::from([]);
        $config->addEnvironment('staging');
        $this->assertTrue($config->hasEnvironment('staging'));

        $config->removeEnvironment('staging');
        $this->assertFalse($config->hasEnvironment('staging'));
        $this->assertNotContains('staging', $config->getEnvironments());
    }

    public function test_set_host_writes_per_env_and_per_service()
    {
        $config = ConfigData::from([]);

        $config->setHost('staging', 'web', 'staging.example.com');
        $config->setHost('staging', 'reverb', 'ws-stg.example.com');

        $this->assertSame('staging.example.com', $config->getHost('staging', 'web'));
        $this->assertSame('ws-stg.example.com', $config->getHost('staging', 'reverb'));
        $this->assertNull($config->getHost('staging', 'mailpit'));
        $this->assertNull($config->getHost('production', 'web'));
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

        $this->assertSame('ws.example.com', $config->getServiceHost('reverb', 'production'));
        // Services without explicit overrides still derive from the web host.
        $this->assertSame('vite-example.com', $config->getServiceHost('vite', 'production'));
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

        $this->assertSame('vite-qa.example.com', $config->getServiceHost('vite', 'qa'));
        $this->assertSame('vite-example.com', $config->getServiceHost('vite', 'main'));
        $this->assertSame('https://qa.example.com', $config->getAppUrl('qa'));
        $this->assertSame('https://example.com', $config->getAppUrl('main'));
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

        $this->assertSame(
            'metrics.example.com',
            $config->getSharedServiceHost(SharedClusterService::GRAFANA, 'production'),
        );
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
        $this->assertSame(
            'grafana-app.example.com',
            $config->getSharedServiceHost(SharedClusterService::GRAFANA, 'production'),
        );
    }

    public function test_get_shared_service_host_uses_global_tld_locally_without_project_name()
    {
        $config = ConfigData::from(['name' => 'demo', 'localTld' => 'test']);

        // Shared cluster hosts are name-less and follow the GLOBAL dev TLD, not
        // the project's localTld override (they're shared across all projects).
        $globalTld = \App\Data\GlobalConfigData::load()->getLocalTld();
        $host = $config->getSharedServiceHost(SharedClusterService::GRAFANA, 'local');

        $this->assertSame("grafana.{$globalTld}", $host);
        $this->assertStringNotContainsString('demo', $host);
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
        $this->assertArrayHasKey('postgres', $services);
        $this->assertArrayHasKey('redis', $services);
        $this->assertArrayHasKey('meilisearch', $services);
        $this->assertArrayHasKey('minio', $services);
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

        $this->assertSame([], $config->getManageableServices());
    }

    public function test_set_host_creates_the_environment_and_writes_into_the_environment_map()
    {
        $config = ConfigData::from(['environments' => ['local' => []]]);
        $this->assertFalse($config->hasEnvironment('production'));

        $config->setHost('production', 'web', 'app.example.com');

        $this->assertSame('app.example.com', $config->getWebHost('production'));
        $this->assertSame('app.example.com', $config->getEnvironment('production')->hosts['web']);
    }

    public function test_get_web_hosts_returns_just_the_primary_when_no_additional_hosts_are_set()
    {
        $config = ConfigData::from([
            'name' => 'myapp',
            'environments' => ['production' => ['hosts' => ['web' => 'app.example.com']]],
        ]);

        $this->assertSame(['app.example.com'], $config->getWebHosts('production'));
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

        $this->assertSame(
            ['app.example.com', 'admin.example.com', 'mybrand.io'],
            $config->getWebHosts('production'),
        );
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

        $this->assertSame(['app.example.com', 'admin.example.com'], $config->getWebHosts('production'));
    }

    public function test_add_additional_web_host_is_idempotent_and_creates_the_environment_if_missing()
    {
        $config = ConfigData::from(['name' => 'myapp', 'environments' => ['local' => []]]);
        $this->assertFalse($config->hasEnvironment('production'));

        $config->addAdditionalWebHost('production', 'admin.example.com');
        $config->addAdditionalWebHost('production', 'admin.example.com');

        $this->assertSame(['admin.example.com'], $config->getEnvironment('production')->additionalWebHosts);
    }

    public function test_remove_additional_web_host_is_a_no_op_when_the_environment_or_host_is_missing()
    {
        $config = ConfigData::from(['name' => 'myapp', 'environments' => ['local' => []]]);

        // No exception, no side effect, for an environment that doesn't exist.
        $config->removeAdditionalWebHost('production', 'admin.example.com');
        $this->assertFalse($config->hasEnvironment('production'));

        $config->addAdditionalWebHost('production', 'admin.example.com');
        $config->removeAdditionalWebHost('production', 'someone-else.example.com');

        $this->assertSame(['admin.example.com'], $config->getEnvironment('production')->additionalWebHosts);

        $config->removeAdditionalWebHost('production', 'admin.example.com');
        $this->assertSame([], $config->getEnvironment('production')->additionalWebHosts);
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

        $this->assertEquals(IngressController::TRAEFIK, $config->getIngress('local'));
        $this->assertEquals(IngressController::TRAEFIK, $config->getIngress('staging'));
        $this->assertEquals(IngressController::NGINX, $config->getIngress('qa'));
        $this->assertEquals(IngressController::AWS_ALB, $config->getIngress('production'));
    }

    public function test_get_ingress_defaults_to_traefik_for_unconfigured_environments()
    {
        $config = ConfigData::from(['environments' => ['local' => [], 'production' => []]]);

        $this->assertEquals(IngressController::TRAEFIK, $config->getIngress('local'));
        $this->assertEquals(IngressController::TRAEFIK, $config->getIngress('production'));
    }

    public function test_build_wait_for_command()
    {
        // 1. System projects skip external TCP checks but still return null without waitForWeb
        $config = ConfigData::from(['isSystem' => true]);
        $this->assertNull($config->buildWaitForCommand([DatabaseDriver::MYSQL]));

        // 2. System project WITH waitForWeb=true returns curl check (not TCP check)
        $command = $config->buildWaitForCommand([DatabaseDriver::MYSQL], waitForWeb: true);
        $this->assertStringContainsString('curl -sf http://web/up', $command);
        $this->assertStringNotContainsString('mysql', $command);

        // 3. Normal project with MySQL returns nc command
        $config = ConfigData::from(['isSystem' => false]);
        $command = $config->buildWaitForCommand([DatabaseDriver::MYSQL]);
        $this->assertStringContainsString('mysql 3306', $command);

        // 4. Normal project with MySQL + waitForWeb includes BOTH checks
        $command = $config->buildWaitForCommand([DatabaseDriver::MYSQL], waitForWeb: true);
        $this->assertStringContainsString('curl -sf http://web/up', $command);
        $this->assertStringContainsString('mysql 3306', $command);

        // 5. SQLite returns null (no external service)
        $this->assertNull($config->buildWaitForCommand([DatabaseDriver::SQLITE]));

        // 6. SQLite + waitForWeb returns only curl check
        $command = $config->buildWaitForCommand([DatabaseDriver::SQLITE], waitForWeb: true);
        $this->assertStringContainsString('curl -sf http://web/up', $command);
        $this->assertStringNotContainsString('sqlite', $command);

        // 7. Redis cache returns command on port 6379
        $command = $config->buildWaitForCommand([CacheDriver::REDIS]);
        $this->assertStringContainsString('redis 6379', $command);

        // 8. Memcached cache returns command on port 11211
        $command = $config->buildWaitForCommand([CacheDriver::MEMCACHED]);
        $this->assertStringContainsString('memcached 11211', $command);

        // 9. Database cache returns null
        $this->assertNull($config->buildWaitForCommand([CacheDriver::DATABASE]));

        // 10. Meilisearch scout returns command on port 7700
        $command = $config->buildWaitForCommand([ScoutDriver::MEILISEARCH]);
        $this->assertStringContainsString('meilisearch 7700', $command);

        // 11. Typesense scout returns command on port 8108
        $command = $config->buildWaitForCommand([ScoutDriver::TYPESENSE]);
        $this->assertStringContainsString('typesense 8108', $command);

        // 12. Database scout returns null
        $this->assertNull($config->buildWaitForCommand([ScoutDriver::DATABASE]));

        // 13. Storage drivers return command on correct ports
        $command = $config->buildWaitForCommand([StorageDriver::MINIO]);
        $this->assertStringContainsString('minio 9000', $command);

        $command = $config->buildWaitForCommand([StorageDriver::SEAWEEDFS]);
        $this->assertStringContainsString('seaweedfs 8333', $command);

        $command = $config->buildWaitForCommand([StorageDriver::GARAGE]);
        $this->assertStringContainsString('garage 3900', $command);
    }

    public function test_is_scaffolding_getter_works_as_method()
    {
        // Ensures isScaffolding() can be called as a method (not just as a property).
        // This would have caught the BadMethodCallException thrown during `larakube new`.
        $config = ConfigData::from(['isScaffolding' => false]);
        $this->assertFalse($config->isScaffolding());

        $config->setIsScaffolding(true);
        $this->assertTrue($config->isScaffolding());
    }

    public function test_php_version_is_hidden_respects_scaffolding()
    {
        // Ensures PhpVersion::isHidden() can call $config->isScaffolding() without crashing.
        // This would have caught the BadMethodCallException thrown during `larakube new`.
        $scaffolding = ConfigData::from(['isScaffolding' => true]);

        // Old versions should be hidden when scaffolding a new project (Laravel 13 requires 8.3+)
        $this->assertTrue(PhpVersion::PHP_8_2->isHidden($scaffolding));
        $this->assertTrue(PhpVersion::PHP_8_1->isHidden($scaffolding));
        $this->assertTrue(PhpVersion::PHP_8_0->isHidden($scaffolding));
        $this->assertTrue(PhpVersion::PHP_7_4->isHidden($scaffolding));

        // Modern versions must remain visible when scaffolding
        $this->assertFalse(PhpVersion::PHP_8_5->isHidden($scaffolding));
        $this->assertFalse(PhpVersion::PHP_8_4->isHidden($scaffolding));
        $this->assertFalse(PhpVersion::PHP_8_3->isHidden($scaffolding));

        // Without scaffolding, old versions are visible
        $existing = ConfigData::from(['isScaffolding' => false]);
        $this->assertFalse(PhpVersion::PHP_8_2->isHidden($existing));
        $this->assertFalse(PhpVersion::PHP_8_1->isHidden($existing));
    }

    public function test_watch_paths_default_to_standard_laravel_dirs()
    {
        $config = ConfigData::from([]);

        $this->assertSame(
            ['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'composer.lock', '.env'],
            $config->getWatchPaths(),
        );
    }

    public function test_watch_paths_can_be_overridden_via_blueprint()
    {
        $config = ConfigData::from([
            'watchPaths' => ['app', 'domain', 'modules'],
        ]);

        $this->assertSame(['app', 'domain', 'modules'], $config->getWatchPaths());
    }

    public function test_provision_test_db_defaults_to_false()
    {
        $config = ConfigData::from([]);

        $this->assertFalse($config->getProvisionTestDb());
    }

    public function test_provision_test_db_can_be_enabled_via_blueprint()
    {
        $config = ConfigData::from(['provisionTestDb' => true]);

        $this->assertTrue($config->getProvisionTestDb());
    }

    public function test_local_tld_defaults_to_global_when_project_has_no_override()
    {
        $config = ConfigData::from(['name' => 'demo']);

        $this->assertFalse($config->hasLocalTld());
        $this->assertSame('kube', $config->getLocalTld());
    }

    public function test_local_tld_override_wins_over_the_global_default()
    {
        $config = ConfigData::from(['name' => 'demo', 'localTld' => 'test']);

        $this->assertTrue($config->hasLocalTld());
        $this->assertSame('test', $config->getLocalTld());
        $this->assertSame('https://demo.test', $config->getAppUrl('local'));
        $this->assertSame('vite.demo.test', $config->getServiceHost('vite', 'local'));
    }

    public function test_set_local_tld_normalizes_and_can_be_cleared()
    {
        $config = ConfigData::from(['name' => 'demo']);

        $config->setLocalTld('.TEST');
        $this->assertSame('test', $config->getLocalTld());

        $config->setLocalTld(null);
        $this->assertFalse($config->hasLocalTld());
        $this->assertSame('kube', $config->getLocalTld());
    }

    public function test_add_additional_extension_appends_uniquely()
    {
        $config = ConfigData::from(['name' => 'demo']);

        $config->addAdditionalExtension('imagick');
        $config->addAdditionalExtension('gd');
        $config->addAdditionalExtension('imagick'); // duplicate — must not double up

        $this->assertSame(['imagick', 'gd'], $config->getAdditionalExtensions());
    }

    public function test_remove_additional_extension_drops_and_reindexes()
    {
        $config = ConfigData::from(['name' => 'demo', 'additionalExtensions' => ['imagick', 'gd', 'redis']]);

        $config->removeAdditionalExtension('gd');
        $config->removeAdditionalExtension('missing'); // no-op — must not error

        $this->assertSame(['imagick', 'redis'], $config->getAdditionalExtensions());
    }
}
