<?php

namespace Tests\Unit;

use App\Data\ConfigData;
use App\Enums\Blueprint;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\DeploymentStrategy;
use App\Enums\FrontendStack;
use App\Enums\LaravelFeature;
use App\Enums\OperatingSystem;
use App\Enums\PhpVersion;
use App\Enums\ScoutDriver;
use App\Enums\ServerVariation;
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
        $this->assertEquals(['local', 'production'], $config->environments);
        $this->assertTrue($config->githubActions);
        $this->assertFalse($config->isSystem);
        $this->assertFalse($config->isScaffolding);
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
}
