<?php

/**
 * InteractsWithClusterContext migrated to Illuminate\Support\Facades\Process
 * (see app/Traits/InteractsWithClusterContext.php), so its own tests use
 * Process::fake() rather than the App\Traits shell_exec() override below —
 * that override is still declared here (and still needed, unchanged) for
 * every OTHER App\Traits file that still calls raw shell_exec() for kubectl;
 * see tests/Support/KubectlExecMock.php's docblock for the fuller list.
 */

namespace App\Traits {
    function shell_exec($command)
    {
        // Per-command mock (command string -> output), for tests that need
        // different canned responses for different commands in one test —
        // checked before the single-output mock below.
        if (isset($GLOBALS['mock_shell_exec_callback']) && is_callable($GLOBALS['mock_shell_exec_callback'])) {
            return ($GLOBALS['mock_shell_exec_callback'])($command);
        }

        if (array_key_exists('mock_shell_exec_output', $GLOBALS)) {
            return $GLOBALS['mock_shell_exec_output'];
        }

        return \shell_exec($command);
    }
}

namespace Tests\Feature {

    use App\Traits\InteractsWithClusterContext;
    use Illuminate\Support\Facades\Process;

    function clusterContext(): object
    {
        return new class
        {
            use InteractsWithClusterContext;

            public function testIsLocalContext(): bool
            {
                return $this->isLocalContext();
            }

            public function testHasActiveCluster(): bool
            {
                return $this->hasActiveCluster();
            }

            public function testHasAnyContext(): bool
            {
                return $this->hasAnyContext();
            }

            public function testFindLocalClusterContext(): ?string
            {
                return $this->findLocalClusterContext();
            }

            public function testIsK3dClusterRunning(): bool
            {
                return $this->isK3dClusterRunning();
            }

            public function testSwitchClusterContext(string $name): bool
            {
                return $this->switchClusterContext($name);
            }
        };
    }

    test('isLocalContext identifies local clusters', function () {
        // Each of these names alone satisfies isLocalContextName(), so the
        // fallback "kubectl config view" server check is never reached.
        $localContexts = ['k3d-larakube', 'minikube', 'docker-desktop', 'orbstack', 'kind-cluster', 'colima'];

        foreach ($localContexts as $context) {
            Process::fake(['kubectl config current-context' => $context]);
            expect(clusterContext()->testIsLocalContext())->toBeTrue("Failed for context: $context");
        }
    });

    test('isLocalContext identifies remote clusters by name, without needing the server fallback', function () {
        $remoteContexts = ['gke_project_zone_cluster', 'arn:aws:eks:us-west-2:123456789012:cluster/prod', 'do-nyc1-my-cluster'];

        foreach ($remoteContexts as $context) {
            Process::fake([
                'kubectl config current-context' => $context,
                'kubectl config view --minify -o jsonpath=*' => 'https://203.0.113.10:6443',
            ]);
            expect(clusterContext()->testIsLocalContext())->toBeFalse("Failed for context: $context");
        }
    });

    test('isLocalContext falls back to the API server address when the context name is unrecognized', function () {
        Process::fake([
            'kubectl config current-context' => 'default',
            'kubectl config view --minify -o jsonpath=*' => 'https://127.0.0.1:6443',
        ]);

        expect(clusterContext()->testIsLocalContext())->toBeTrue();
    });

    test('isLocalContext returns false when there is no current context', function () {
        Process::fake(['kubectl config current-context' => Process::result(output: '', exitCode: 1)]);

        expect(clusterContext()->testIsLocalContext())->toBeFalse();
    });

    test('hasActiveCluster is false without a current context, and reflects cluster-info reachability otherwise', function () {
        Process::fake(['kubectl config current-context' => Process::result(output: '', exitCode: 1)]);
        expect(clusterContext()->testHasActiveCluster())->toBeFalse();

        Process::fake([
            'kubectl config current-context' => 'k3d-larakube',
            'kubectl cluster-info --request-timeout=2s' => Process::result(exitCode: 0),
        ]);
        expect(clusterContext()->testHasActiveCluster())->toBeTrue();

        Process::fake([
            'kubectl config current-context' => 'k3d-larakube',
            'kubectl cluster-info --request-timeout=2s' => Process::result(exitCode: 1),
        ]);
        expect(clusterContext()->testHasActiveCluster())->toBeFalse();
    });

    test('hasAnyContext reflects whether kubeconfig has any contexts at all', function () {
        Process::fake(['kubectl config get-contexts -o name' => "k3d-larakube\norbstack\n"]);
        expect(clusterContext()->testHasAnyContext())->toBeTrue();

        Process::fake(['kubectl config get-contexts -o name' => Process::result(output: '', exitCode: 1)]);
        expect(clusterContext()->testHasAnyContext())->toBeFalse();
    });

    test('findLocalClusterContext prefers a LaraKube-provisioned context over a generic local one', function () {
        Process::fake(['kubectl config get-contexts -o name' => "orbstack\nk3d-larakube\ngke_remote\n"]);
        expect(clusterContext()->testFindLocalClusterContext())->toBe('k3d-larakube');

        Process::fake(['kubectl config get-contexts -o name' => "orbstack\ngke_remote\n"]);
        expect(clusterContext()->testFindLocalClusterContext())->toBe('orbstack');

        Process::fake(['kubectl config get-contexts -o name' => "gke_remote\ndo-nyc1\n"]);
        expect(clusterContext()->testFindLocalClusterContext())->toBeNull();
    });

    test('isK3dClusterRunning reads the SERVERS column from k3d cluster list', function () {
        Process::fake(["k3d cluster list 'larakube' --no-headers" => 'larakube   1/1   0/0   true']);
        expect(clusterContext()->testIsK3dClusterRunning())->toBeTrue();

        Process::fake(["k3d cluster list 'larakube' --no-headers" => 'larakube   0/1   0/0   true']);
        expect(clusterContext()->testIsK3dClusterRunning())->toBeFalse();
    });

    test('switchClusterContext reflects whether kubectl config use-context succeeded', function () {
        Process::fake(["kubectl config use-context 'k3d-larakube'" => Process::result(exitCode: 0)]);
        expect(clusterContext()->testSwitchClusterContext('k3d-larakube'))->toBeTrue();

        Process::fake(["kubectl config use-context 'missing-ctx'" => Process::result(exitCode: 1)]);
        expect(clusterContext()->testSwitchClusterContext('missing-ctx'))->toBeFalse();
    });
}
