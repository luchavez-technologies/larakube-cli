<?php

/**
 * The container-runtime resolver and its command builders. The whole point of
 * the abstraction is that `build` does NOT alias cleanly (docker buildx +
 * --load vs. plain podman build), so these assert the emitted string per
 * runtime — no real process runs. Runtime is pinned via the env override, which
 * short-circuits detection.
 */

use App\Traits\ResolvesContainerRuntime;

function containerRuntimeHarness(): object
{
    return new class
    {
        use ResolvesContainerRuntime;
    };
}

afterEach(function (): void {
    // TestCase::setUp() pins docker for every test; restore it so a podman-pinned
    // case here never leaks into whatever runs next in this worker.
    putenv('LARAKUBE_CONTAINER_RUNTIME=docker');
});

test('the env override wins and short-circuits detection', function (): void {
    putenv('LARAKUBE_CONTAINER_RUNTIME=podman');
    expect(containerRuntimeHarness()->containerRuntime())->toBe('podman')
        ->and(containerRuntimeHarness()->runtimeIsPodman())->toBeTrue();

    putenv('LARAKUBE_CONTAINER_RUNTIME=docker');
    expect(containerRuntimeHarness()->containerRuntime())->toBe('docker')
        ->and(containerRuntimeHarness()->runtimeIsPodman())->toBeFalse();
});

test('an unrecognised override is ignored and detection takes over', function (): void {
    // Invalid value → fall through to detection. Pin the detection shell-outs so
    // this is deterministic regardless of what the test host has installed.
    putenv('LARAKUBE_CONTAINER_RUNTIME=containerd');
    Process::fake([
        'command -v podman' => Process::result(output: '/usr/bin/podman'),
        'podman info' => Process::result(output: 'host: ...'),
    ]);
    expect(containerRuntimeHarness()->containerRuntime())->toBe('podman');
});

test('on WSL/Linux Podman is the default: preferred when up, and the fallback when neither is', function (): void {
    putenv('LARAKUBE_CONTAINER_RUNTIME=containerd'); // force detection

    // Podman up → podman (even if docker is also up).
    Process::fake([
        'command -v podman' => Process::result(output: '/usr/bin/podman'),
        'podman info' => Process::result(output: 'host: ...'),
        'docker info' => Process::result(output: 'Server Version: 27'),
    ]);
    expect(containerRuntimeHarness()->containerRuntime())->toBe('podman');

    // Nothing up yet → still Podman (the runtime `setup` installs), NOT docker.
    Process::fake([
        'command -v podman' => Process::result(output: ''),
        'podman info' => Process::result(output: '', exitCode: 1),
        'docker info' => Process::result(output: '', exitCode: 1),
    ]);
    expect(containerRuntimeHarness()->containerRuntime())->toBe('podman');
});

test('an existing Docker-only box is respected (Podman absent, Docker up → docker)', function (): void {
    putenv('LARAKUBE_CONTAINER_RUNTIME=containerd'); // force detection
    Process::fake([
        'command -v podman' => Process::result(output: ''),
        'docker info' => Process::result(output: 'Server Version: 27'),
    ]);
    expect(containerRuntimeHarness()->containerRuntime())->toBe('docker');
});

test('docker builds via buildx and loads the cross-built image back into the store', function (): void {
    putenv('LARAKUBE_CONTAINER_RUNTIME=docker');

    $cmd = containerRuntimeHarness()->buildImageCommand(
        'app:prod', '/proj/Dockerfile.php', '/proj', 'linux/amd64', '/tmp/dotenv', '--target deploy',
    );

    expect($cmd)
        ->toContain('docker buildx build')
        ->toContain('--platform linux/amd64')
        ->toContain('--target deploy')
        ->toContain("--secret id=dotenv,src='/tmp/dotenv'")
        ->toContain("-t 'app:prod'")
        ->toContain("-f '/proj/Dockerfile.php'")
        ->toEndWith('--load');
});

test('podman builds with plain `build` — no buildx sub-command and no --load', function (): void {
    putenv('LARAKUBE_CONTAINER_RUNTIME=podman');

    $cmd = containerRuntimeHarness()->buildImageCommand(
        'app:prod', '/proj/Dockerfile.php', '/proj', 'linux/amd64', '/tmp/dotenv', '--target deploy',
    );

    expect($cmd)
        ->toStartWith('podman build ')
        ->toContain('--platform linux/amd64')
        ->toContain('--target deploy')
        // the BuildKit dotenv secret survives on Podman (Buildah ≥3.1)
        ->toContain("--secret id=dotenv,src='/tmp/dotenv'")
        ->toContain("-t 'app:prod'")
        ->not->toContain('buildx')
        ->not->toContain('--load');
});

test('a build with no platform/secret still emits the runtime-correct verb', function (): void {
    putenv('LARAKUBE_CONTAINER_RUNTIME=docker');
    expect(containerRuntimeHarness()->buildImageCommand('a:local', '/p/Dockerfile', '/p'))
        ->toContain('docker buildx build')
        ->not->toContain('--platform')
        ->not->toContain('--secret');

    putenv('LARAKUBE_CONTAINER_RUNTIME=podman');
    expect(containerRuntimeHarness()->buildImageCommand('a:local', '/p/Dockerfile', '/p'))
        ->toStartWith('podman build ')
        ->not->toContain('--load');
});

test('containerChownSpec keeps host ownership under each runtime', function (): void {
    // Docker: bind-mount files land root-owned → chown to the real host uid:gid.
    putenv('LARAKUBE_CONTAINER_RUNTIME=docker');
    expect(containerRuntimeHarness()->containerChownSpec(1000, 1000))->toBe('1000:1000');

    // Rootless Podman: container root already maps to the host user, so chowning
    // to 1000 would hit an unwritable subuid — 0:0 keeps the tree host-owned.
    putenv('LARAKUBE_CONTAINER_RUNTIME=podman');
    expect(containerRuntimeHarness()->containerChownSpec(1000, 1000))->toBe('0:0')
        ->and(containerRuntimeHarness()->containerChownSpec(501, 20))->toBe('0:0');
});

test('save / pull / images builders swap only the binary — the shape is identical', function (): void {
    putenv('LARAKUBE_CONTAINER_RUNTIME=docker');
    $d = containerRuntimeHarness();
    expect($d->saveImageCommand('app:abc'))->toBe("docker save 'app:abc'")
        ->and($d->pullImageCommand('node:24-alpine'))->toBe("docker pull 'node:24-alpine'")
        ->and($d->imageQuietLookupCommand('app:local'))->toBe("docker images -q 'app:local'");

    putenv('LARAKUBE_CONTAINER_RUNTIME=podman');
    $p = containerRuntimeHarness();
    expect($p->saveImageCommand('app:abc'))->toBe("podman save 'app:abc'")
        ->and($p->pullImageCommand('node:24-alpine'))->toBe("podman pull 'node:24-alpine'")
        ->and($p->imageQuietLookupCommand('app:local'))->toBe("podman images -q 'app:local'");
});

test('resolution is un-cached (property-free so the trait can compose onto enums)', function (): void {
    $h = containerRuntimeHarness();

    putenv('LARAKUBE_CONTAINER_RUNTIME=podman');
    expect($h->containerRuntime())->toBe('podman');

    // No per-object cache — a later override is honoured on the next call. (The
    // trait can't hold a property: enums, which compose it, forbid properties.)
    putenv('LARAKUBE_CONTAINER_RUNTIME=docker');
    expect($h->containerRuntime())->toBe('docker');
});
