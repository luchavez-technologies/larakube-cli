<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

function gitLoginFakes(array $overrides = []): array
{
    return array_merge([
        '*command -v tea*' => Process::result(output: '', exitCode: 1),
        '*logins delete*' => Process::result(),
        '*login add*' => Process::result(output: 'Login as larakube on https://git.example.com successful'),
        '*get secret larakube-tools-registry*' => base64_encode(json_encode([
            ['tool' => 'git', 'instance' => 'git-example-com', 'host' => 'git.example.com'],
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

test('without tea installed, git:login runs the official image and forwards the token variable by name', function (): void {
    putenv('LARAKUBE_CONTAINER_RUNTIME=podman');
    Process::fake(gitLoginFakes());

    $this->artisan('git:login production --domain=git.example.com')
        ->expectsQuestion('Forgejo access token for git.example.com', 'tok-abc')
        ->assertExitCode(0);

    putenv('LARAKUBE_CONTAINER_RUNTIME');

    Process::assertRan(fn (PendingProcess $process) => str_starts_with($process->command, 'podman run --rm -i ')
        && str_contains($process->command, "-e 'GITEA_SERVER_TOKEN'")
        && str_contains($process->command, 'docker.io/gitea/tea:0.16.0 ')
        && str_contains($process->command, "' tea login add"));
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

test('the tea container answers its git probe with "not a git repository" so --repo is used', function (): void {
    putenv('LARAKUBE_CONTAINER_RUNTIME=docker');
    Process::fake(['*command -v tea*' => Process::result(output: '', exitCode: 1), '*' => Process::result()]);

    $tea = (new class
    {
        use App\Traits\InteractsWithGlobalConfig;

        public function command(): string
        {
            return $this->getTeaCommand();
        }
    })->command();

    putenv('LARAKUBE_CONTAINER_RUNTIME');

    expect($tea)
        ->toContain('fatal: not a git repository')
        ->toContain('exit 128')
        ->toContain('PATH=/tmp:$PATH tea "$@"');
});
