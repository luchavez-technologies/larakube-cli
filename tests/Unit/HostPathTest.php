<?php

use App\Data\GlobalConfigData;
use App\Support\HostPath;
use App\Support\Os;
use Spatie\TemporaryDirectory\TemporaryDirectory;

afterEach(function (): void {
    Os::setForcedFamily(null);
});

test('a Windows drive path and its /mnt form translate both ways', function (): void {
    expect(HostPath::toWsl('C:\\Users\\you\\key.json'))->toBe('/mnt/c/Users/you/key.json')
        ->and(HostPath::toWindows('/mnt/c/Users/you/key.json'))->toBe('C:\\Users\\you\\key.json')
        ->and(HostPath::toWsl('/home/larakube/key.json'))->toBeNull()
        ->and(HostPath::toWindows('/home/larakube/key.json'))->toBeNull();
});

test('inside LaraKube Linux a Windows path becomes its /mnt path when the file is there', function (): void {
    Os::setForcedFamily('Linux');
    $mount = TemporaryDirectory::make()->deleteWhenDestroyed();
    @mkdir($mount->path().'/c/Users/you', 0755, true);
    file_put_contents($mount->path().'/c/Users/you/key.json', '{}');

    expect(HostPath::forThisSide('C:\\Users\\you\\key.json', $mount->path()))->toBe($mount->path().'/c/Users/you/key.json')
        ->and(HostPath::forThisSide('D:\\missing.json', $mount->path()))->toBe('D:\\missing.json');
});

test('inline JSON credentials and native paths are left alone', function (): void {
    Os::setForcedFamily('Linux');

    expect(HostPath::forThisSide('{"type":"service_account"}'))->toBe('{"type":"service_account"}')
        ->and(HostPath::forThisSide('/home/larakube/key.json'))->toBe('/home/larakube/key.json');
});

test('the shared config hands back the Google Cloud key file in this side\'s form', function (): void {
    Os::setForcedFamily('Linux');
    $config = new GlobalConfigData(gcpCredentials: 'C:\\Users\\you\\key.json');

    // No /mnt/c here, so the stored value comes back unchanged for the caller's own not-found handling.
    expect($config->getGcpCredentials())->toBe('C:\\Users\\you\\key.json')
        ->and($config->gcpCredentials)->toBe('C:\\Users\\you\\key.json');
});
