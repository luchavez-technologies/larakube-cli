<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

Prompt::interactive(false);

test('secrets:migrate dry-run shows diff without writing', function () {
    Process::fake([
        '*' => Process::result(output: base64_encode('hvs.root_token_test')),
    ]);

    Http::fake([
        'localhost:*' => Http::sequence()
            // export: list metadata mounts
            ->push(['data' => ['keys' => ['production/']]])
            // export: list keys in production
            ->push(['data' => ['keys' => ['APP_KEY']]])
            // export: read key value
            ->push(['data' => ['data' => ['value' => 'abc']]]),
    ]);

    $this->artisan('secrets:migrate local --dry-run --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('APP_KEY')
        ->expectsOutputToContain('Dry-run complete');
});

test('secrets:migrate orchestrates export then import', function () {
    Process::fake([
        '*' => Process::result(output: base64_encode('hvs.root_token_test')),
    ]);

    Http::fake([
        'localhost:*' => Http::sequence()
            // export: list metadata mounts
            ->push(['data' => ['keys' => ['production/']]])
            // export: list keys in production
            ->push(['data' => ['keys' => ['APP_KEY']]])
            // export: read key value
            ->push(['data' => ['data' => ['value' => 'abc']]])
            // import: GET /v1/sys/init → initialized
            ->push(['initialized' => true])
            // import: GET /v1/sys/seal-status → unsealed
            ->push(['sealed' => false])
            // import: POST /v1/sys/mounts/secret
            ->push([])
            // import: POST /v1/secret/data/production/APP_KEY
            ->push(['data' => []]),
    ]);

    $this->artisan('secrets:migrate local --force --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Migration from openbao → openbao complete');
});
