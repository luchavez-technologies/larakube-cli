<?php

use App\Commands\Cloud\CloudCreateCommand;
use App\Data\ConfigData;
use App\Facades\State;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function sshHandoverPublicKey(string $seed = 'a'): string
{
    $type = 'ssh-ed25519';

    return $type.' '.base64_encode(pack('N', strlen($type)).$type.pack('N', 32).str_repeat($seed, 32));
}

function sshHandoverCommand(array $options = []): CloudCreateCommand
{
    $command = app(CloudCreateCommand::class);
    $input = new ArrayInput($options, $command->getDefinition());
    $command->setInput($input);
    $command->setOutput(new OutputStyle($input, new BufferedOutput));

    return $command;
}

function sshHandoverRun(CloudCreateCommand $command, string $home, string $provider = 'gcp'): bool
{
    $_SERVER['HOME'] = $home;

    return (fn (): bool => $this->handOverSshAccess(
        ['path' => '/usr/bin/tofu', 'isOpenTofu' => true],
        'cloud-server',
        ['region' => 'us-central1', 'zone' => 'us-central1-a', 'size' => 'e2-small', 'dropletName' => 'cloud-server', 'sshSources' => '"0.0.0.0/0"', 'sshPort' => 22, 'sshPubKey' => 'ssh-ed25519 THROWAWAY'],
        '203.0.113.10',
        22,
        '/tmp/throwaway',
        [sshHandoverPublicKey()],
        $provider,
    ))->call($command);
}

test('normalizeAuthorizedKey keeps type and key and drops the comment', function (): void {
    $key = sshHandoverPublicKey();

    expect((fn (): ?string => $this->normalizeAuthorizedKey($key.' me@example.com ${evil}'))->call(sshHandoverCommand()))->toBe($key);
});

test('normalizeAuthorizedKey rejects private, multi-line and mismatched keys', function (string $raw): void {
    expect((fn (): ?string => $this->normalizeAuthorizedKey($raw))->call(sshHandoverCommand()))->toBeNull();
})->with([
    'private key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
    'two lines' => "ssh-ed25519 AAAA\nssh-ed25519 BBBB",
    'options prefix' => 'command="id" ssh-ed25519 AAAA',
    'type mismatch' => 'ssh-rsa '.base64_encode(pack('N', 11).'ssh-ed25519'),
]);

test('resolveAuthorizedKeys accepts do, hetzner, aws and gcp outside a project', function (string $provider): void {
    $key = sshHandoverPublicKey();
    $command = sshHandoverCommand(['--authorized-key' => [$key.' team@example.com']]);

    $resolved = (fn (): array|false => $this->resolveAuthorizedKeys($provider, null))->call($command);

    expect($resolved)->toBe([$key]);
})->with(['do', 'hetzner', 'aws', 'gcp']);

test('resolveAuthorizedKeys refuses inside a project for every provider', function (string $provider): void {
    $key = sshHandoverPublicKey();
    $command = sshHandoverCommand(['--authorized-key' => [$key]]);

    $resolved = (fn (): array|false => $this->resolveAuthorizedKeys($provider, new ConfigData))->call($command);

    expect($resolved)->toBeFalse()
        ->and(State::lastError())->toBe('--authorized-key is only supported for a VPS created outside a project.');
})->with(['do', 'hetzner', 'aws', 'gcp']);

test('resolveAuthorizedKeys refuses private keys', function (string $provider): void {
    $command = sshHandoverCommand(['--authorized-key' => ['-----BEGIN OPENSSH PRIVATE KEY-----']]);

    $resolved = (fn (): array|false => $this->resolveAuthorizedKeys($provider, null))->call($command);

    expect($resolved)->toBeFalse()
        ->and(State::lastError())->toContain('Private keys are rejected');
})->with(['do', 'hetzner', 'aws', 'gcp']);

test('the handover script waits for approved keys before removing only the provisioning key', function (): void {
    $script = sshHandoverCommand()->handOverSshAccessScript(['APPROVEDONE', 'APPROVEDTWO'], 'THROWAWAY');

    expect(strpos($script, 'exit 1'))->toBeLessThan(strpos($script, 'grep -vF'))
        ->and(strpos($script, 'grep -vF'))->toBeLessThan(strpos($script, 'mv -f'))
        ->and($script)->toContain("grep -vF -- 'THROWAWAY'")
        ->toContain("for blob in 'APPROVEDONE' 'APPROVEDTWO'")
        ->not->toContain('printf');
});

test('writeSshAccessScript writes approved keys, removes provisioning blob, sets permissions, empties root authorized_keys, and escapes shell metacharacters', function (): void {
    $keyOne = sshHandoverPublicKey('a');
    $keyTwo = sshHandoverPublicKey('b');
    $metacharKey = "ssh-ed25519 AAAA\$(id)`uname`;'\"";
    $script = sshHandoverCommand()->writeSshAccessScript([$keyOne, $keyTwo, $metacharKey], "THROWAWAY'$(whoami)");

    expect($script)
        ->toContain('set -e')
        ->toContain(escapeshellarg($keyOne))
        ->toContain(escapeshellarg($keyTwo))
        ->toContain(escapeshellarg($metacharKey))
        ->toContain('grep -vF -- '.escapeshellarg("THROWAWAY'$(whoami)"))
        ->toContain('chown larakube:larakube "$staged"')
        ->toContain('chmod 600 "$staged"')
        ->toContain('mv -f "$staged" "$file"')
        ->toContain('if [ -f /root/.ssh/authorized_keys ]; then : > /root/.ssh/authorized_keys; fi')
        ->not->toContain('seq 1 60')
        ->not->toContain('sleep 2');
});

test('the GCP template lists every approved key under larakube and drops root', function (): void {
    $second = 'ssh-rsa '.base64_encode(pack('N', 7).'ssh-rsa');
    $hcl = view('tofu.gcp.vps', ['region' => 'us-central1', 'size' => 'e2-small', 'dropletName' => 'x', 'sshSources' => '"0.0.0.0/0"', 'sshPubKey' => 'ssh-ed25519 THROWAWAY', 'sshAuthorizedKeys' => [sshHandoverPublicKey(), $second]])->render();

    expect($hcl)->toContain('"larakube:'.sshHandoverPublicKey().'"')
        ->toContain('"larakube:'.$second.'"')
        ->not->toContain('THROWAWAY');
});

test('handover refuses when the provisioning key is also an approved key', function (string $provider): void {
    $home = TemporaryDirectory::make()->deleteWhenDestroyed();
    $_SERVER['HOME'] = $home->path();
    Process::fake();
    $key = sshHandoverPublicKey();

    $handedOver = (fn (): bool => $this->handOverSshAccess(['path' => '/usr/bin/tofu', 'isOpenTofu' => true], 'cloud-server', ['sshPubKey' => $key.' comment'], '203.0.113.10', 22, '/tmp/throwaway', [$key], $provider))->call(sshHandoverCommand());

    expect($handedOver)->toBeFalse();
    Process::assertNothingRan();
})->with(['gcp', 'do', 'hetzner', 'aws']);

test('handover updates metadata before touching the server and proves the old key is dead', function (): void {
    $home = TemporaryDirectory::make()->deleteWhenDestroyed();
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return str_contains($process->command, 'echo success')
            ? Process::result(output: 'Permission denied', exitCode: 255)
            : Process::result();
    });

    expect(sshHandoverRun(sshHandoverCommand(), $home->path()))->toBeTrue();

    $apply = array_key_first(array_filter($commands, fn (string $c): bool => str_contains($c, ' apply ') && str_contains($c, '-refresh=false')));
    $install = array_key_first(array_filter($commands, fn (string $c): bool => str_contains($c, 'authorized_keys')));
    expect($apply)->not->toBeNull()->and($install)->not->toBeNull()
        ->and($apply)->toBeLessThan($install);
});

test('handover fails when the provisioning key still logs in', function (): void {
    $home = TemporaryDirectory::make()->deleteWhenDestroyed();
    Process::fake([
        '*apply*' => Process::result(),
        '*echo success*' => Process::result(output: 'success'),
        '*ssh -i*' => Process::result(),
    ]);

    expect(sshHandoverRun(sshHandoverCommand(), $home->path()))->toBeFalse();
});

test('handover leaves the server alone when the metadata update fails', function (): void {
    $home = TemporaryDirectory::make()->deleteWhenDestroyed();
    Process::fake([
        '*apply*' => Process::result(exitCode: 1),
        '*ssh -i*' => Process::result(),
    ]);

    expect(sshHandoverRun(sshHandoverCommand(), $home->path()))->toBeFalse();
    Process::assertNotRan(fn ($process): bool => str_contains($process->command, 'authorized_keys'));
});

test('non-GCP handover writes keys over SSH without re-applying tofu and proves the old key is dead', function (string $provider): void {
    $home = TemporaryDirectory::make()->deleteWhenDestroyed();
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return str_contains($process->command, 'echo success')
            ? Process::result(output: 'Permission denied', exitCode: 255)
            : Process::result();
    });

    expect(sshHandoverRun(sshHandoverCommand(), $home->path(), $provider))->toBeTrue();

    Process::assertNotRan(fn ($process): bool => str_contains($process->command, 'tofu') || str_contains($process->command, 'apply'));
    expect(file_exists($home->path().'/.larakube/tofu/cloud-server/main.tf'))->toBeFalse();

    $install = collect($commands)->first(fn (string $c): bool => str_contains($c, 'authorized_keys'));
    expect($install)->not->toBeNull()
        ->and($install)->toContain(sshHandoverPublicKey())
        ->and($install)->toContain('printf')
        ->and($install)->not->toContain('seq 1 60');
})->with(['do', 'hetzner', 'aws']);

test('non-GCP handover fails when the provisioning key still logs in', function (string $provider): void {
    $home = TemporaryDirectory::make()->deleteWhenDestroyed();
    Process::fake([
        '*echo success*' => Process::result(output: 'success'),
        '*ssh -i*' => Process::result(),
    ]);

    expect(sshHandoverRun(sshHandoverCommand(), $home->path(), $provider))->toBeFalse();
    Process::assertNotRan(fn ($process): bool => str_contains($process->command, 'tofu') || str_contains($process->command, 'apply'));
})->with(['do', 'hetzner', 'aws']);
