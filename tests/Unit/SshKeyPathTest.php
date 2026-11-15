<?php

use App\Support\Os;
use App\Support\SshKeyPath;
use Spatie\TemporaryDirectory\TemporaryDirectory;

afterEach(function (): void {
    Os::setForcedFamily(null);
});

function sshKeyPathMount(): array
{
    $mount = TemporaryDirectory::make()->deleteWhenDestroyed();
    @mkdir($mount->path().'/c/Users/you/.ssh', 0755, true);
    $key = $mount->path().'/c/Users/you/.ssh/id_ed25519';
    file_put_contents($key, "PRIVATE KEY\n");

    return [$mount, $key];
}

test('a native path is left alone', function (): void {
    Os::setForcedFamily('Linux');

    expect(SshKeyPath::forThisSide('/home/larakube/.ssh/id_ed25519'))->toBe('/home/larakube/.ssh/id_ed25519');
});

test('on Windows itself a Windows key path is used as is', function (): void {
    Os::setForcedFamily('Windows');

    expect(SshKeyPath::forThisSide('C:\\Users\\you\\.ssh\\id_ed25519'))->toBe('C:\\Users\\you\\.ssh\\id_ed25519');
});

test('inside the distro a Windows key path becomes its /mnt path, made private for ssh', function (): void {
    Os::setForcedFamily('Linux');
    [$mount, $key] = sshKeyPathMount();
    chmod($key, 0644);

    expect(SshKeyPath::forThisSide('C:\\Users\\you\\.ssh\\id_ed25519', $mount->path()))->toBe($key)
        ->and(fileperms($key) & 0777)->toBe(0600);
});

test('a Windows key path that is not there is returned unchanged, for ssh to report', function (): void {
    Os::setForcedFamily('Linux');
    [$mount] = sshKeyPathMount();

    expect(SshKeyPath::forThisSide('D:\\keys\\missing', $mount->path()))->toBe('D:\\keys\\missing');
});
