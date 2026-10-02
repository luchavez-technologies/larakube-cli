<?php

use App\Commands\Runtime\RuntimeInstallCommand;
use Illuminate\Support\Facades\Process;

test('runtime:install installs rootless Podman and reports success', function (): void {
    Process::fake([
        'command -v apt-get' => Process::result(output: '/usr/bin/apt-get'),
        '*apt-get install*podman*' => Process::result(output: 'installed'),
        '*registries.conf*' => Process::result(output: ''),
        'command -v podman' => Process::result(output: '/usr/bin/podman'),
        'podman info' => Process::result(output: '', exitCode: 1),
    ]);

    // `podman info` fails before and after the fake install: the command must say so.
    $this->artisan('runtime:install')->assertExitCode(1);

    Process::assertRan(fn ($p) => str_contains($p->command, 'apt-get install -y podman slirp4netns fuse-overlayfs uidmap'));
});

test('runtime:install does nothing when Podman already works', function (): void {
    Process::fake([
        'command -v podman' => Process::result(output: '/usr/bin/podman'),
        'podman info' => Process::result(output: 'host: ...'),
    ]);

    $this->artisan('runtime:install')->assertExitCode(0);

    Process::assertNotRan(fn ($p) => str_contains($p->command, 'apt-get'));
});

test('runtime:install refuses a runtime it cannot install', function (): void {
    Process::fake();

    $this->artisan('runtime:install --runtime=docker')->assertExitCode(1);

    Process::assertNothingRan();
});

test('inside WSL with no terminal, root comes from Windows instead of a sudo password', function (): void {
    putenv('WSL_DISTRO_NAME=Ubuntu');
    Process::fake([
        'command -v wsl.exe' => Process::result(output: '/mnt/c/Windows/System32/wsl.exe'),
        'command -v apt-get' => Process::result(output: '/usr/bin/apt-get'),
        '*' => Process::result(output: ''),
    ]);

    $command = new class extends RuntimeInstallCommand
    {
        public function prefix(): string
        {
            return $this->privilegePrefix();
        }

        protected function hasTerminal(): bool
        {
            return false;
        }
    };

    try {
        expect($command->prefix())->toBe("wsl.exe -d 'Ubuntu' -u root -- ");
    } finally {
        putenv('WSL_DISTRO_NAME');
    }
});

test('with a terminal the prefix is sudo', function (): void {
    $command = new class extends RuntimeInstallCommand
    {
        public function prefix(): string
        {
            return $this->privilegePrefix();
        }

        protected function hasTerminal(): bool
        {
            return true;
        }
    };

    expect($command->prefix())->toBeIn(['sudo ', '']);
});
