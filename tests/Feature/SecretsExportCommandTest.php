<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use Spatie\TemporaryDirectory\TemporaryDirectory;

Prompt::interactive(false);

test('secrets:export exports all environments and secrets to a JSON file', function (): void {
    Process::fake([
        '*' => Process::result(output: base64_encode('hvs.root_token_test')),
    ]);

    Http::fake([
        'localhost:*' => Http::sequence()
            ->push(['data' => ['keys' => ['production/']]])
            ->push(['data' => ['keys' => ['APP_KEY']]])
            ->push(['data' => ['data' => ['value' => 'base64:abc123']]]),
    ]);

    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $output = $temporaryDirectory->path('larakube_export_test.json');

    $this->artisan("secrets:export local --output={$output} --no-interaction")
        ->assertExitCode(0)
        ->expectsOutputToContain('Exported');

    if (file_exists($output)) {
        $data = json_decode((string) file_get_contents($output), true);
        expect($data)->toHaveKey('environments')
            ->and($data['environments'])->toHaveKey('production');
    }

    $temporaryDirectory->delete();
});

test('secrets:export fails when openbao is not bootstrapped', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('secrets:export local --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('not bootstrapped');
});
