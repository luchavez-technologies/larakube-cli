<?php

use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Enums\StorageDriver;

/**
 * A Commons-backed component has no manual setup left to do:
 * ensurePlexProvisionedForApp() creates the tenant bucket under
 * plexBucketName($tenant) before the app ever runs. Printing the walkthrough
 * anyway is not merely noise — it names a bucket ("laravel") the app is not
 * wired to, so following it leaves a stray, unused bucket behind.
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
