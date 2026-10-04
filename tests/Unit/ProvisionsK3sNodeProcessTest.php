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

test('traefikInstalledOnContext reflects whether the traefik Deployment exists on that context', function (): void {
    // Pinned to ~/.kube/config explicitly (see kubectlPinned()) so this never
    // silently follows a shell $KUBECONFIG pointed elsewhere.
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config'))." kubectl --context 'larakube-1.2.3.4'";

    Process::fake(["{$kubectl} get deployment -n traefik traefik" => Process::result(exitCode: 0)]);
    expect(k3sNodeHelper()->traefikInstalled('larakube-1.2.3.4'))->toBeTrue();

    Process::fake(["{$kubectl} get deployment -n traefik traefik" => Process::result(exitCode: 1)]);
    expect(k3sNodeHelper()->traefikInstalled('larakube-1.2.3.4'))->toBeFalse();
});

test('k3s is installed with the short host name as its node name, so a long cloud host name cannot stop the node registering', function (): void {
    Process::fake(['*' => Process::result(output: 'ok')]);

    $helper = new class
    {
        use ProvisionsK3sNode;

        public function install(): bool
        {
            return $this->installK3s('root', '203.0.113.9', '22', '/tmp/key', null);
        }
    };
    $helper->install();

    // The script goes over SSH either as the command or on its standard input.
    $sent = [];
    Process::assertRan(function ($process) use (&$sent): bool {
        $sent[] = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command).' '.(string) ($process->input ?? '');

        return true;
    });

    expect(implode("\n", $sent))->toContain('--node-name="$(hostname -s)"');
});
