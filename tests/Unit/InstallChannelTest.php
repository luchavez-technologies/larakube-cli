<?php

use App\Enums\InstallChannel;
use Spatie\TemporaryDirectory\TemporaryDirectory;

test('Homebrew is recognised by prefix and by a symlink into the Cellar or Caskroom', function (): void {
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    mkdir($dir->path().'/usr/local/Cellar/k9s/0.50.0/bin', 0755, true);
    touch($dir->path().'/usr/local/Cellar/k9s/0.50.0/bin/k9s');
    mkdir($dir->path().'/usr/local/bin', 0755, true);
    symlink($dir->path().'/usr/local/Cellar/k9s/0.50.0/bin/k9s', $dir->path().'/usr/local/bin/k9s');

    expect(InstallChannel::detect('/opt/homebrew/bin/k9s', '/home/me/.larakube/bin'))->toBe(InstallChannel::Homebrew)
        ->and(InstallChannel::detect('/home/linuxbrew/.linuxbrew/bin/gh', '/home/me/.larakube/bin'))->toBe(InstallChannel::Homebrew)
        // An Intel Mac's brew links into /usr/local/bin; only the link target says Homebrew.
        ->and(InstallChannel::detect($dir->path().'/usr/local/bin/k9s', '/home/me/.larakube/bin'))->toBe(InstallChannel::Homebrew);
});

test('OS package-manager paths are System, never managed by the CLI', function (): void {
    expect(InstallChannel::detect('/usr/bin/kubectl', '/home/me/.larakube/bin'))->toBe(InstallChannel::System)
        ->and(InstallChannel::detect('/snap/bin/aws', '/home/me/.larakube/bin'))->toBe(InstallChannel::System)
        ->and(InstallChannel::System->isManagedByCli())->toBeFalse();
});

test('binDir, any .larakube path, and a tool\'s own markers are LaraKube-managed, including Windows paths', function (): void {
    expect(InstallChannel::detect('/custom/bin/kubectl', '/custom/bin'))->toBe(InstallChannel::LaraKube)
        ->and(InstallChannel::detect('/Users/dev/.larakube/bin/tofu', '/elsewhere'))->toBe(InstallChannel::LaraKube)
        ->and(InstallChannel::detect('/Users/dev/google-cloud-sdk/bin/gcloud', '/elsewhere', ['/google-cloud-sdk/']))->toBe(InstallChannel::LaraKube)
        ->and(InstallChannel::detect('C:\\Users\\dev\\AppData\\Local\\Programs\\Amazon\\AWSCLIV2\\aws.exe', 'C:\\Users\\dev\\.larakube\\bin', ['/Programs/Amazon/AWSCLIV2/']))->toBe(InstallChannel::LaraKube)
        ->and(InstallChannel::detect('C:\\Users\\dev\\.larakube\\bin\\kubectl.exe', 'C:\\Users\\dev\\.larakube\\bin'))->toBe(InstallChannel::LaraKube);
});

test('anything else, like OrbStack\'s bundled kubectl, is Other', function (): void {
    expect(InstallChannel::detect('/usr/local/bin/kubectl', '/home/me/.larakube/bin'))->toBe(InstallChannel::Other)
        ->and(InstallChannel::Other->isManagedByCli())->toBeFalse();
});
