<?php

use App\Commands\Cloud\CloudCreateCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function sshHandoverPublicKey(): string
{
    $type = 'ssh-ed25519';

    return $type.' '.base64_encode(pack('N', strlen($type)).$type.pack('N', 32).str_repeat('a', 32));
}

function sshHandoverCommand(): CloudCreateCommand
{
    $command = app(CloudCreateCommand::class);
    $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

    return $command;
}

function sshHandoverRun(CloudCreateCommand $command, string $home): bool
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

test('the handover script waits for approved keys before removing only the provisioning key', function (): void {
    $script = sshHandoverCommand()->handOverSshAccessScript(['APPROVEDONE', 'APPROVEDTWO'], 'THROWAWAY');

    expect(strpos($script, 'exit 1'))->toBeLessThan(strpos($script, 'grep -vF'))
        ->and(strpos($script, 'grep -vF'))->toBeLessThan(strpos($script, 'mv -f'))
        ->and($script)->toContain("grep -vF -- 'THROWAWAY'")
        ->toContain("for blob in 'APPROVEDONE' 'APPROVEDTWO'")
        ->not->toContain('printf');
});

test('the GCP template lists every approved key under larakube and drops root', function (): void {
    $second = 'ssh-rsa '.base64_encode(pack('N', 7).'ssh-rsa');
    $hcl = view('tofu.gcp.vps', ['region' => 'us-central1', 'size' => 'e2-small', 'dropletName' => 'x', 'sshSources' => '"0.0.0.0/0"', 'sshPubKey' => 'ssh-ed25519 THROWAWAY', 'sshAuthorizedKeys' => [sshHandoverPublicKey(), $second]])->render();

    expect($hcl)->toContain('"larakube:'.sshHandoverPublicKey().'"')
        ->toContain('"larakube:'.$second.'"')
        ->not->toContain('THROWAWAY');
});

test('handover refuses when the provisioning key is also an approved key', function (): void {
    $home = TemporaryDirectory::make()->deleteWhenDestroyed();
    $_SERVER['HOME'] = $home->path();
    Process::fake();
    $key = sshHandoverPublicKey();

    $handedOver = (fn (): bool => $this->handOverSshAccess(['path' => '/usr/bin/tofu', 'isOpenTofu' => true], 'cloud-server', ['sshPubKey' => $key.' comment'], '203.0.113.10', 22, '/tmp/throwaway', [$key]))->call(sshHandoverCommand());

    expect($handedOver)->toBeFalse();
    Process::assertNothingRan();
});

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

    $apply = array_key_first(array_filter($commands, fn (string $c): bool => str_contains($c, ' apply ')));
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
