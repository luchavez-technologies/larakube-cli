<?php

/**
 * Tests for DetectsWsl's kubectl/docker-touching methods (isWsl() itself,
 * being pure env/file checks, is covered alongside InteractsWithHosts in
 * tests/Unit/HostsSyncTest.php). These shell out via the Process facade,
 * faked here — see app/Traits/DetectsWsl.php.
 */

use App\Traits\DetectsWsl;
use Illuminate\Support\Facades\Process;

function wslDetector(): object
{
    return new class
    {
        use DetectsWsl;

        public function dockerDesktopKubernetes(): bool
        {
            return $this->isDockerDesktopKubernetesOnWsl();
        }

        public function dockerCli(): bool
        {
            return $this->hasDockerCli();
        }

        public function dockerDesktop(): bool
        {
            return $this->isDockerDesktop();
        }

        public function dockerDesktopOnWsl(): bool
        {
            return $this->hasDockerDesktopOnWsl();
        }

        // Tests only ever simulate "is WSL" via WSL_DISTRO_NAME (see forceWsl()
        // below); stub out the /proc/version fallback so "not WSL" cases don't
        // depend on whether the machine running this suite is itself WSL2.
        protected function wslKernelSignaturePresent(): bool
        {
            return false;
        }
    };
}

function forceWsl(callable $callback): void
{
    $original = getenv('WSL_DISTRO_NAME');

    try {
        putenv('WSL_DISTRO_NAME=Ubuntu');
        $callback();
    } finally {
        putenv($original === false ? 'WSL_DISTRO_NAME' : "WSL_DISTRO_NAME={$original}");
    }
}

test('isDockerDesktopKubernetesOnWsl is false outside WSL, regardless of context', function () {
    $original = getenv('WSL_DISTRO_NAME');

    try {
        putenv('WSL_DISTRO_NAME');
        Process::fake(['kubectl config current-context' => 'docker-desktop']);

        expect(wslDetector()->dockerDesktopKubernetes())->toBeFalse();
    } finally {
        putenv($original === false ? 'WSL_DISTRO_NAME' : "WSL_DISTRO_NAME={$original}");
    }
});

test('isDockerDesktopKubernetesOnWsl checks the current context on WSL', function () {
    forceWsl(function () {
        Process::fake(['kubectl config current-context' => 'docker-desktop']);
        expect(wslDetector()->dockerDesktopKubernetes())->toBeTrue();

        Process::fake(['kubectl config current-context' => 'k3s-larakube']);
        expect(wslDetector()->dockerDesktopKubernetes())->toBeFalse();
    });
});

test('hasDockerCli reflects whether `command -v docker` resolves', function () {
    Process::fake(['command -v docker' => '/usr/bin/docker']);
    expect(wslDetector()->dockerCli())->toBeTrue();

    Process::fake(['command -v docker' => Process::result(output: '', exitCode: 1)]);
    expect(wslDetector()->dockerCli())->toBeFalse();
});

test('isDockerDesktop matches the daemon operating-system string, case-insensitively', function () {
    Process::fake(['docker info --format "{{.OperatingSystem}}"' => 'Docker Desktop']);
    expect(wslDetector()->dockerDesktop())->toBeTrue();

    Process::fake(['docker info --format "{{.OperatingSystem}}"' => 'Alpine Linux v3.20']);
    expect(wslDetector()->dockerDesktop())->toBeFalse();

    Process::fake(['docker info --format "{{.OperatingSystem}}"' => Process::result(output: '', exitCode: 1)]);
    expect(wslDetector()->dockerDesktop())->toBeFalse();
});

test('hasDockerDesktopOnWsl requires both WSL and a reachable Docker Desktop daemon', function () {
    Process::fake([
        'command -v docker' => '/usr/bin/docker',
        'docker info --format "{{.OperatingSystem}}"' => 'Docker Desktop',
    ]);
    expect(wslDetector()->dockerDesktopOnWsl())->toBeFalse(); // not WSL

    forceWsl(function () {
        Process::fake([
            'command -v docker' => '/usr/bin/docker',
            'docker info --format "{{.OperatingSystem}}"' => 'Docker Desktop',
        ]);
        expect(wslDetector()->dockerDesktopOnWsl())->toBeTrue();

        Process::fake([
            'command -v docker' => Process::result(output: '', exitCode: 1),
            'docker info --format "{{.OperatingSystem}}"' => 'Docker Desktop',
        ]);
        expect(wslDetector()->dockerDesktopOnWsl())->toBeFalse(); // no docker CLI
    });
});
