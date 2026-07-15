<?php

/**
 * Tests for the multi-node storage guard: when an env counts as multi-node,
 * which .env state drivers are still on local storage (and so lost on per-pod
 * ephemeral disk), and whether the NFS StorageClass is installed. The latter
 * shells out via the Process facade (see app/Traits/GuardsSharedStorage.php),
 * faked below — no longer needs a real deploy to exercise. The actual
 * prompt-driven guardSharedStorage() flow still does.
 */

use App\Data\ConfigData;
use App\Enums\DeploymentStrategy;
use App\Traits\GuardsSharedStorage;
use App\Traits\ResolvesEnvironmentContext;
use Illuminate\Support\Facades\Process;

function storageGuard(): object
{
    return new class
    {
        // GuardsSharedStorage's own docblock documents this as a required
        // companion (nfsStorageClassPresent() calls contextKubectl()).
        use GuardsSharedStorage, ResolvesEnvironmentContext;

        public function multi(ConfigData $c, string $e, ?string $ctx): bool
        {
            return $this->isMultiNode($c, $e, $ctx);
        }

        public function risky(ConfigData $c, string $e): array
        {
            return $this->localStateDrivers($c, $e);
        }

        public function nfsPresent(string $context): bool
        {
            return $this->nfsStorageClassPresent($context);
        }
    };
}

test('multi-node-ha strategy is multi-node without probing the cluster', function () {
    $config = new ConfigData(name: 'app');
    $config->setStrategy(DeploymentStrategy::MULTI_NODE_HA);

    expect(storageGuard()->multi($config, 'production', null))->toBeTrue();
});

test('single-node strategy with no cluster context is not multi-node', function () {
    $config = new ConfigData(name: 'app');
    $config->setStrategy(DeploymentStrategy::SINGLE_NODE);

    expect(storageGuard()->multi($config, 'production', null))->toBeFalse();
});

test('local state drivers flag file-based session/cache and non-object-store uploads', function () {
    $dir = sys_get_temp_dir().'/lk-guard-'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/.env.production", "FILESYSTEM_DISK=local\nSESSION_DRIVER=file\nCACHE_STORE=redis\n");
    $config = new ConfigData(name: 'app', path: $dir);

    $risky = storageGuard()->risky($config, 'production');

    expect($risky)->toHaveCount(2)
        ->and(implode(' ', $risky))->toContain('FILESYSTEM_DISK=local')
        ->and(implode(' ', $risky))->toContain('SESSION_DRIVER=file');

    unlink("$dir/.env.production");
    rmdir($dir);
});

test('local state drivers are clean when uploads use S3 and session/cache are externalized', function () {
    $dir = sys_get_temp_dir().'/lk-guard-'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/.env.production", "FILESYSTEM_DISK=s3\nSESSION_DRIVER=database\nCACHE_STORE=redis\n");
    $config = new ConfigData(name: 'app', path: $dir);

    expect(storageGuard()->risky($config, 'production'))->toBe([]);

    unlink("$dir/.env.production");
    rmdir($dir);
});

test('nfsStorageClassPresent reflects whether the larakube-nfs StorageClass exists on the cluster', function () {
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config'))." kubectl --context 'prod-ctx'";

    Process::fake(["{$kubectl} get storageclass 'larakube-nfs' -o name" => Process::result(exitCode: 0)]);
    expect(storageGuard()->nfsPresent('prod-ctx'))->toBeTrue();

    Process::fake(["{$kubectl} get storageclass 'larakube-nfs' -o name" => Process::result(exitCode: 1)]);
    expect(storageGuard()->nfsPresent('prod-ctx'))->toBeFalse();
});
