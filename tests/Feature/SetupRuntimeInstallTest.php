<?php

/**
 * Phase 3 of the Podman migration: `larakube setup` installs rootless Podman on
 * a WSL/Linux host. Covers the runtime choice and the install command shape —
 * the full handle() (cluster:setup, traefik:setup, dnsmasq, k9s) is out of scope
 * for a unit-level test.
 */

use App\Commands\SetupCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function setupRuntimeHarness(): object
{
    $command = new class extends SetupCommand
    {
        public function callEnsureRuntime(): bool
        {
            return $this->ensureContainerRuntimeInstalled();
        }

        public function callInstallPodman(): bool
        {
            return $this->installRootlessPodman();
        }

        // The real one prompts (confirm) and runs apt upgrade — neither is what
        // these tests are about.
        protected function updateSystemPackages(): void {}
    };

    $input = new ArrayInput([], $command->getDefinition());
    $command->setInput($input);
    $command->setOutput(new OutputStyle($input, new BufferedOutput));

    return $command;
}

test('normalizeRuntimeFlag accepts podman/docker (any case) and rejects the rest', function (): void {
    $c = setupRuntimeHarness();

    expect($c->normalizeRuntimeFlag('podman'))->toBe('podman')
        ->and($c->normalizeRuntimeFlag('  DOCKER '))->toBe('docker')
        ->and($c->normalizeRuntimeFlag('containerd'))->toBeNull()
        ->and($c->normalizeRuntimeFlag(''))->toBeNull()
        ->and($c->normalizeRuntimeFlag(null))->toBeNull();
});

test('installRootlessPodman installs the rootless package set and verifies the engine', function (): void {
    Process::fake([
        'command -v apt-get' => Process::result(output: '/usr/bin/apt-get'),
        '*apt-get install*podman*' => Process::result(output: 'installed'),
        '*registries.conf*' => Process::result(output: ''), // sudo tee — never run for real
        'command -v podman' => Process::result(output: '/usr/bin/podman'),
        'podman info' => Process::result(output: 'host: ...'),
    ]);

    expect(setupRuntimeHarness()->callInstallPodman())->toBeTrue();

    // The three companions are what make it rootless.
    Process::assertRan(fn ($p) => str_contains($p->command, 'apt-get install -y podman slirp4netns fuse-overlayfs uidmap'));
});

test('installRootlessPodman reports failure (not success) when the engine never answers', function (): void {
    Process::fake([
        'command -v apt-get' => Process::result(output: '/usr/bin/apt-get'),
        '*apt-get install*podman*' => Process::result(output: 'installed'),
        '*registries.conf*' => Process::result(output: ''), // sudo tee — never run for real
        // installed on PATH, but rootless not active yet (fresh subuid/subgid).
        'command -v podman' => Process::result(output: '/usr/bin/podman'),
        'podman info' => Process::result(output: '', exitCode: 1),
    ]);

    expect(setupRuntimeHarness()->callInstallPodman())->toBeFalse();
});

test('installRootlessPodman bails cleanly on a non-apt host', function (): void {
    Process::fake([
        'command -v apt-get' => Process::result(output: '', exitCode: 1),
    ]);

    expect(setupRuntimeHarness()->callInstallPodman())->toBeFalse();
    Process::assertNotRan(fn ($p) => str_contains($p->command, 'apt-get install'));
});

test('ensureContainerRuntimeInstalled short-circuits when Podman is already functional', function (): void {
    Process::fake([
        'command -v podman' => Process::result(output: '/usr/bin/podman'),
        'podman info' => Process::result(output: 'host: ...'),
    ]);

    expect(setupRuntimeHarness()->callEnsureRuntime())->toBeTrue();
    // Nothing to install — no apt call at all.
    Process::assertNotRan(fn ($p) => str_contains($p->command, 'apt-get'));
});

test('--runtime=podman installs Podman headlessly when nothing is functional yet', function (): void {
    Process::fake([
        'command -v podman' => Process::result(output: ''),   // absent → not functional
        'podman info' => Process::result(output: '', exitCode: 1),
        'docker info' => Process::result(output: '', exitCode: 1),
        'command -v apt-get' => Process::result(output: '/usr/bin/apt-get'),
        '*apt-get install*podman*' => Process::result(output: 'installed'),
        '*registries.conf*' => Process::result(output: ''), // sudo tee — never run for real
    ]);

    $command = new class extends SetupCommand
    {
        public function callEnsureRuntime(): bool
        {
            return $this->ensureContainerRuntimeInstalled();
        }

        protected function updateSystemPackages(): void {}
    };
    $input = new ArrayInput(['--runtime' => 'podman'], $command->getDefinition());
    $command->setInput($input);
    $command->setOutput(new OutputStyle($input, new BufferedOutput));

    // Engine never comes up in this fake (podman info stays failing), so the
    // call returns false — but it must have chosen Podman and run the install,
    // never prompted, never touched Docker.
    $command->callEnsureRuntime();
    Process::assertRan(fn ($p) => str_contains($p->command, 'apt-get install -y podman'));
});
