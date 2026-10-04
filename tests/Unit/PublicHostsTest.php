<?php

use App\Data\ConfigData;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/** A project folder with a saved blueprint whose local environment has public names. */
function publicHostsProject(array $names): string
{
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed()->path();
    $config = new ConfigData(name: 'shop');
    $config->setPath($dir);
    $config->addEnvironment('local');
    $config->getEnvironment('local')->publicHosts = $names;
    $config->getEnvironment('local')->hosts = array_merge($config->getEnvironment('local')->hosts, $names);
    $config->saveToFile($dir);

    return $dir;
}

test('public names are kept in the local file, never in the committed blueprint', function (): void {
    $dir = publicHostsProject(['web' => 'shop-box.example.com', 'vite' => 'vite-shop-box.example.com']);

    $blueprint = (string) file_get_contents($dir.'/.larakube.json');
    $local = json_decode((string) file_get_contents($dir.'/.larakube.local.json'), true);

    expect($blueprint)->not->toContain('example.com')
        ->and($local['environments']['local']['publicHosts'])->toBe(['web' => 'shop-box.example.com', 'vite' => 'vite-shop-box.example.com']);
});

test('a loaded project uses its public names wherever it builds a host or an address', function (): void {
    $dir = publicHostsProject(['web' => 'shop-box.example.com', 'vite' => 'vite-shop-box.example.com', 'reverb' => 'ws-shop-box.example.com']);

    $config = ConfigData::loadFromFile($dir);

    expect($config->getWebHost('local'))->toBe('shop-box.example.com')
        ->and($config->getServiceHost('vite', 'local'))->toBe('vite-shop-box.example.com')
        ->and($config->getServiceHost('reverb', 'local'))->toBe('ws-shop-box.example.com')
        ->and($config->getAppUrl('local'))->toContain('shop-box.example.com')
        ->and($config->getWebHost('production'))->not->toBe('shop-box.example.com');
});

test('removing the public names takes them out of the local file and puts the local hosts back', function (): void {
    $dir = publicHostsProject(['web' => 'shop-box.example.com']);

    $config = ConfigData::loadFromFile($dir);
    $env = $config->getEnvironment('local');
    $env->hosts = array_diff_key($env->hosts, $env->publicHosts);
    $env->publicHosts = [];
    $config->saveToFile($dir);

    $local = json_decode((string) file_get_contents($dir.'/.larakube.local.json'), true);

    expect($local['environments'] ?? [])->not->toHaveKey('local')
        ->and(ConfigData::loadFromFile($dir)->getWebHost('local'))->toBe('shop.'.App\Data\GlobalConfigData::load()->getLocalTld());
});
