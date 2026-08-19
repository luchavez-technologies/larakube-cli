<?php

/**
 * Tests for InteractsWithRemoteDeploy's Process-backed read-only checks.
 * The build/push/sideload/rollout paths (runStreaming()) and the full
 * deployViaSshSideload()/deployViaRegistry()/applyScopedDeploy() orchestration
 * are left to a real droplet smoke test — same boundary RemoteDeployTest.php
 * already draws for the pure command-builders.
 */

use App\Traits\InteractsWithRemoteDeploy;
use Illuminate\Support\Facades\Process;

function remoteDeployProcessHelper(): object
{
    return new class
    {
        use InteractsWithRemoteDeploy;

        public function reachable(string $context): bool
        {
            return $this->remoteContextReachable($context);
        }

        public function sshPlatform(string $sshBase): ?string
        {
            return $this->detectNodePlatformOverSsh($sshBase);
        }

        public function kubectlPlatform(string $context): ?string
        {
            return $this->detectNodePlatformViaKubectl($context);
        }

        public function digest(string $registryImage): ?string
        {
            return $this->resolvePushedDigest($registryImage);
        }

        protected function kustomizeBin(): ?string
        {
            return null;
        }
    };
}

test('remoteContextReachable reflects cluster-info exit code', function (): void {
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config'))." kubectl --context 'larakube-1.2.3.4'";

    Process::fake(["{$kubectl} cluster-info --request-timeout=5s" => Process::result(exitCode: 0)]);
    expect(remoteDeployProcessHelper()->reachable('larakube-1.2.3.4'))->toBeTrue();

    Process::fake(["{$kubectl} cluster-info --request-timeout=5s" => Process::result(exitCode: 1)]);
    expect(remoteDeployProcessHelper()->reachable('larakube-1.2.3.4'))->toBeFalse();
});

test('detectNodePlatformOverSsh maps the remote uname -m to a docker platform', function (): void {
    $sshBase = "ssh -o StrictHostKeyChecking=no -i '/key' -p 22 'larakube@1.2.3.4'";
    Process::fake(["{$sshBase} 'uname -m'" => "x86_64\n"]);

    expect(remoteDeployProcessHelper()->sshPlatform($sshBase))->toBe('linux/amd64');
});

test('detectNodePlatformViaKubectl only resolves when every node agrees on architecture', function (): void {
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config'))." kubectl --context 'ctx'";

    Process::fake(["{$kubectl} get nodes -o 'jsonpath={.items[*].status.nodeInfo.architecture}'" => "amd64 amd64\n"]);
    expect(remoteDeployProcessHelper()->kubectlPlatform('ctx'))->toBe('linux/amd64');

    Process::fake(["{$kubectl} get nodes -o 'jsonpath={.items[*].status.nodeInfo.architecture}'" => "amd64 arm64\n"]);
    expect(remoteDeployProcessHelper()->kubectlPlatform('ctx'))->toBeNull();
});

test('resolvePushedDigest only accepts a well-formed sha256 digest', function (): void {
    $sha = 'sha256:'.str_repeat('a', 64);
    Process::fake(["docker buildx imagetools inspect 'ghcr.io/me/app:abc' --format '{{.Manifest.Digest}}'" => $sha."\n"]);
    expect(remoteDeployProcessHelper()->digest('ghcr.io/me/app:abc'))->toBe($sha);

    Process::fake(["docker buildx imagetools inspect 'ghcr.io/me/app:abc' --format '{{.Manifest.Digest}}'" => Process::result(output: '', exitCode: 1)]);
    expect(remoteDeployProcessHelper()->digest('ghcr.io/me/app:abc'))->toBeNull();
});
