<?php

use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

test('data:wire injects pocketbase url into env file', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();
    file_put_contents("{$tempDir}/.env", "APP_NAME=TestApp\n");

    $oldCwd = getcwd();
    chdir($tempDir);

    try {
        Process::fake([
            '*get secret*' => Process::result(output: base64_encode('data.dev.test')),
            '*' => Process::result(output: 'data.dev.test'),
        ]);

        $this->artisan('data:wire local --engine=pocketbase')
            ->assertExitCode(0)
            ->expectsOutputToContain('Wired PocketBase API URL');

        $envContent = file_get_contents("{$tempDir}/.env");
        expect($envContent)->toContain('VITE_POCKETBASE_URL=https://data.')
            ->toContain('PUBLIC_POCKETBASE_URL=https://data.');
    } finally {
        chdir($oldCwd);
        $temporaryDirectory->delete();
    }
});

test('data:wire injects directus url into env file', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();
    file_put_contents("{$tempDir}/.env", "APP_NAME=TestApp\n");

    $oldCwd = getcwd();
    chdir($tempDir);

    try {
        Process::fake([
            '*get secret*' => Process::result(output: base64_encode('data.dev.test')),
            '*' => Process::result(output: 'data.dev.test'),
        ]);

        $this->artisan('data:wire local --engine=directus')
            ->assertExitCode(0)
            ->expectsOutputToContain('Wired Directus API URL');

        $envContent = file_get_contents("{$tempDir}/.env");
        expect($envContent)->toContain('VITE_DIRECTUS_URL=https://data.');
    } finally {
        chdir($oldCwd);
        $temporaryDirectory->delete();
    }
});

test('data:wire creates the env file when the project has none', function (): void {
    // Both tests above pre-create .env, which is exactly why this went
    // unnoticed: syncEnvFile() returns early when the local .env is absent, so
    // on a fresh SPA (which needs no .env until it has a backend) data:wire
    // printed a success message and wrote nothing at all.
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();

    $oldCwd = getcwd();
    chdir($tempDir);

    try {
        Process::fake([
            '*get secret*' => Process::result(output: base64_encode('data.dev.test')),
            '*' => Process::result(output: 'data.dev.test'),
        ]);

        expect(file_exists("{$tempDir}/.env"))->toBeFalse();

        $this->artisan('data:wire local --engine=pocketbase')
            ->assertExitCode(0);

        expect(file_exists("{$tempDir}/.env"))->toBeTrue()
            ->and(file_get_contents("{$tempDir}/.env"))
            ->toContain('VITE_POCKETBASE_URL=https://data.');
    } finally {
        chdir($oldCwd);
        $temporaryDirectory->delete();
    }
});

test('data:wire emits only the prefix the project framework actually reads', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();
    file_put_contents("{$tempDir}/.larakube.json", (string) json_encode([
        'id' => 'reactor', 'name' => 'reactor', 'framework' => 'vite',
    ]));

    $oldCwd = getcwd();
    chdir($tempDir);

    try {
        Process::fake([
            '*get secret*' => Process::result(output: base64_encode('data.dev.test')),
            '*' => Process::result(output: 'data.dev.test'),
        ]);

        $this->artisan('data:wire local --engine=pocketbase')->assertExitCode(0);

        $env = file_get_contents("{$tempDir}/.env");

        // A Vite bundle reads VITE_ only; the other three were dead weight.
        expect($env)->toContain('VITE_POCKETBASE_URL=https://data.')
            ->and($env)->toContain('POCKETBASE_URL=https://data.')
            ->and($env)->not->toContain('NEXT_PUBLIC_')
            ->and($env)->not->toContain('ASTRO_');
    } finally {
        chdir($oldCwd);
        $temporaryDirectory->delete();
    }
});
