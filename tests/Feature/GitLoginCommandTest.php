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
