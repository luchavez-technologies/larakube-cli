<?php

use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Enums\StorageDriver;

/**
 * A Commons-backed component has no manual setup left: plex:join already
 * created its bucket, so the walkthrough would name one the app never uses.
 */
function plexBackedConfig(array $plex): ConfigData
{
    $config = new ConfigData;
    $config->setName('hello-next');
    $config->addEnvironment('local');
    $config->environments['local'] = new EnvironmentData(plex: $plex);

    return $config;
}

test('a Commons-backed storage driver reports as Plex-backed', function (): void {
    $config = plexBackedConfig(['postgres', 'redis', 'seaweedfs']);

    expect($config->isPlexBacked(StorageDriver::SEAWEEDFS, 'local'))->toBeTrue();
});

test('the same driver is NOT Plex-backed when self-hosted', function (): void {
    // --no-plex: the project runs its own pod, so the one-time bucket steps
    // are genuinely required and must still print.
    $config = plexBackedConfig(['postgres']);

    expect($config->isPlexBacked(StorageDriver::SEAWEEDFS, 'local'))->toBeFalse();
});

test('every path that prints one-time steps filters Plex-backed components', function (): void {
    // The loop exists in four places (the shared printer plus new/init/add);
    // all four must carry the guard or the misleading walkthrough comes back
    // through whichever one was missed.
    $sites = [
        'app/Traits/LaraKubeOutput.php',
        'app/Commands/NewCommand.php',
        'app/Commands/InitCommand.php',
        'app/Commands/AddCommand.php',
    ];

    foreach ($sites as $site) {
        $source = (string) file_get_contents(base_path($site));

        expect($source)->toContain('getPostInstallInstructions')
            ->and($source)->toContain('isPlexBacked');
    }
});
