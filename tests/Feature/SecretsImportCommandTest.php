<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

Prompt::interactive(false);

// ── Helpers ────────────────────────────────────────────────────────────────

function makeExportFile(array $environments = []): string
{
    $path = sys_get_temp_dir().'/larakube_import_test_'.uniqid().'.json';
    file_put_contents($path, json_encode([
        'engine' => 'openbao',
        'exported_at' => now()->toIso8601String(),
        'environments' => $environments,
    ]));

    return $path;
}

// ── Tests ──────────────────────────────────────────────────────────────────

test('secrets:import initializes and unseals openbao, then writes secrets', function () {
    $input = makeExportFile([
        'production' => [
            'APP_KEY' => 'base64:abc123',
            'DB_PASSWORD' => 's3cret',
        ],
    ]);

    Process::fake([
        '*port-forward*' => Process::result(),
        '*apply -f *' => Process::result(output: 'secret/openbao-bootstrap created'),
        '*' => Process::result(),
    ]);

    Http::fake([
        'localhost:*' => Http::sequence()
            // GET /v1/sys/init → not initialized
            ->push(['initialized' => false])
            // POST /v1/sys/init
            ->push(['root_token' => 'hvs.root', 'keys' => ['unseal-key-abc']])
            // POST /v1/sys/unseal
            ->push(['sealed' => false])
            // POST /v1/sys/mounts/secret (enable KV)
            ->push([])
            // POST /v1/secret/data/production/APP_KEY
            ->push(['data' => ['created_time' => now()->toIso8601String()]])
            // POST /v1/secret/data/production/DB_PASSWORD
            ->push(['data' => ['created_time' => now()->toIso8601String()]]),
    ]);

    $this->artisan("secrets:import local --engine=openbao --input={$input} --force --no-interaction")
        ->assertExitCode(0)
        ->expectsOutputToContain('Imported 2 secret(s)');

    @unlink($input);
});

test('secrets:import unseals an already-initialized but sealed openbao', function () {
    $input = makeExportFile(['production' => ['APP_KEY' => 'abc']]);

    Process::fake([
        '*port-forward*' => Process::result(),
        '*jsonpath*root-token*' => Process::result(output: base64_encode('hvs.existing')),
        '*jsonpath*unseal-key*' => Process::result(output: base64_encode('existing-unseal-key')),
        '*' => Process::result(),
    ]);

    Http::fake([
        'localhost:*' => Http::sequence()
            // GET /v1/sys/init → already initialized
            ->push(['initialized' => true])
            // GET /v1/sys/seal-status → sealed
            ->push(['sealed' => true])
            // POST /v1/sys/unseal
            ->push(['sealed' => false])
            // POST /v1/sys/mounts/secret
            ->push([])
            // POST /v1/secret/data/production/APP_KEY
            ->push(['data' => []]),
    ]);

    $this->artisan("secrets:import local --engine=openbao --input={$input} --force --no-interaction")
        ->assertExitCode(0)
        ->expectsOutputToContain('Imported 1 secret(s)');

    @unlink($input);
});

test('secrets:import fails when input file does not exist', function () {
    Process::fake(['*' => Process::result()]);

    $this->artisan('secrets:import local --input=/nonexistent/file.json --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('Export file not found');
});
