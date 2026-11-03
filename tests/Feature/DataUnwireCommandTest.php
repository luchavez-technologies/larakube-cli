<?php

use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function dataUnwireRegistry(): string
{
    return base64_encode((string) json_encode([
        ['tool' => 'pocketbase', 'instance' => 'data-dev-test', 'host' => 'data.dev.test', 'engine' => 'pocketbase'],
    ]));
}

test('data:unwire removes pocketbase url variables from env file', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();
    file_put_contents("{$tempDir}/.env", "APP_NAME=TestApp\nPOCKETBASE_URL=https://data.dev.test\nVITE_POCKETBASE_URL=https://data.dev.test\n");

    $oldCwd = getcwd();
    chdir($tempDir);

    try {
        Process::fake([
            '*get secret larakube-tools-registry*' => Process::result(output: dataUnwireRegistry()),
            '*get secret*' => Process::result(output: base64_encode('data.dev.test')),
            '*' => Process::result(output: 'data.dev.test'),
        ]);

        $this->artisan('data:unwire local --engine=pocketbase --force')
            ->assertExitCode(0)
            ->expectsOutputToContain('Removed PocketBase API URL variables');

        $envContent = file_get_contents("{$tempDir}/.env");
        expect($envContent)->not->toContain('POCKETBASE_URL')
            ->toContain('APP_NAME=TestApp');
    } finally {
        chdir($oldCwd);
        $temporaryDirectory->delete();
    }
});

test('data:unwire warns when env file does not exist', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();

    $oldCwd = getcwd();
    chdir($tempDir);

    try {
        Process::fake([
            '*get secret larakube-tools-registry*' => Process::result(output: dataUnwireRegistry()),
            '*get secret*' => Process::result(output: base64_encode('data.dev.test')),
            '*' => Process::result(output: 'data.dev.test'),
        ]);

        $this->artisan('data:unwire local --engine=pocketbase --force')
            ->assertExitCode(0)
            ->expectsOutputToContain('does not exist');
    } finally {
        chdir($oldCwd);
        $temporaryDirectory->delete();
    }
});
