<?php

use App\Data\GlobalConfigData;

function globalConfigMergeSeed(array $data): string
{
    $path = home_path('.larakube/config.json');
    @mkdir(dirname($path), 0700, true);
    file_put_contents($path, json_encode($data));

    return $path;
}

function globalConfigMergeRead(string $path): array
{
    return json_decode((string) file_get_contents($path), true);
}

test('a copy held while another process saves keeps that process\'s change to a different key', function (): void {
    $path = globalConfigMergeSeed(['email' => 'dev@example.com', 'localTld' => 'test']);

    $longRunning = GlobalConfigData::load();
    $other = GlobalConfigData::load();
    $other->setLocalTld('kube');
    $other->save();

    $longRunning->setEmail('new@example.com');
    $longRunning->save();

    expect(globalConfigMergeRead($path))->toMatchArray(['email' => 'new@example.com', 'localTld' => 'kube']);
});

test('two copies adding different servers both keep theirs, even into a map that started empty', function (): void {
    $path = globalConfigMergeSeed(['stacks' => new stdClass]);

    $windows = GlobalConfigData::load();
    $wsl = GlobalConfigData::load();

    $windows->stacks['web-1'] = ['name' => 'web-1'];
    $windows->save();

    $wsl->stacks['web-2'] = ['name' => 'web-2'];
    $wsl->save();

    expect(array_keys(globalConfigMergeRead($path)['stacks']))->toEqualCanonicalizing(['web-1', 'web-2']);
});

test('a server one copy removes stays removed, and another copy\'s server edit survives', function (): void {
    $path = globalConfigMergeSeed(['stacks' => ['old' => ['name' => 'old'], 'web' => ['name' => 'web', 'ip' => '203.0.113.1']]]);

    $remover = GlobalConfigData::load();
    $editor = GlobalConfigData::load();

    $editor->stacks['web']['ip'] = '203.0.113.2';
    $editor->save();

    unset($remover->stacks['old']);
    $remover->save();

    $stacks = globalConfigMergeRead($path)['stacks'];

    expect($stacks)->not->toHaveKey('old')
        ->and($stacks['web']['ip'])->toBe('203.0.113.2');
});

test('a key the copy never touched is not reset to what it held when it loaded', function (): void {
    $path = globalConfigMergeSeed(['email' => 'dev@example.com', 'latestVersion' => 'v0.40.0']);

    $longRunning = GlobalConfigData::load();
    $other = GlobalConfigData::load();
    $other->latestVersion = 'v0.41.0';
    $other->save();

    $longRunning->save();

    expect(globalConfigMergeRead($path)['latestVersion'])->toBe('v0.41.0');
});

test('a list is replaced whole when the copy changed it', function (): void {
    $path = globalConfigMergeSeed(['cloudAccounts' => ['do' => [['id' => 'do-1', 'name' => 'Main', 'token' => 't1', 'default' => true, 'createdAt' => 'x']]]]);

    $config = GlobalConfigData::load();
    $config->cloudAccounts['do'] = [];
    $config->save();

    expect(globalConfigMergeRead($path)['cloudAccounts']['do'])->toBe([]);
});

test('a server another process removed is not brought back by a copy that never touched it', function (): void {
    $path = globalConfigMergeSeed(['email' => 'dev@example.com', 'stacks' => ['old' => ['name' => 'old'], 'web' => ['name' => 'web']]]);

    $longRunning = GlobalConfigData::load();
    $destroyer = GlobalConfigData::load();
    unset($destroyer->stacks['old']);
    $destroyer->save();

    $longRunning->setEmail('new@example.com');
    $longRunning->stacks['web']['ip'] = '203.0.113.9';
    $longRunning->save();

    $saved = globalConfigMergeRead($path);

    expect($saved['stacks'])->not->toHaveKey('old')
        ->and($saved['stacks']['web']['ip'])->toBe('203.0.113.9')
        ->and($saved['email'])->toBe('new@example.com');
});
