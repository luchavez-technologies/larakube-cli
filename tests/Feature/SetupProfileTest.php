<?php

use Illuminate\Support\Facades\Process;

/**
 * Both tools already present, so nothing installs and the assertions are about
 * which STEPS the profile runs — the whole point of the remote track.
 */
function setupProfileToolingFakes(): array
{
    return [
        'command -v kubectl' => Process::result('/usr/local/bin/kubectl'),
        'command -v tofu' => Process::result('/usr/local/bin/tofu'),
        'command -v terraform' => Process::result('', exitCode: 1),
        'command -v podman' => Process::result('', exitCode: 1),
        'command -v docker' => Process::result('', exitCode: 1),
        '*' => Process::result('', exitCode: 1),
    ];
}

test('the remote profile skips every local-only step', function (): void {
    Process::fake(setupProfileToolingFakes());

    $this->artisan('setup', ['--profile' => 'remote'])
        ->expectsOutputToContain('Setting this machine up for Cluster Tools and remote clusters.')
        ->expectsOutputToContain('kubectl already installed at: /usr/local/bin/kubectl')
        ->expectsOutputToContain('Ready for Cluster Tools.')
        // The four things this track exists to avoid.
        ->doesntExpectOutputToContain('container runtime should larakube install')
        ->doesntExpectOutputToContain('Traefik')
        ->doesntExpectOutputToContain('dnsmasq')
        ->doesntExpectOutputToContain('Select developer CLI tools')
        ->assertExitCode(0);
});

test('the remote profile fails when kubectl cannot be installed', function (): void {
    // kubectl is the one hard requirement — a warning here would hand back a
    // machine that cannot run a single {tool}:init.
    Process::fake([
        'command -v kubectl' => Process::result('', exitCode: 1),
        '*' => Process::result('', exitCode: 1),
    ]);

    $this->artisan('setup', ['--profile' => 'remote', '--no-interaction' => true])
        ->expectsOutputToContain('Could not install kubectl')
        ->assertExitCode(1);
});

test('an unrecognised profile is refused rather than guessed', function (): void {
    Process::fake(setupProfileToolingFakes());

    $this->artisan('setup', ['--profile' => 'cloudy'])
        ->expectsOutputToContain("Unknown profile 'cloudy'")
        ->assertExitCode(1);
});

test('a non-interactive run with no profile derives one and says which', function (): void {
    // Deriving is fine; deriving SILENTLY is not — the run has to state which
    // track it picked and on what evidence.
    Process::fake(setupProfileToolingFakes());

    $this->artisan('setup', ['--no-interaction' => true])
        ->expectsOutputToContain("No --profile given — using 'remote'")
        ->expectsOutputToContain('No container runtime found.')
        ->assertExitCode(0);
});

test('--tools still bypasses profile resolution entirely', function (): void {
    // The targeted-install path returns before any profile question, so a
    // scripted `setup --tools=kubectl` keeps working unchanged.
    Process::fake(setupProfileToolingFakes());

    $this->artisan('setup', ['--tools' => 'kubectl'])
        ->expectsOutputToContain('Configuring kubectl (Kubernetes CLI)...')
        ->expectsOutputToContain('Already installed at: /usr/local/bin/kubectl')
        ->doesntExpectOutputToContain('What is this machine for?')
        ->assertExitCode(0);
});
