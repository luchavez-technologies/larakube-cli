<?php

use App\Data\ConfigData;
use App\Enums\CompanionDriver;
use App\Traits\ManagesCompanions;

function companionsHarness(array $preInstalled = []): object
{
    return new class($preInstalled)
    {
        use ManagesCompanions;

        public array $deployed = [];

        public bool $namespaceEnsured = false;

        public bool $phpMyAdminRefreshed = false;

        public function __construct(private array $preInstalled) {}

        public function ensure(ConfigData $config, string $appName): void
        {
            $this->ensureProjectCompanions($config, $appName);
        }

        protected function isCompanionInstalled(CompanionDriver $companion): bool
        {
            return in_array($companion, $this->preInstalled, true);
        }

        protected function ensureCompanionNamespace(): void
        {
            $this->namespaceEnsured = true;
        }

        protected function deployCompanion(CompanionDriver $companion): void
        {
            $this->deployed[] = $companion;
        }

        protected function refreshPhpMyAdminServers(ConfigData $config, string $appName): void
        {
            $this->phpMyAdminRefreshed = true;
        }
    };
}

test('ensureProjectCompanions does nothing when withCompanions is false', function (): void {
    $config = ConfigData::from(['name' => 'demo', 'database' => 'mariadb', 'withCompanions' => false]);
    $harness = companionsHarness([CompanionDriver::PHPMYADMIN]);

    $harness->ensure($config, 'demo');

    expect($harness->deployed)->toBe([])
        ->and($harness->namespaceEnsured)->toBeFalse()
        ->and($harness->phpMyAdminRefreshed)->toBeFalse();
});

test('ensureProjectCompanions never auto-installs phpMyAdmin — companions are opt-in via companion:add', function (): void {
    $config = ConfigData::from(['name' => 'demo', 'database' => 'mariadb']);
    $harness = companionsHarness(); // nothing pre-installed

    $harness->ensure($config, 'demo');

    expect($harness->deployed)->toBe([])
        ->and($harness->namespaceEnsured)->toBeFalse();
});

test('ensureProjectCompanions never auto-installs pgAdmin for PostgreSQL', function (): void {
    $config = ConfigData::from(['name' => 'demo', 'database' => 'postgres']);
    $harness = companionsHarness();

    $harness->ensure($config, 'demo');

    expect($harness->deployed)->toBe([]);
});

test('ensureProjectCompanions never auto-installs Mongo Express for MongoDB', function (): void {
    $config = ConfigData::from(['name' => 'demo', 'database' => 'mongodb']);
    $harness = companionsHarness();

    $harness->ensure($config, 'demo');

    expect($harness->deployed)->toBe([]);
});

test('ensureProjectCompanions never auto-installs RedisInsight for Redis', function (): void {
    $config = ConfigData::from(['name' => 'demo', 'cacheDriver' => 'redis']);
    $harness = companionsHarness();

    $harness->ensure($config, 'demo');

    expect($harness->deployed)->toBe([]);
});

test('ensureProjectCompanions deploys nothing for SQLite', function (): void {
    $config = ConfigData::from(['name' => 'demo', 'database' => 'sqlite']);
    $harness = companionsHarness([CompanionDriver::PHPMYADMIN, CompanionDriver::PGADMIN, CompanionDriver::MONGO_EXPRESS]);

    $harness->ensure($config, 'demo');

    expect($harness->deployed)->toBe([]);
});

test('ensureProjectCompanions skips memcached — it has no companion at all', function (): void {
    $config = ConfigData::from(['name' => 'demo', 'cacheDriver' => 'memcached']);
    $harness = companionsHarness([CompanionDriver::REDISINSIGHT]);

    $harness->ensure($config, 'demo');

    expect($harness->deployed)->toBe([]);
});

test('ensureProjectCompanions re-applies an already-installed companion so its ingress tracks the current TLD', function (): void {
    // Re-applying unconditionally is the fix for stale companion ingresses after
    // config:tld — a companion installed under the old TLD must be re-rendered on
    // up so its host (e.g. phpmyadmin.localhost) resolves, not left write-once.
    // But it must already be installed — up never installs one for the first time.
    $config = ConfigData::from(['name' => 'demo', 'database' => 'postgres']);
    $harness = companionsHarness([CompanionDriver::PGADMIN]);

    $harness->ensure($config, 'demo');

    expect($harness->deployed)->toBe([CompanionDriver::PGADMIN])
        ->and($harness->namespaceEnsured)->toBeTrue();
});

test('ensureProjectCompanions re-applies phpMyAdmin and refreshes its server list when already installed', function (): void {
    $config = ConfigData::from(['name' => 'demo', 'database' => 'mariadb']);
    $harness = companionsHarness([CompanionDriver::PHPMYADMIN]);

    $harness->ensure($config, 'demo');

    expect($harness->deployed)->toBe([CompanionDriver::PHPMYADMIN])
        ->and($harness->namespaceEnsured)->toBeTrue()
        ->and($harness->phpMyAdminRefreshed)->toBeTrue();
});

test('ensureProjectCompanions re-applies only the already-installed companion, not both', function (): void {
    $config = ConfigData::from(['name' => 'demo', 'database' => 'mariadb', 'cacheDriver' => 'redis']);
    $harness = companionsHarness([CompanionDriver::REDISINSIGHT]);

    $harness->ensure($config, 'demo');

    expect($harness->deployed)->toBe([CompanionDriver::REDISINSIGHT]);
});
