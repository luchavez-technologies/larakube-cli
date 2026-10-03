<?php

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use App\Traits\GathersInfrastructureConfig;

/**
 * `new --postgres` names the database the app wants. It was recorded as an
 * extra database while SQLite stayed primary, so plex:join (which reads the
 * primary one) never shared the Postgres it was asked for.
 */
function primaryDatabaseDefault(ConfigData $config, string $fallback = 'sqlite'): string
{
    $probe = new class
    {
        use GathersInfrastructureConfig;

        public function ask(ConfigData $config, string $fallback): string
        {
            return $this->primaryDatabaseDefault($config, $fallback);
        }
    };

    return $probe->ask($config, $fallback);
}

test('a database named by a flag leads the question, not the engine default', function (): void {
    $config = (new ConfigData)->addDatabase(DatabaseDriver::POSTGRESQL);

    expect(primaryDatabaseDefault($config))->toBe('postgres');
});

test('an explicitly chosen primary database wins over a flagged extra', function (): void {
    $config = (new ConfigData)->setDatabase(DatabaseDriver::MYSQL)->addDatabase(DatabaseDriver::POSTGRESQL);

    expect(primaryDatabaseDefault($config))->toBe('mysql');
});

test('with nothing named, the engine default applies', function (): void {
    expect(primaryDatabaseDefault(new ConfigData, 'sqlite'))->toBe('sqlite');
});
