<?php

use Illuminate\Support\Facades\Artisan;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function configTldChdir(string $dir): string
{
    $original = getcwd();
    chdir($dir);

    return $original;
}

test('config:tld status shows only the global TLD outside a project', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();
    $original = configTldChdir($tempDir);

    try {
        Artisan::call('config:tld');
        $output = Artisan::output();
    } finally {
        chdir($original);
        $temporaryDirectory->delete();
    }

    expect($output)->toContain('Local TLD:')
        ->and($output)->not->toContain("project's TLD");
});

test('config:tld status shows the project TLD following the global default', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();
    file_put_contents($tempDir.'/.larakube.json', json_encode(['name' => 'demo']));
    $original = configTldChdir($tempDir);

    try {
        Artisan::call('config:tld');
        $output = Artisan::output();
    } finally {
        chdir($original);
        $temporaryDirectory->delete();
    }

    expect($output)->toContain('Global Local TLD:')
        ->and($output)->toContain("This project's TLD:")
        ->and($output)->toContain('follows the global default');
});

test('config:tld status shows a pinned project TLD override', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();
    file_put_contents($tempDir.'/.larakube.json', json_encode(['name' => 'demo', 'localTld' => 'test']));
    $original = configTldChdir($tempDir);

    try {
        Artisan::call('config:tld');
        $output = Artisan::output();
    } finally {
        chdir($original);
        $temporaryDirectory->delete();
    }

    expect($output)->toContain("This project's TLD:")
        ->and($output)->toContain('.test')
        ->and($output)->toContain('pinned override');
});
