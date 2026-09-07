<?php

use App\Traits\CheckPrerequisites;
use Illuminate\Support\Facades\Process;

function prerequisitesChecker(): object
{
    return new class
    {
        use CheckPrerequisites;

        public function check(bool $requireK9s = false): bool
        {
            return $this->checkPrerequisites($requireK9s);
        }

        // Prompts helpers (error/info/warning) write directly to stdout via
        // Termwind, independent of Artisan's output — silence isn't needed for
        // the assertions here, but laraKubeError() is called on the runtime-not-
        // responding path and isn't part of this trait.
        public function laraKubeError($text = null) {}
    };
}

test('passes with a working Docker runtime (no Podman)', function (): void {
    Process::fake([
        'command -v podman' => Process::result(exitCode: 1),
        'command -v docker' => Process::result(output: '/usr/bin/docker'),
        'which kubectl' => Process::result(exitCode: 0),
        'docker info' => Process::result(exitCode: 0),
    ]);

    expect(prerequisitesChecker()->check())->toBeTrue();
});

test('passes with a working rootless Podman runtime (no Docker) — the post-`larakube setup` state', function (): void {
    Process::fake([
        'command -v podman' => Process::result(output: '/usr/bin/podman'),
        'command -v docker' => Process::result(exitCode: 1),
        'which kubectl' => Process::result(exitCode: 0),
        'podman info' => Process::result(exitCode: 0),
    ]);

    expect(prerequisitesChecker()->check())->toBeTrue();
});

test('fails when NEITHER Podman nor Docker is installed', function (): void {
    Process::fake([
        'command -v podman' => Process::result(exitCode: 1),
        'command -v docker' => Process::result(exitCode: 1),
        'which kubectl' => Process::result(exitCode: 0),
    ]);

    expect(prerequisitesChecker()->check())->toBeFalse();
});

test('fails when kubectl is missing', function (): void {
    Process::fake([
        'command -v podman' => Process::result(output: '/usr/bin/podman'),
        'command -v docker' => Process::result(exitCode: 1),
        'which kubectl' => Process::result(exitCode: 1),
        'podman info' => Process::result(exitCode: 0),
    ]);

    expect(prerequisitesChecker()->check())->toBeFalse();
});

test('fails when the runtime is installed but not responding', function (): void {
    Process::fake([
        'command -v podman' => Process::result(exitCode: 1),
        'command -v docker' => Process::result(output: '/usr/bin/docker'),
        'which kubectl' => Process::result(exitCode: 0),
        'docker info' => Process::result(exitCode: 1), // daemon down
    ]);

    expect(prerequisitesChecker()->check())->toBeFalse();
});

test('does not require k9s unless requested', function (): void {
    Process::fake([
        'command -v podman' => Process::result(exitCode: 1),
        'command -v docker' => Process::result(output: '/usr/bin/docker'),
        'which kubectl' => Process::result(exitCode: 0),
        'which k9s' => Process::result(exitCode: 1),
        'docker info' => Process::result(exitCode: 0),
    ]);

    expect(prerequisitesChecker()->check(requireK9s: true))->toBeTrue();
    Process::assertRan('which k9s');
});
