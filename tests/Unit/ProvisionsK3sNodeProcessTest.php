<?php

/**
 * Tests for ProvisionsK3sNode's standalone read-only kubectl check.
 * syncKubeconfig()/deployTraefik() mix real SSH/scp, Blade view rendering,
 * and certificate-file I/O and are left to a real-machine smoke test; the
 * SSH-touching methods themselves (testSsh/canSudo/runRemoteCommand) belong
 * to InteractsWithRemoteSsh, a separate trait not covered by this pass.
 */

use App\Traits\ProvisionsK3sNode;
use Illuminate\Support\Facades\Process;

function k3sNodeHelper(): object
{
    return new class
    {
        use ProvisionsK3sNode;

        public function traefikInstalled(string $context): bool
        {
            return $this->traefikInstalledOnContext($context);
        }
    };
}

test('traefikInstalledOnContext reflects whether the traefik Deployment exists on that context', function () {
    // Pinned to ~/.kube/config explicitly (see kubectlPinned()) so this never
    // silently follows a shell $KUBECONFIG pointed elsewhere.
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config'))." kubectl --context 'larakube-1.2.3.4'";

    Process::fake(["{$kubectl} get deployment -n traefik traefik" => Process::result(exitCode: 0)]);
    expect(k3sNodeHelper()->traefikInstalled('larakube-1.2.3.4'))->toBeTrue();

    Process::fake(["{$kubectl} get deployment -n traefik traefik" => Process::result(exitCode: 1)]);
    expect(k3sNodeHelper()->traefikInstalled('larakube-1.2.3.4'))->toBeFalse();
});
