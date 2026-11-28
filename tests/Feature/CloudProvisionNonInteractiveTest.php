<?php

use App\Data\GlobalConfigData;
use App\Exceptions\MissingFlagException;
use App\Facades\State;
use App\Support\CloudInstallerTrust;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function cloudInitApprovedPublicKey(string $seed = 'k'): string
{
    $type = 'ssh-ed25519';

    return $type.' '.base64_encode(pack('N', strlen($type)).$type.pack('N', 32).str_repeat($seed, 32));
}

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

test('cloud:init passes installer trust and authorized keys through and locks out the provisioning key', function (): void {
    $tempDir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempKey = $tempDir->path().'/id_ed25519';
    exec('ssh-keygen -t ed25519 -N "" -f '.escapeshellarg($tempKey).' -q');

    $approvedKey = cloudInitApprovedPublicKey('z');
    $commands = [];
    $handedOver = false;

    Process::fake(function ($process) use (&$commands, &$handedOver) {
        $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $commands[] = ['cmd' => $cmd, 'input' => (string) ($process->input ?? '')];

        if (str_contains($cmd, 'authorized_keys.XXXXXX')) {
            $handedOver = true;

            return Process::result();
        }

        if (str_contains($cmd, 'echo success')) {
            return $handedOver
                ? Process::result(output: 'Permission denied', exitCode: 255)
                : Process::result(output: "success\n");
        }

        if (str_contains($cmd, 'sudo -n true')) {
            return Process::result(output: '');
        }

        if (str_contains($cmd, 'test -f /var/run/reboot-required')) {
            return Process::result(exitCode: 1);
        }

        return Process::result(output: "ok\n");
    });

    $trust = new CloudInstallerTrust('https://accounts.google.com', 'larakube-cloud:01xyz', 'cloud-sa-123');

    $this->artisan('cloud:init', [
        '--ip' => '203.0.113.55',
        '--ssh-key' => $tempKey,
        '--admin-email' => 'admin@example.com',
        '--stack-name' => 'vps-cloud-existing',
        '--authorized-key' => [$approvedKey.' team@example.com'],
        '--cloud-installer-issuer' => $trust->issuer,
        '--cloud-installer-audience' => $trust->audience,
        '--cloud-installer-subject' => $trust->subject,
        '--without-dns' => true,
        '--without-tls' => true,
        '--json' => true,
        '--no-interaction' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('"installer":{"ready":true,"audience":"larakube-cloud:01xyz","error":null},"sshUser":"larakube"');

    $k3sInstall = collect($commands)->first(fn (array $entry): bool => str_contains($entry['cmd'], 'Installing K3s'));
    expect($k3sInstall)->not->toBeNull()
        ->and($k3sInstall['cmd'])->toContain(base64_encode($trust->authenticationConfig()))
        ->and($k3sInstall['cmd'])->toContain(CloudInstallerTrust::DROP_IN);

    $binding = collect($commands)->first(fn (array $entry): bool => str_contains($entry['cmd'], 'apply -f -') && str_contains($entry['input'], CloudInstallerTrust::BINDING));
    expect($binding)->not->toBeNull();

    $handover = collect($commands)->first(fn (array $entry): bool => str_contains($entry['cmd'], 'authorized_keys.XXXXXX'));
    expect($handover)->not->toBeNull()
        ->and($handover['cmd'])->toContain(escapeshellarg($approvedKey))
        ->and($handover['cmd'])->not->toContain('seq 1 60');

    $stack = GlobalConfigData::load()->findStack('vps-cloud-existing');
    expect($stack)->not->toBeNull()
        ->and($stack->sshKey)->toBeNull();
});

test('cloud:init rejects private --authorized-key values and incomplete --cloud-installer-* flags', function (): void {
    $this->artisan('cloud:init', [
        '--authorized-key' => ['-----BEGIN OPENSSH PRIVATE KEY-----'],
        '--no-interaction' => true,
    ])->assertExitCode(1);

    expect(State::lastError())->toContain('Private keys are rejected');

    $this->artisan('cloud:init', [
        '--cloud-installer-issuer' => 'https://accounts.google.com',
        '--no-interaction' => true,
    ])->assertExitCode(1);

    expect(State::lastError())->toContain('--cloud-installer-issuer, --cloud-installer-audience and --cloud-installer-subject go together.');
});

test('cloud:init accepts --dns-provider and --dns-tls-group via the shared DNS helper', function (): void {
    $definition = app(App\Commands\Cloud\CloudProvisionCommand::class)->getDefinition();

    expect($definition->hasOption('dns-provider'))->toBeTrue()
        ->and($definition->hasOption('dns-tls-group'))->toBeTrue()
        ->and($definition->hasOption('authorized-key'))->toBeTrue()
        ->and($definition->hasOption('cloud-installer-issuer'))->toBeTrue()
        ->and($definition->hasOption('cloud-installer-audience'))->toBeTrue()
        ->and($definition->hasOption('cloud-installer-subject'))->toBeTrue();
});
