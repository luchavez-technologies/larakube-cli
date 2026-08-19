<?php

use App\Data\ConfigData;
use App\Enums\CompanionDriver;
use App\Traits\ManagesCompanions;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * The connection block renders via Laravel Prompts table() (which writes straight
 * to stdout, bypassing line()), so the tests assert on the structured rows that
 * feed the table — data, not presentation. Columns are:
 * [0] Companion, [1] URL, [2] Host, [3] User, [4] Password, [5] Database.
 */
function companionAccessHarness(array $configmap = [], array $secret = []): object
{
    return new class($configmap, $secret)
    {
        use ManagesCompanions;

        public array $printed = [];

        public function __construct(public array $configmap, public array $secret) {}

        /** @return array<int, array<int, string>> */
        public function rows(ConfigData $config, string $appName, string $environment): array
        {
            return $this->companionAccessRows($config, $appName, $environment);
        }

        public function render(ConfigData $config, string $appName, string $environment): void
        {
            $this->showCompanionAccess($config, $appName, $environment);
        }

        public function line($string, $style = null, $verbosity = null): void
        {
            $this->printed[] = $string;
        }

        public function newLine($count = 1): void {}

        protected function isCompanionInstalled(CompanionDriver $companion): bool
        {
            return true; // pretend every companion is already installed
        }

        // Bypass kubectl: serve the injected ConfigMap/Secret data instead.
        protected function readClusterEnvVars(string $kind, string $name, string $namespace, bool $base64): array
        {
            return $kind === 'secret' ? $this->secret : $this->configmap;
        }
    };
}

/** Find a companion's row by its label (column 0). */
function companionRow(array $rows, string $companion): ?array
{
    foreach ($rows as $row) {
        if ($row[0] === $companion) {
            return $row;
        }
    }

    return null;
}

test('companionAccessRows only includes Mailpit when withCompanions is false', function (): void {
    $config = ConfigData::from(['name' => 'demo', 'database' => 'mariadb', 'withCompanions' => false]);

    $rows = companionAccessHarness()->rows($config, 'demo', 'local');

    expect($rows)->toHaveCount(1)
        ->and($rows[0][0])->toBe('Mailpit');
});

test('companionAccessRows includes a phpMyAdmin row when withCompanions is true', function (): void {
    $config = ConfigData::from(['name' => 'demo', 'database' => 'mariadb']);

    $rows = companionAccessHarness()->rows($config, 'demo', 'local');
    $names = array_column($rows, 0);

    expect($names)->toContain('Mailpit')->toContain('phpMyAdmin');
});

test('showCompanionAccess does nothing for non-local environments', function (): void {
    $config = ConfigData::from(['name' => 'demo', 'database' => 'mariadb']);
    $harness = companionAccessHarness();

    expect($harness->rows($config, 'demo', 'production'))->toBe([]);

    $harness->render($config, 'demo', 'production');
    expect($harness->printed)->toBe([]); // no header, no table
});

test('Plex tenant credentials are read from .env, not the enum defaults', function (): void {
    // Cluster carries no DB_* for a Plex-backed service; the truth lives in .env.
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    file_put_contents($dir.'/.env', implode("\n", [
        'APP_NAME=PlexReact',
        'DB_HOST=mariadb.larakube-plex.svc.cluster.local',
        'DB_DATABASE=plex_react',
        'DB_USERNAME=plex_react',
        'DB_PASSWORD="tenant-secret-xyz"',
    ]));

    $config = ConfigData::from(['name' => 'plex-react', 'database' => 'mariadb']);
    $config->setPath($dir);

    $rows = companionAccessHarness(configmap: [], secret: [])->rows($config, 'plex-react', 'local');
    $pma = companionRow($rows, 'phpMyAdmin');

    expect($pma)->not->toBeNull()
        ->and($pma[2])->toBe('mariadb.larakube-plex.svc.cluster.local') // Host
        ->and($pma[3])->toBe('plex_react')                             // User
        ->and($pma[4])->toBe('tenant-secret-xyz')                      // Password, quotes stripped
        ->and($pma[5])->toBe('plex_react')                             // Database
        ->and($pma[4])->not->toBe('larakubesecretpassword');

    $temporaryDirectory->delete();
});

test('self-hosted cluster Secret/ConfigMap override .env values', function (): void {
    // envFrom wins over .env in the pod, so the live ConfigMap/Secret are truth.
    $config = ConfigData::from(['name' => 'demo', 'database' => 'mariadb']);
    $config->setPath(sys_get_temp_dir()); // no .env here

    $rows = companionAccessHarness(
        configmap: [
            'DB_HOST' => 'mariadb.demo.svc.cluster.local',
            'DB_DATABASE' => 'laravel',
            'DB_USERNAME' => 'laravel',
        ],
        secret: ['DB_PASSWORD' => 'larakubesecretpassword'],
    )->rows($config, 'demo', 'local');

    $pma = companionRow($rows, 'phpMyAdmin');

    expect($pma)->not->toBeNull()
        ->and($pma[2])->toBe('mariadb.demo.svc.cluster.local') // Host
        ->and($pma[3])->toBe('laravel')                        // User
        ->and($pma[4])->toBe('larakubesecretpassword');        // Password
});
