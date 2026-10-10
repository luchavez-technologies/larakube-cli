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

test('provisionK3sNode records a traefikWarning when the Traefik step fails, but still returns the context name', function (): void {
    // Every other step is stubbed out (real SSH/scp/cert I/O, same reason
    // deployTraefik() itself isn't exercised here) so this isolates ONLY the
    // Traefik-failure propagation this test is about.
    $helper = new class
    {
        use ProvisionsK3sNode;

        public function hardenServer(...$args): void {}

        public function createLaraKubeUser(...$args): void {}

        public function installK3s(...$args): bool
        {
            return true;
        }

        public function waitForK3sReady(...$args): bool
        {
            return true;
        }

        public function syncKubeconfig(...$args): bool
        {
            return true;
        }

        public function deployTraefik(...$args): bool
        {
            return false;
        }

        public function line(...$args): void {}

        public function run(): string
        {
            return $this->provisionK3sNode('larakube', '203.0.113.9', '22', '/tmp/key', null, interactive: false);
        }

        public function warning(): ?string
        {
            return $this->traefikWarning;
        }
    };

    $context = $helper->run();

    expect($context)->toBe('larakube-203.0.113.9')
        ->and($helper->warning())->toContain('Traefik deploy failed');
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
