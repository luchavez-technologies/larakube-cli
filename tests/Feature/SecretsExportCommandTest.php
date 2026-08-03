<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

Prompt::interactive(false);

test('secrets:export exports all environments and secrets to a JSON file', function () {
    Process::fake([
        '*' => Process::result(output: base64_encode('hvs.root_token_test')),
    ]);

    Http::fake([
        'localhost:*' => Http::sequence()
            ->push(['data' => ['keys' => ['production/']]])
            ->push(['data' => ['keys' => ['APP_KEY']]])
            ->push(['data' => ['data' => ['value' => 'base64:abc123']]]),
    ]);

    $output = sys_get_temp_dir().'/larakube_export_test_'.uniqid().'.json';

    $this->artisan("secrets:export local --output={$output} --no-interaction")
        ->assertExitCode(0)
        ->expectsOutputToContain('Exported');

    if (file_exists($output)) {
        $data = json_decode((string) file_get_contents($output), true);
        expect($data)->toHaveKey('environments');
        expect($data['environments'])->toHaveKey('production');
    }

    @unlink($output);
});

test('secrets:export fails when openbao is not bootstrapped', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('secrets:export local --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('not bootstrapped');
});
