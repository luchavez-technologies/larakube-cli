<?php

/**
 * Tests for InteractsWithDocker's Process-backed read-only checks. Streaming
 * methods (buildTargetedImage/runInContainer/chownToHostUser, which echo
 * live output via runStreaming()) and sideloadIntoK3s's sudo-priming pipe
 * are left to a real-machine smoke test — the former has no meaningful
 * assertion beyond "a real docker build ran", the latter is genuinely
 * interactive (untouched by this migration on purpose).
 */

use App\Traits\InteractsWithDocker;
use Illuminate\Support\Facades\Process;

function dockerProcessHelper(): object
{
    return new class
    {
        use InteractsWithDocker;

        public function imageExistsCheck(string $image): bool
        {
            return $this->imageExists($image);
        }

        public function uid(): int
        {
            return $this->hostUid();
        }

        public function gid(): int
        {
            return $this->hostGid();
        }

        public function inActiveCluster(string $imageTag): ?bool
        {
            return $this->imageInActiveCluster($imageTag);
        }

        public function sideload(string $context): bool
        {
            return $this->resolveSideloadTarget($context);
        }

        public function contains(string $list, string $tag): bool
        {
            return $this->clusterImageListContains($list, $tag);
        }
    };
}

test('imageExists reflects whether docker images -q returned an id', function (): void {
    Process::fake(["docker images -q 'app:local'" => "sha256:abc123\n"]);
    expect(dockerProcessHelper()->imageExistsCheck('app:local'))->toBeTrue();

    Process::fake(["docker images -q 'app:local'" => Process::result(output: '', exitCode: 1)]);
    expect(dockerProcessHelper()->imageExistsCheck('app:local'))->toBeFalse();
});

test('hostUid/hostGid parse the id command output', function (): void {
    Process::fake(['id -u' => "1000\n"]);
    expect(dockerProcessHelper()->uid())->toBe(1000);

    Process::fake(['id -g' => "1000\n"]);
    expect(dockerProcessHelper()->gid())->toBe(1000);
});

test('hostUid falls back to posix_getuid when id produces no usable digits', function (): void {
    Process::fake(['id -u' => Process::result(output: '', exitCode: 1)]);

    expect(dockerProcessHelper()->uid())->toBeInt();
});

test('imageInActiveCluster is true (nothing to sideload) for a remote/registry context', function (): void {
    Process::fake(['kubectl config current-context' => "arn:aws:eks:us-east-1:123:cluster/prod\n"]);

    expect(dockerProcessHelper()->inActiveCluster('app:local'))->toBeTrue();
});

test('imageInActiveCluster is null for native k3s when sudo is not cached (never prompts)', function (): void {
    Process::fake([
        'kubectl config current-context' => "k3s-larakube\n",
        'sudo -n true' => Process::result(exitCode: 1),
    ]);

    expect(dockerProcessHelper()->inActiveCluster('app:local'))->toBeNull();
});

test('resolveSideloadTarget is true only for the local native k3s context', function (): void {
    $r = dockerProcessHelper();

    expect($r->sideload('k3s-larakube'))->toBeTrue()
        ->and($r->sideload('  k3s-larakube  '))->toBeTrue()   // trims whitespace
        ->and($r->sideload('larakube-203.0.113.5'))->toBeFalse()  // remote k3s from cloud:init
        ->and($r->sideload('arn:aws:eks:...'))->toBeFalse()       // managed cloud
        ->and($r->sideload('orbstack'))->toBeFalse()               // shares the host Docker daemon
        ->and($r->sideload('docker-desktop'))->toBeFalse()
        ->and($r->sideload(''))->toBeFalse();
});

/**
 * Deciding whether the active cluster already has the image (so `up` can import
 * a missing-from-cluster image without a full rebuild). The matcher must cope
 * with how crictl/ctr decorate refs: a docker.io/library/ prefix, or the repo
 * and tag landing in separate columns.
 */
test('clusterImageListContains matches an image across its decorations', function (): void {
    $r = dockerProcessHelper();

    expect($r->contains("app-two:latest\nredis:7\n", 'app-two:latest'))->toBeTrue()        // exact ref
        ->and($r->contains('docker.io/library/app-two:latest', 'app-two:latest'))->toBeTrue() // prefixed
        ->and($r->contains("IMAGE                TAG\napp-two              latest\n", 'app-two:latest'))->toBeTrue(); // columns
});

test('clusterImageListContains reports an image absent from the listing', function (): void {
    $r = dockerProcessHelper();

    expect($r->contains("redis:7\npostgres:16\n", 'app-two:latest'))->toBeFalse() // not listed
        ->and($r->contains('', 'app-two:latest'))->toBeFalse()                     // empty listing
        ->and($r->contains('app-two:latest', ''))->toBeFalse();                    // empty tag
});
