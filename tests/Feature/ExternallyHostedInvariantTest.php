<?php

use App\Data\ConfigData;
use App\Data\EnvironmentData;

/**
 * `plex` and `managed` are written by different commands and nothing enforces
 * plex ⊆ managed at write time. Every decision about deployment SHAPE must
 * therefore read the union — reading `managed` alone is what kept a
 * Plex-joined project deploying its own Postgres/Redis/SeaweedFS pods.
 */
function externallyHostedConfig(array $managed, array $plex): ConfigData
{
    $config = new ConfigData;
    $config->setName('hello-app');
    $config->addEnvironment('local');
    $config->environments['local'] = new EnvironmentData(managed: $managed, plex: $plex);

    return $config;
}

test('a Plex-joined service counts as externally hosted even when managed is empty', function (): void {
    $config = externallyHostedConfig(managed: [], plex: ['postgres', 'redis']);

    expect($config->getExternallyHosted('local'))->toEqualCanonicalizing(['postgres', 'redis'])
        // …while the strict list stays strict, for the data-safety guards.
        ->and($config->getManaged('local'))->toBeEmpty();
});

test('an externally managed service (AWS RDS) still counts, with no Plex at all', function (): void {
    // The original meaning of `managed`: local pod, provider-managed in prod.
    $config = externallyHostedConfig(managed: ['postgres'], plex: []);

    expect($config->getExternallyHosted('local'))->toBe(['postgres']);
});

test('the union does not duplicate a service present in both lists', function (): void {
    $config = externallyHostedConfig(managed: ['redis'], plex: ['redis', 'seaweedfs']);

    expect($config->getExternallyHosted('local'))->toEqualCanonicalizing(['redis', 'seaweedfs']);
});

test('an init container never waits on a service that lives outside the namespace', function (): void {
    // getCoreDependencies() documented "managed/Plex" but read only `managed`,
    // so a Plex-only project would `nc` a postgres pod that does not exist.
    $config = externallyHostedConfig(managed: [], plex: ['postgres']);
    $config->database = App\Enums\DatabaseDriver::POSTGRESQL;

    $names = array_map(fn ($d) => $d->value, $config->getCoreDependencies('local'));

    expect($names)->not->toContain('postgres');
});

test('every deployment-shape decision reads the union, not managed alone', function (): void {
    // These sites decide whether a workload/volume manifest is emitted at all.
    $sites = [
        'app/Traits/GeneratesProjectInfrastructure.php',
        'app/Enums/DatabaseDriver.php',
        'app/Enums/StorageDriver.php',
        'app/Enums/SearchDriver.php',
        'app/Enums/LaravelFeature.php',
    ];

    foreach ($sites as $site) {
        expect((string) file_get_contents(base_path($site)))
            ->toContain('getExternallyHosted(');
    }
});

test('the Plex data-safety guards deliberately stay strict', function (): void {
    // Treating "claims plex" as "already joined" would skip the existing-data
    // check and strand a live volume.
    foreach (['app/Commands/Plex/PlexJoinCommand.php', 'app/Commands/Plex/PlexMigrateCommand.php'] as $guard) {
        $source = (string) file_get_contents(base_path($guard));

        expect($source)->toContain('$config->getManaged($env)')
            // The comment there names the accessor, so match the CALL.
            ->and($source)->not->toContain('$config->getExternallyHosted(');
    }
});
