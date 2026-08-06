<?php

use Illuminate\Support\Facades\Process;

test('data:wire injects pocketbase url into env file', function () {
    $tempDir = sys_get_temp_dir().'/test-data-wire-'.uniqid();
    mkdir($tempDir);
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
        expect($envContent)->toContain('VITE_POCKETBASE_URL=https://data.');
        expect($envContent)->toContain('PUBLIC_POCKETBASE_URL=https://data.');
    } finally {
        chdir($oldCwd);
        @unlink("{$tempDir}/.env");
        @rmdir($tempDir);
    }
});

test('data:wire injects directus url into env file', function () {
    $tempDir = sys_get_temp_dir().'/test-data-wire-dir-'.uniqid();
    mkdir($tempDir);
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
        @unlink("{$tempDir}/.env");
        @rmdir($tempDir);
    }
});
