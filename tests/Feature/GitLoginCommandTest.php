<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

function gitLoginFakes(array $overrides = []): array
{
    return array_merge([
        '*command -v tea*' => Process::result(output: '/usr/local/bin/tea', exitCode: 0),
        '*logins delete*' => Process::result(),
        '*login add*' => Process::result(output: 'Login as larakube on https://git.example.com successful'),
        '*get secret larakube-tools-registry*' => base64_encode(json_encode([
            ['tool' => 'forgejo', 'instance' => 'git-example-com', 'host' => 'git.example.com'],
        ])),
        '*' => Process::result(),
    ], $overrides);
}

test('git:login hands the token to tea through the environment, never its arguments', function (): void {
    Process::fake(gitLoginFakes());

    $this->artisan('git:login production --domain=git.example.com')
        ->expectsQuestion('Forgejo access token for git.example.com', 'tok-abc')
        ->assertExitCode(0)
        ->expectsOutputToContain('tea is logged in to git.example.com');

    Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, "login add --name 'git.example.com' --url 'https://git.example.com' --no-version-check")
        && ($process->environment['GITEA_SERVER_TOKEN'] ?? null) === 'tok-abc'
        && ! str_contains($process->command, 'tok-abc'));
});

test('without tea installed, git:login refuses and instructs the user to install tea', function (): void {
    Process::fake(gitLoginFakes(['*command -v tea*' => Process::result(output: '', exitCode: 1)]));

    $this->artisan('git:login production --domain=git.example.com')
        ->assertExitCode(1)
        ->expectsOutputToContain('Tea CLI (tea) is not installed')
        ->expectsOutputToContain('larakube setup --tools=tea');
});

test('git:login finds the Forgejo host recorded on the cluster when --domain is omitted', function (): void {
    Process::fake(gitLoginFakes());

    $this->artisan('git:login production --context=prod-cluster')
        ->expectsQuestion('Forgejo access token for git.example.com', 'tok-abc')
        ->assertExitCode(0);
});

test('git:login stops when no Forgejo is registered on the cluster', function (): void {
    Process::fake(gitLoginFakes(['*get secret larakube-tools-registry*' => '']));

    $this->artisan('git:login production --context=prod-cluster')
        ->assertExitCode(1)
        ->expectsOutputToContain('No Forgejo is registered');

    Process::assertNotRan(fn (PendingProcess $process) => str_contains($process->command, 'login add'));
});

test('git:login accepts --token and --json non-interactively', function (): void {
    Process::fake(gitLoginFakes());

    $this->artisan('git:login production --domain=git.example.com --token=tok-xyz --json --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain(json_encode([
            'success' => true,
            'host' => 'git.example.com',
            'environment' => 'production',
        ], JSON_UNESCAPED_SLASHES));

    Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, "login add --name 'git.example.com' --url 'https://git.example.com' --no-version-check")
        && ($process->environment['GITEA_SERVER_TOKEN'] ?? null) === 'tok-xyz'
        && ! str_contains($process->command, 'tok-xyz'));
});

test('git:login reads GITEA_SERVER_TOKEN from the environment under --json --no-interaction', function (): void {
    Process::fake(gitLoginFakes());
    putenv('GITEA_SERVER_TOKEN=tok-from-env');

    try {
        $this->artisan('git:login production --domain=git.example.com --json --no-interaction')
            ->assertExitCode(0)
            ->expectsOutputToContain('"success":true');

        Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, 'login add')
            && ($process->environment['GITEA_SERVER_TOKEN'] ?? null) === 'tok-from-env');
    } finally {
        putenv('GITEA_SERVER_TOKEN');
    }
});

test('git:login emits json error when non-interactive and no token is supplied', function (): void {
    Process::fake(gitLoginFakes());
    putenv('GITEA_SERVER_TOKEN');

    $this->artisan('git:login production --domain=git.example.com --json --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('"success":false')
        ->expectsOutputToContain('Forgejo access token is required');

    Process::assertNotRan(fn (PendingProcess $process) => str_contains($process->command, 'login add'));
});

test('git:login emits json error when tea login add fails under --json', function (): void {
    Process::fake(gitLoginFakes([
        '*login add*' => Process::result(errorOutput: '401 Unauthorized', exitCode: 1),
    ]));

    $this->artisan('git:login production --domain=git.example.com --token=bad-tok --json --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('"success":false')
        ->expectsOutputToContain('401 Unauthorized');
});
