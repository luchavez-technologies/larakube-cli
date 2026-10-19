<?php

use App\Data\GlobalConfigData;
use App\Exceptions\MissingFlagException;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    // Reset global config stacks for isolation
    config(['larakube.global_path' => sys_get_temp_dir().'/larakube_test_'.uniqid().'.json']);
});

afterEach(function (): void {
    $path = config('larakube.global_path');
    if ($path && file_exists($path)) {
        @unlink($path);
    }
});

test('throws MissingFlagException when --ip is omitted in non-interactive mode', function (): void {
    expect(fn () => $this->artisan('cloud:init', ['--no-interaction' => true]))->toThrow(MissingFlagException::class);
});

test('throws MissingFlagException when --admin-email is omitted and no email stored', function (): void {
    $tempKey = tempnam(sys_get_temp_dir(), 'test_key_');
    @unlink($tempKey);
    exec('ssh-keygen -t ed25519 -N "" -f '.escapeshellarg($tempKey).' -q');

    $this->expectException(MissingFlagException::class);

    try {
        $this->artisan('cloud:init', [
            '--ip' => '1.2.3.4',
            '--ssh-key' => $tempKey,
            '--no-interaction' => true,
        ]);
    } finally {
        @unlink($tempKey);
        @unlink($tempKey.'.pub');
    }
});

test('provisions successfully non-interactively and registers stack in global config', function (): void {
    $tempKey = tempnam(sys_get_temp_dir(), 'test_key_');
    @unlink($tempKey);
    exec('ssh-keygen -t ed25519 -N "" -f '.escapeshellarg($tempKey).' -q');

    // Fake Process for remote SSH executions
    Process::fake([
        'ssh-keygen -R *' => Process::result(),
        "ssh * 'echo success'" => Process::result(output: "success\n"),
        "ssh * 'sudo -n true'" => Process::result(output: ''),
        "ssh * 'test -f /var/run/reboot-required'" => Process::result(exitCode: 1),
        "ssh * 'echo K3S_URL=*'" => Process::result(output: 'https://1.2.3.4:6443'),
        'kubectl --context=* get --raw=/readyz*' => Process::result(output: 'ok'),
        'kubectl --context=* apply *' => Process::result(output: 'applied'),
        'kubectl --context=* get *' => Process::result(output: 'ok'),
        '*' => Process::result(output: "success\n"),
    ]);

    try {
        $this->artisan('cloud:init', [
            '--ip' => '1.2.3.4',
            '--ssh-key' => $tempKey,
            '--admin-email' => 'admin@example.com',
            '--stack-name' => 'vps-test-1',
            '--without-dns' => true,
            '--without-tls' => true,
            '--json' => true,
            '--no-interaction' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('"success":true,"name":"vps-test-1","ip":"1.2.3.4"');

        $stack = GlobalConfigData::load()->findStack('vps-test-1');
        expect($stack)->not->toBeNull()
            ->and($stack->ip)->toBe('1.2.3.4')
            ->and($stack->provider)->toBe('custom')
            ->and($stack->kind)->toBe('vps');
    } finally {
        @unlink($tempKey);
        @unlink($tempKey.'.pub');
    }
});

test('provisions successfully with encrypted key when --ssh-passphrase is provided', function (): void {
    $tempKey = tempnam(sys_get_temp_dir(), 'test_enc_key_');
    @unlink($tempKey);
    exec('ssh-keygen -t ed25519 -N "pass123" -f '.escapeshellarg($tempKey).' -q');

    Process::fake([
        'ssh-keygen -R *' => Process::result(),
        "ssh * 'echo success'" => Process::result(output: "success\n"),
        "ssh * 'sudo -n true'" => Process::result(output: ''),
        "ssh * 'test -f /var/run/reboot-required'" => Process::result(exitCode: 1),
        "ssh * 'echo K3S_URL=*'" => Process::result(output: 'https://1.2.3.4:6443'),
        'kubectl --context=* get --raw=/readyz*' => Process::result(output: 'ok'),
        'kubectl --context=* apply *' => Process::result(output: 'applied'),
        'kubectl --context=* get *' => Process::result(output: 'ok'),
        '*' => Process::result(output: "success\n"),
    ]);

    try {
        $this->artisan('cloud:init', [
            '--ip' => '1.2.3.4',
            '--ssh-key' => $tempKey,
            '--ssh-passphrase' => 'pass123',
            '--admin-email' => 'admin@example.com',
            '--stack-name' => 'vps-enc-test',
            '--without-dns' => true,
            '--without-tls' => true,
            '--json' => true,
            '--no-interaction' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('"success":true,"name":"vps-enc-test"');

        $stack = GlobalConfigData::load()->findStack('vps-enc-test');
        expect($stack)->not->toBeNull()
            ->and($stack->name)->toBe('vps-enc-test')
            ->and($stack->sshKey)->toBe($tempKey);
    } finally {
        @unlink($tempKey);
        @unlink($tempKey.'.pub');
    }
});
