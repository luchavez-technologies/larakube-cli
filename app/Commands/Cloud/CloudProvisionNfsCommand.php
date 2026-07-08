<?php

namespace App\Commands\Cloud;

use App\Data\ConfigData;
use App\Traits\InteractsWithClusterContext;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class CloudProvisionNfsCommand extends Command
{
    use InteractsWithClusterContext, LaraKubeOutput, StreamsProcessOutput;

    protected $signature = 'cloud:init:nfs
                            {--context= : Target a specific kube-context}
                            {--size=10Gi : Size of the block volume backing the NFS share}
                            {--storage-class= : Block StorageClass for the backing volume (default: cluster default, e.g. do-block-storage)}
                            {--retain : Keep the PersistentVolume (reclaimPolicy: Retain) when a PVC is deleted}';

    /**
     * Backward-compatible alias for the pre-rename command name.
     *
     * @var array<int, string>
     */
    protected $aliases = ['cloud:provision:nfs'];

    protected $description = '[Experimental] In-cluster NFS for RWX shared storage — works on some clusters but NOT DOKS (mount hangs). Prefer externalizing to object storage + Redis';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('Provision in-cluster NFS (shared ReadWriteMany storage)');
        $this->newLine();

        $context = $this->option('context') ?: $this->askForClusterContext();
        if (! $context) {
            $this->laraKubeError('No Kubernetes context selected.');

            return 1;
        }

        $ctx = escapeshellarg($context);
        $this->line("  <fg=gray>Target context:</> <fg=cyan>{$context}</>");
        $this->newLine();

        // Idempotent: the StorageClass is the marker.
        $found = Process::run("kubectl --context {$ctx} get storageclass ".escapeshellarg(ConfigData::NFS_STORAGE_CLASS).' -o name')->successful();
        if ($found) {
            $this->laraKubeInfo("ℹ️  '".ConfigData::NFS_STORAGE_CLASS."' is already installed — nothing to do.");

            return 0;
        }

        if (! $this->preflight($context)) {
            return 1;
        }

        $size = (string) ($this->option('size') ?: '10Gi');
        $storageClass = trim((string) $this->option('storage-class'));

        $serverView = ['size' => $size];
        if ($storageClass !== '') {
            $serverView['storageClass'] = $storageClass;
        }

        // PHASE 1 — the NFS server. Apply it and wait until it's actually SERVING
        // (readiness probe on :2049), not just scheduled. The provisioner mounts this
        // export at pod-create time, so it must be up first or the provisioner pod
        // wedges in ContainerCreating (the classic failure).
        // A pre-existing nfs-server Service with a ClusterIP can't be patched to
        // headless (clusterIP is immutable) — delete it first so the headless one
        // applies. Only when it's actually non-headless, so re-runs stay clean.
        $existingClusterIp = trim(Process::run("kubectl --context {$ctx} -n nfs get svc nfs-server -o jsonpath=".escapeshellarg('{.spec.clusterIP}'))->output());
        if ($existingClusterIp !== '' && $existingClusterIp !== 'None') {
            Process::run("kubectl --context {$ctx} -n nfs delete svc nfs-server --ignore-not-found");
        }

        $this->laraKubeInfo('1/2 Installing the NFS server...');
        if (! $this->applyView($context, 'k8s.nfs.server', $serverView)) {
            return 1;
        }
        if (! $this->waitForRollout($context, 'nfs-server', 180, 'app=nfs-server')) {
            return 1;
        }

        // PHASE 2 — the provisioner + StorageClass, now that the server serves.
        $this->laraKubeInfo('2/2 Installing the provisioner...');
        $provisionerView = [
            'archiveOnDelete' => 'true',                                // safer default: archive, don't delete
            'reclaimPolicy' => $this->option('retain') ? 'Retain' : 'Delete',
        ];
        if (! $this->applyView($context, 'k8s.nfs.provisioner', $provisionerView)) {
            return 1;
        }
        if (! $this->waitForRollout($context, 'nfs-provisioner', 120, 'app=nfs-provisioner')) {
            return 1;
        }

        // Prove it actually works — provision a throwaway RWX PVC and confirm it Binds.
        if (! $this->smokeTest($context)) {
            return 1;
        }

        $this->newLine();
        $this->laraKubeInfo("✅ NFS ready. StorageClass '".ConfigData::NFS_STORAGE_CLASS."' provides ReadWriteMany volumes.");
        $this->line('  <fg=gray>Enable shared storage for an env with</> <fg=yellow>"sharedStorage": true</> <fg=gray>in that env, then redeploy.</>');
        $this->line('  <fg=gray>Note: the NFS server is a single pod (soft SPOF for storage; app pods stay HA).</>');

        return 0;
    }

    /** Sanity checks before touching the cluster. */
    protected function preflight(string $context): bool
    {
        $ctx = escapeshellarg($context);

        // NFS only earns its keep across multiple nodes — a single node uses RWO directly.
        $nodes = trim(Process::run("kubectl --context {$ctx} get nodes -o name")->output());
        $nodeCount = $nodes === '' ? 0 : count(explode("\n", $nodes));
        if ($nodeCount <= 1) {
            $this->laraKubeWarn("This cluster has {$nodeCount} node — shared NFS is only needed across multiple nodes. On a single node, RWO block storage already works.");
            if (! $this->confirmStep('Install NFS anyway?')) {
                return false;
            }
        }

        // The backing PVC needs a block StorageClass. With none given and no cluster
        // default, the PVC would hang Pending forever — catch it up front.
        if (trim((string) $this->option('storage-class')) === '') {
            $scJson = Process::run("kubectl --context {$ctx} get storageclass -o json")->output();
            if (! str_contains($scJson, 'is-default-class": "true"') && ! str_contains($scJson, 'is-default-class":"true"')) {
                $this->laraKubeError('No default block StorageClass for the NFS server\'s backing volume. Pass --storage-class=<name> (e.g. do-block-storage).');

                return false;
            }
        }

        // Be explicit about the tradeoff — the server pod is a soft SPOF.
        $this->laraKubeWarn('The NFS server is a SINGLE pod: a soft single-point-of-failure for STORAGE (your app pods stay HA). For production-grade shared storage, externalize to object storage + Redis instead.');

        return $this->confirmStep('Proceed with the in-cluster NFS install?');
    }

    /** A yes/no gate that auto-proceeds under --no-interaction. */
    protected function confirmStep(string $label): bool
    {
        if ($this->option('no-interaction')) {
            return true;
        }
        if (! confirm($label, default: true)) {
            $this->laraKubeInfo('Cancelled.');

            return false;
        }

        return true;
    }

    /** Render a manifest view and apply it. */
    protected function applyView(string $context, string $view, array $data): bool
    {
        $tmp = sys_get_temp_dir().'/larakube-'.str_replace('.', '-', $view).'.yaml';
        file_put_contents($tmp, view($view, $data)->render());
        $code = $this->runStreaming('kubectl --context '.escapeshellarg($context).' apply -f '.escapeshellarg($tmp).' --request-timeout=60s');
        @unlink($tmp);

        if ($code !== 0) {
            $this->laraKubeError("Failed to apply {$view}.");

            return false;
        }

        return true;
    }

    /** Wait for a deployment to roll out; on timeout, surface the pod's Events. */
    protected function waitForRollout(string $context, string $deploy, int $timeout, string $selector): bool
    {
        $ctx = escapeshellarg($context);
        $code = $this->runStreaming("kubectl --context {$ctx} rollout status deploy/{$deploy} -n nfs --timeout={$timeout}s", $timeout + 10);

        if ($code !== 0) {
            $this->laraKubeError("'{$deploy}' did not become ready. Recent events:");
            $this->printEvents($context, $selector);
            $this->diagnoseHint($deploy);

            return false;
        }

        return true;
    }

    /** Provision a throwaway RWX PVC and confirm it Binds — proves the class works. */
    protected function smokeTest(string $context): bool
    {
        $ctx = escapeshellarg($context);
        $sc = ConfigData::NFS_STORAGE_CLASS;
        $this->laraKubeInfo('Verifying the StorageClass with a test PVC...');

        $pvc = <<<YAML
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: nfs-smoke-test
  namespace: nfs
spec:
  accessModes:
    - ReadWriteMany
  storageClassName: {$sc}
  resources:
    requests:
      storage: 1Mi
YAML;
        $tmp = sys_get_temp_dir().'/larakube-nfs-smoke.yaml';
        file_put_contents($tmp, $pvc);
        Process::run("kubectl --context {$ctx} apply -f ".escapeshellarg($tmp));
        @unlink($tmp);

        $bound = false;
        for ($i = 0; $i < 30; $i++) {
            $phase = trim(Process::run("kubectl --context {$ctx} -n nfs get pvc nfs-smoke-test -o jsonpath=".escapeshellarg('{.status.phase}'))->output());
            if ($phase === 'Bound') {
                $bound = true;
                break;
            }
            usleep(2_000_000);
        }

        // Clean up the test PVC either way.
        Process::run("kubectl --context {$ctx} -n nfs delete pvc nfs-smoke-test --ignore-not-found");

        if (! $bound) {
            $this->laraKubeError('The test PVC never bound — the StorageClass is installed but not provisioning. Provisioner events:');
            $this->printEvents($context, 'app=nfs-provisioner');

            return false;
        }

        $this->laraKubeInfo('  ✅ Test PVC bound — RWX provisioning works.');

        return true;
    }

    /** Print the Events section of the pods matching a selector. */
    protected function printEvents(string $context, string $selector): void
    {
        $ctx = escapeshellarg($context);
        $describe = Process::run("kubectl --context {$ctx} -n nfs describe pod -l ".escapeshellarg($selector))->output();
        $pos = strpos($describe, 'Events:');
        $this->line($pos !== false ? '  '.str_replace("\n", "\n  ", trim(substr($describe, $pos))) : '  (no events found)');
    }

    /** A targeted hint for the usual node-level mount failure. */
    protected function diagnoseHint(string $deploy): void
    {
        if ($deploy === 'nfs-provisioner') {
            $this->line('  <fg=gray>If the events show</> <fg=yellow>mount.nfs: bad option / no such device / operation not permitted</><fg=gray>,</>');
            $this->line('  <fg=gray>the node can\'t do NFS mounts — in-cluster NFS won\'t work here; externalize storage instead.</>');
        }
    }
}
