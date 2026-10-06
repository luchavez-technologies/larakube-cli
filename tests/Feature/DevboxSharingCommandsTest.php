<?php

use App\Data\GlobalConfigData;
use App\Data\StackData;
use App\Services\Devbox\DevBoxBundle;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use Spatie\TemporaryDirectory\TemporaryDirectory;

beforeEach(function (): void {
    Prompt::interactive(false);
});

function setupMockDevBox(string $name = 'box-alpha'): array
{
    $tempDir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $keyPath = $tempDir->path('key.pem');
    file_put_contents($keyPath, '---FAKE-PRIVATE-KEY---');
    file_put_contents("{$keyPath}.pub", 'ssh-ed25519 AAAAC3FAKE test@larakube');

    $config = GlobalConfigData::load();
    $stack = new StackData(
        name: $name,
        provider: 'do',
        kind: 'vps',
        region: 'sgp1',
        context: null,
        ip: '198.51.100.10',
        sshKey: $keyPath,
        role: 'dev',
    );
    $config->putStack($stack);
    $config->save();

    return [$tempDir, $stack, $keyPath];
}

test('devbox:export exports an encrypted .devbox bundle', function (): void {
    [$tempDir, $stack, $keyPath] = setupMockDevBox('export-box');
    $outputFile = $tempDir->path('export-box.devbox');

    $this->artisan('devbox:export', [
        'name' => 'export-box',
        '--output' => $outputFile,
        '--passphrase' => 'SecretPass123!',
        '--json' => true,
    ])->assertExitCode(0);

    expect(file_exists($outputFile))->toBeTrue();

    $content = (string) file_get_contents($outputFile);
    $decrypted = DevBoxBundle::decrypt($content, 'SecretPass123!');

    expect($decrypted['stack']['name'])->toBe('export-box')
        ->and($decrypted['stack']['ip'])->toBe('198.51.100.10')
        ->and($decrypted['keys']['private'])->toBe('---FAKE-PRIVATE-KEY---');

    $tempDir->delete();
});

test('devbox:export fails when devbox does not exist', function (): void {
    $this->artisan('devbox:export', [
        'name' => 'non-existent-box',
        '--passphrase' => 'secret',
        '--json' => true,
    ])->assertExitCode(1);
});

test('devbox:import successfully decrypts bundle, registers stack and writes key', function (): void {
    $tempDir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $bundleFile = $tempDir->path('import-test.devbox');

    $payload = [
        'version' => 1,
        'stack' => [
            'name' => 'imported-box',
            'provider' => 'hetzner',
            'kind' => 'vps',
            'ip' => '203.0.113.88',
            'role' => 'dev',
        ],
        'keys' => [
            'private' => '---MY-IMPORTED-PRIVATE-KEY---',
            'public' => 'ssh-ed25519 AAAAC3IMPORTED test@imported',
        ],
    ];

    $encrypted = DevBoxBundle::encrypt($payload, 'ImportPassword999!');
    file_put_contents($bundleFile, $encrypted);

    Process::fake();

    $this->artisan('devbox:import', [
        'file' => $bundleFile,
        '--passphrase' => 'ImportPassword999!',
        '--skip-test' => true,
        '--json' => true,
    ])->assertExitCode(0);

    $config = GlobalConfigData::load();
    $importedStack = $config->findStack('imported-box');

    expect($importedStack)->not->toBeNull()
        ->and($importedStack->ip)->toBe('203.0.113.88')
        ->and($importedStack->role)->toBe('dev')
        ->and(file_exists($importedStack->sshKey))->toBeTrue()
        ->and(file_get_contents($importedStack->sshKey))->toBe('---MY-IMPORTED-PRIVATE-KEY---');

    if (file_exists($importedStack->sshKey)) {
        @unlink($importedStack->sshKey);
        @unlink($importedStack->sshKey.'.pub');
    }
    $tempDir->delete();
});

test('devbox:import fails with wrong passphrase', function (): void {
    $tempDir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $bundleFile = $tempDir->path('fail-test.devbox');

    $payload = ['stack' => ['name' => 'x', 'ip' => '1.2.3.4'], 'keys' => ['private' => 'k']];
    file_put_contents($bundleFile, DevBoxBundle::encrypt($payload, 'correct'));

    $this->artisan('devbox:import', [
        'file' => $bundleFile,
        '--passphrase' => 'wrong',
        '--json' => true,
    ])->assertExitCode(1);

    $tempDir->delete();
});

test('devbox:grant fetches keys from GitHub and appends to authorized_keys over SSH', function (): void {
    [$tempDir, $stack, $keyPath] = setupMockDevBox('grant-box');

    Http::fake([
        'https://github.com/alice.keys' => Http::response("ssh-ed25519 AAAAC3ALICE alice@laptop\nssh-rsa AAAAB3ALICE2 alice@work\n", 200),
    ]);

    Process::fake([
        '*ssh*' => Process::result(output: 'success', exitCode: 0),
    ]);

    $this->artisan('devbox:grant', [
        'box' => 'grant-box',
        '--github' => 'alice',
        '--json' => true,
    ])->assertExitCode(0);

    Process::assertRan(function ($process): bool {
        return str_contains($process->command, 'authorized_keys')
            && str_contains($process->command, 'AAAAC3ALICE');
    });

    $tempDir->delete();
});

test('devbox:revoke removes matching keys from authorized_keys over SSH', function (): void {
    [$tempDir, $stack, $keyPath] = setupMockDevBox('revoke-box');

    Process::fake([
        '*ssh*' => Process::result(output: 'success', exitCode: 0),
    ]);

    $this->artisan('devbox:revoke', [
        'box' => 'revoke-box',
        '--github' => 'alice',
        '--json' => true,
    ])->assertExitCode(0);

    Process::assertRan(function ($process): bool {
        return str_contains($process->command, 'authorized_keys')
            && str_contains($process->command, 'github:alice');
    });

    $tempDir->delete();
});
