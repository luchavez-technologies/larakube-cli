<?php

use App\Commands\Cloud\CloudCreateCommand;
use App\Support\CloudInstallerTrust;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function cloudInstallerTrust(): CloudInstallerTrust
{
    return new CloudInstallerTrust('https://accounts.google.com', 'larakube-cloud:01abc', '112233445566778899');
}

function cloudInstallerCreateCommand(): CloudCreateCommand
{
    $command = app(CloudCreateCommand::class);
    $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

    return $command;
}

test('the API server trusts one issuer, one audience and one subject, mapped to the installers group', function (): void {
    $config = cloudInstallerTrust()->authenticationConfig();

    expect($config)->toContain('kind: AuthenticationConfiguration')
        ->toContain('url: https://accounts.google.com')
        ->toContain('- larakube-cloud:01abc')
        ->toContain("claims.sub == '112233445566778899'")
        ->toContain("['larakube-cloud:installers']")
        ->and(cloudInstallerTrust()->dropIn())->toBe("kube-apiserver-arg+:\n  - \"authentication-config=/etc/rancher/k3s/larakube-cloud-auth.yaml\"\n")
        ->and(CloudInstallerTrust::bindingManifest())->toContain('name: cluster-admin')->toContain('name: larakube-cloud:installers');
});

test('values that could break out of the YAML or the CEL rule are refused', function (string $issuer, string $audience, string $subject): void {
    expect(fn () => new CloudInstallerTrust($issuer, $audience, $subject))->toThrow(InvalidArgumentException::class);
})->with([
    'subject escaping the CEL string' => ['https://accounts.google.com', 'larakube-cloud:a', "x' || true || 'y"],
    'subject with a newline' => ['https://accounts.google.com', 'larakube-cloud:a', "ok\nmore"],
    'plain http issuer' => ['http://accounts.google.com', 'larakube-cloud:a', '123'],
    'audience with spaces' => ['https://accounts.google.com', 'larakube cloud', '123'],
]);

test('the three installer options go together, and none means no trust', function (): void {
    expect(CloudInstallerTrust::fromOptions(null, null, null))->toBeNull()
        ->and(fn () => CloudInstallerTrust::fromOptions('https://accounts.google.com', 'larakube-cloud:a', null))->toThrow(InvalidArgumentException::class);
});

test('the trust files are written before k3s is installed', function (): void {
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return Process::result();
    });

    $command = cloudInstallerCreateCommand();
    (function (): void {
        $this->installerTrust = cloudInstallerTrust();
        $this->installK3s('root', '203.0.113.10', '22', '/tmp/key', null);
    })->call($command);

    $script = collect($commands)->first(fn (string $line): bool => str_contains($line, 'Installing K3s'));
    $auth = base64_encode(cloudInstallerTrust()->authenticationConfig());

    expect($script)->not->toBeNull()
        ->and(strpos($script, $auth))->toBeLessThan(strpos($script, 'Installing K3s'))
        ->and($script)->toContain(CloudInstallerTrust::DROP_IN);
});

test('create binds the installers group and reports it, or reports why not', function (bool $applied): void {
    Process::fake(['*apply -f -*' => Process::result(exitCode: $applied ? 0 : 1)]);

    $command = cloudInstallerCreateCommand();
    $result = (function (): array {
        $this->installerTrust = cloudInstallerTrust();
        $this->bindCloudInstallers('larakube-203.0.113.10');

        return $this->result['installer'];
    })->call($command);

    expect($result['ready'])->toBe($applied)
        ->and($result['audience'])->toBe('larakube-cloud:01abc')
        ->and($result['error'] === null)->toBe($applied);
    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'apply -f -') && str_contains((string) $process->input, 'larakube-cloud:installers'));
})->with([true, false]);
