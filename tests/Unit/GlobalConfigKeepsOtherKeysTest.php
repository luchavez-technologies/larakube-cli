<?php

use App\Data\GlobalConfigData;

test('saving the global config keeps the keys LaraKube Desktop stores in the same file', function (): void {
    $path = home_path('.larakube/config.json');
    @mkdir(dirname($path), 0700, true);
    file_put_contents($path, json_encode(['email' => 'dev@example.com', 'experimental' => true, 'hideProjects' => true, 'cliChannel' => 'stable', 'usage' => 'apps']));

    $config = GlobalConfigData::load();
    $config->setLocalTld('test');
    $config->save();

    $saved = json_decode((string) file_get_contents($path), true);

    expect($saved)->toMatchArray(['experimental' => true, 'hideProjects' => true, 'cliChannel' => 'stable', 'usage' => 'apps', 'localTld' => 'test', 'email' => 'dev@example.com']);
});

test('a key the CLI owns is still replaced when it changes', function (): void {
    $path = home_path('.larakube/config.json');
    @mkdir(dirname($path), 0700, true);
    file_put_contents($path, json_encode(['email' => 'old@example.com']));

    $config = GlobalConfigData::load();
    $config->setEmail('new@example.com');
    $config->save();

    expect(json_decode((string) file_get_contents($path), true)['email'])->toBe('new@example.com');
});
