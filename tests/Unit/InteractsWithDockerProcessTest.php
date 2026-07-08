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
    };
}

test('imageExists reflects whether docker images -q returned an id', function () {
    Process::fake(["docker images -q 'app:local'" => "sha256:abc123\n"]);
    expect(dockerProcessHelper()->imageExistsCheck('app:local'))->toBeTrue();

    Process::fake(["docker images -q 'app:local'" => Process::result(output: '', exitCode: 1)]);
    expect(dockerProcessHelper()->imageExistsCheck('app:local'))->toBeFalse();
});

test('hostUid/hostGid parse the id command output', function () {
    Process::fake(['id -u' => "1000\n"]);
    expect(dockerProcessHelper()->uid())->toBe(1000);

    Process::fake(['id -g' => "1000\n"]);
    expect(dockerProcessHelper()->gid())->toBe(1000);
});

test('hostUid falls back to posix_getuid when id produces no usable digits', function () {
    Process::fake(['id -u' => Process::result(output: '', exitCode: 1)]);

    expect(dockerProcessHelper()->uid())->toBeInt();
});

test('imageInActiveCluster is true (nothing to sideload) for a remote/registry context', function () {
    Process::fake(['kubectl config current-context' => "arn:aws:eks:us-east-1:123:cluster/prod\n"]);

    expect(dockerProcessHelper()->inActiveCluster('app:local'))->toBeTrue();
});

test('imageInActiveCluster is null for native k3s when sudo is not cached (never prompts)', function () {
    Process::fake([
        'kubectl config current-context' => "k3s-larakube\n",
        'sudo -n true' => Process::result(exitCode: 1),
    ]);

    expect(dockerProcessHelper()->inActiveCluster('app:local'))->toBeNull();
});

test('imageInActiveCluster is null for a k3d cluster whose server node is not running', function () {
    Process::fake([
        'kubectl config current-context' => "k3d-demo\n",
        'docker inspect -f "{{.State.Running}}" \'k3d-demo-server-0\'' => "false\n",
    ]);

    expect(dockerProcessHelper()->inActiveCluster('app:local'))->toBeNull();
});

test('imageInActiveCluster matches the crictl listing on a running k3d server node', function () {
    Process::fake([
        'kubectl config current-context' => "k3d-demo\n",
        'docker inspect -f "{{.State.Running}}" \'k3d-demo-server-0\'' => "true\n",
        "docker exec 'k3d-demo-server-0' crictl images" => "app:local\n",
    ]);

    expect(dockerProcessHelper()->inActiveCluster('app:local'))->toBeTrue();
});
