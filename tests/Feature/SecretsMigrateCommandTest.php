<?php

use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

Prompt::interactive(false);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('secrets:migrate dry-run shows diff without writing', function (): void {
    Process::fake([
        '*' => Process::result(output: base64_encode('hvs.root_token_test')),
    ]);

    Saloon::fake([
        // export: list metadata mounts
        MockResponse::make(['data' => ['keys' => ['production/']]]),
        // export: list keys in production
        MockResponse::make(['data' => ['keys' => ['APP_KEY']]]),
        // export: read key value
        MockResponse::make(['data' => ['data' => ['value' => 'abc']]]),
    ]);

    $this->artisan('secrets:migrate local --dry-run --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('APP_KEY')
        ->expectsOutputToContain('Dry-run complete');
});

test('secrets:migrate orchestrates export then import', function (): void {
    Process::fake([
        '*' => Process::result(output: base64_encode('hvs.root_token_test')),
    ]);

    Saloon::fake([
        // export: list metadata mounts
        MockResponse::make(['data' => ['keys' => ['production/']]]),
        // export: list keys in production
        MockResponse::make(['data' => ['keys' => ['APP_KEY']]]),
        // export: read key value
        MockResponse::make(['data' => ['data' => ['value' => 'abc']]]),
        // import: GET /v1/sys/init → initialized
        MockResponse::make(['initialized' => true]),
        // import: GET /v1/sys/seal-status → unsealed
        MockResponse::make(['sealed' => false]),
        // import: POST /v1/sys/mounts/secret
        MockResponse::make([]),
        // import: POST /v1/secret/data/production/APP_KEY
        MockResponse::make(['data' => []]),
    ]);

    $this->artisan('secrets:migrate local --force --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Migration from openbao → openbao complete');
});
