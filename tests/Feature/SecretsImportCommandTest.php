<?php

use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Spatie\TemporaryDirectory\TemporaryDirectory;

Prompt::interactive(false);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

// ── Helpers ────────────────────────────────────────────────────────────────

function makeExportFile(array $environments = []): TemporaryDirectory
{
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    file_put_contents($temporaryDirectory->path('export.json'), json_encode([
        'engine' => 'openbao',
        'exported_at' => now()->toIso8601String(),
        'environments' => $environments,
    ]));

    return $temporaryDirectory;
}

// ── Tests ──────────────────────────────────────────────────────────────────

test('secrets:import initializes and unseals openbao, then writes secrets', function (): void {
    $temporaryDirectory = makeExportFile([
        'production' => [
            'APP_KEY' => 'base64:abc123',
            'DB_PASSWORD' => 's3cret',
        ],
    ]);
    $input = $temporaryDirectory->path('export.json');

    Process::fake([
        '*port-forward*' => Process::result(),
        '*apply -f *' => Process::result(output: 'secret/openbao-bootstrap created'),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        // GET /v1/sys/init → not initialized
        MockResponse::make(['initialized' => false]),
        // POST /v1/sys/init
        MockResponse::make(['root_token' => 'hvs.root', 'keys' => ['unseal-key-abc']]),
        // POST /v1/sys/unseal
        MockResponse::make(['sealed' => false]),
        // POST /v1/sys/mounts/secret (enable KV)
        MockResponse::make([]),
        // POST /v1/secret/data/production/APP_KEY
        MockResponse::make(['data' => ['created_time' => now()->toIso8601String()]]),
        // POST /v1/secret/data/production/DB_PASSWORD
        MockResponse::make(['data' => ['created_time' => now()->toIso8601String()]]),
    ]);

    $this->artisan("secrets:import local --engine=openbao --input={$input} --force --no-interaction")
        ->assertExitCode(0)
        ->expectsOutputToContain('Imported 2 secret(s)');

    $temporaryDirectory->delete();
});

test('secrets:import unseals an already-initialized but sealed openbao', function (): void {
    $temporaryDirectory = makeExportFile(['production' => ['APP_KEY' => 'abc']]);
    $input = $temporaryDirectory->path('export.json');

    Process::fake([
        '*port-forward*' => Process::result(),
        '*jsonpath*root-token*' => Process::result(output: base64_encode('hvs.existing')),
        '*jsonpath*unseal-key*' => Process::result(output: base64_encode('existing-unseal-key')),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        // GET /v1/sys/init → already initialized
        MockResponse::make(['initialized' => true]),
        // GET /v1/sys/seal-status → sealed
        MockResponse::make(['sealed' => true]),
        // POST /v1/sys/unseal
        MockResponse::make(['sealed' => false]),
        // POST /v1/sys/mounts/secret
        MockResponse::make([]),
        // POST /v1/secret/data/production/APP_KEY
        MockResponse::make(['data' => []]),
    ]);

    $this->artisan("secrets:import local --engine=openbao --input={$input} --force --no-interaction")
        ->assertExitCode(0)
        ->expectsOutputToContain('Imported 1 secret(s)');

    $temporaryDirectory->delete();
});

test('secrets:import fails when input file does not exist', function (): void {
    Process::fake(['*' => Process::result()]);

    $this->artisan('secrets:import local --input=/nonexistent/file.json --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('Export file not found');
});
