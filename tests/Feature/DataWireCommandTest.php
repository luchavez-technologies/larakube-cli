<?php

use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * data:wire now picks from the cluster registry instead of prompting for a
 * hostname, so a registered instance is what makes the command resolvable.
 */
function dataWireRegistry(): string
{
    return base64_encode((string) json_encode([
        ['tool' => 'data', 'instance' => 'data-dev-test', 'host' => 'data.dev.test', 'engine' => 'pocketbase'],
    ]));
}

test('data:wire injects pocketbase url into env file', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();
    file_put_contents("{$tempDir}/.env", "APP_NAME=TestApp\n");

    $oldCwd = getcwd();
    chdir($tempDir);

    try {
        Process::fake([
            '*get secret larakube-tools-registry*' => Process::result(output: dataWireRegistry()),
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
            '*get secret larakube-tools-registry*' => Process::result(output: dataWireRegistry()),
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
            '*get secret larakube-tools-registry*' => Process::result(output: dataWireRegistry()),
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
            '*get secret larakube-tools-registry*' => Process::result(output: dataWireRegistry()),
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

test('data:wire refuses to leave the env file committable', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();
    file_put_contents("{$tempDir}/.larakube.json", (string) json_encode([
        'id' => 'reactor', 'name' => 'reactor', 'framework' => 'vite',
    ]));
    // Exactly the state that bit us: `larakube env` had added `.env.*`, which
    // ignores .env.production but NOT the base .env the command is about to
    // create — and the old str_contains() dedupe saw `.env.*` and skipped it.
    file_put_contents("{$tempDir}/.gitignore", "node_modules\ndist\n.env.*\n");

    $oldCwd = getcwd();
    chdir($tempDir);

    try {
        Process::fake([
            '*get secret larakube-tools-registry*' => Process::result(output: dataWireRegistry()),
            '*get secret*' => Process::result(output: base64_encode('data.dev.test')),
            '*' => Process::result(output: 'data.dev.test'),
        ]);

        $this->artisan('data:wire local --engine=pocketbase')->assertExitCode(0);

        $lines = array_map('trim', explode("\n", file_get_contents("{$tempDir}/.gitignore")));

        expect($lines)->toContain('.env')
            ->and($lines)->toContain('.env.*');
    } finally {
        chdir($oldCwd);
        $temporaryDirectory->delete();
    }
});

test('data:wire takes the engine from the registry rather than asking', function (): void {
    // The contradiction this removes: you answered "PocketBase" to the engine
    // prompt and were then asked what host *Directus* should use — because the
    // host prompt fell back to SharedClusterService::DATA->label(), which is
    // 'Directus', instead of the engine you had just chosen.
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();
    file_put_contents("{$tempDir}/.larakube.json", (string) json_encode([
        'id' => 'reactor', 'name' => 'reactor', 'framework' => 'vite',
    ]));

    $oldCwd = getcwd();
    chdir($tempDir);

    try {
        Process::fake([
            '*get secret larakube-tools-registry*' => Process::result(output: base64_encode((string) json_encode([
                ['tool' => 'data', 'instance' => 'cms-example-com', 'host' => 'cms.example.com', 'engine' => 'directus'],
            ]))),
            '*get secret*' => Process::result(output: base64_encode('cms.example.com')),
            '*' => Process::result(output: 'cms.example.com'),
        ]);

        // No --engine passed: it must come from the instance, not a prompt.
        $this->artisan('data:wire local --domain=cms.example.com')->assertExitCode(0);

        expect(file_get_contents("{$tempDir}/.env"))
            ->toContain('VITE_DIRECTUS_URL=https://cms.example.com')
            ->not->toContain('POCKETBASE');
    } finally {
        chdir($oldCwd);
        $temporaryDirectory->delete();
    }
});
