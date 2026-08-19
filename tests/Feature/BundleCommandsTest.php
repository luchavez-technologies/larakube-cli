<?php

use Spatie\TemporaryDirectory\TemporaryDirectory;

test('bundle:zip compresses a directory into a tarball and bundle:unzip extracts it', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tmpDir = $temporaryDirectory->path();
    mkdir("$tmpDir/dist", 0755, true);
    $bundleDir = "$tmpDir/dist/test-bundle";
    mkdir($bundleDir, 0755, true);

    // Create a mock bundle.json and .env
    file_put_contents("$bundleDir/bundle.json", '{"name":"test"}');
    file_put_contents("$bundleDir/.env", 'TEST_KEY=123');

    $originalCwd = getcwd();
    chdir($tmpDir);

    try {
        // Test bundle:zip
        $this->artisan('bundle:zip', [
            'path' => 'dist/test-bundle',
            '--output' => 'dist/my-bundle',
            '--delete' => true,
        ])->assertExitCode(0);

        // Verify the original directory was deleted (--delete)
        expect($bundleDir)->not->toBeDirectory();
        // Verify the tarball was created with the custom output name
        expect(file_exists("$tmpDir/dist/my-bundle.tar.gz"))->toBeTrue();

        // Test bundle:unzip
        $this->artisan('bundle:unzip', [
            'path' => 'dist/my-bundle.tar.gz',
            '--delete' => true,
        ])->assertExitCode(0);

        // The tarball contained the folder 'test-bundle', so it should be recreated
        expect($bundleDir)->toBeDirectory();
        expect(file_get_contents("$bundleDir/.env"))->toBe('TEST_KEY=123');
        // The archive should be deleted because of --delete
        expect(file_exists("$tmpDir/dist/my-bundle.tar.gz"))->toBeFalse();

    } finally {
        chdir($originalCwd);
        $temporaryDirectory->delete();
    }
});
