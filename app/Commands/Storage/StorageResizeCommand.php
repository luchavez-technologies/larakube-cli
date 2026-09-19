<?php

namespace App\Commands\Storage;

use App\Services\Kubectl;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithVolumeSizing;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

/**
 * Grow one PersistentVolumeClaim in place.
 *
 * Its own command rather than a flag on something else, because growing a
 * volume is a distinct operation with its own preconditions — see ADR 0023
 * and the no-hidden-flag rule. Deliberately scoped to a SINGLE claim and the
 * single Deployment that mounts it: the command this replaces scaled every
 * Deployment in the namespace to zero, which against `larakube-shared` is a
 * whole-fleet outage to touch one volume.
 */
class StorageResizeCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithVolumeSizing, LaraKubeOutput;

    protected $signature = 'storage:resize
        {environment=local : Environment whose cluster holds the volume}
        {--pvc=       : Name of the PersistentVolumeClaim to grow}
        {--size=      : New size, e.g. 20Gi. Must be larger than the current request}
        {--namespace= : Namespace the claim lives in. Discovered from the claim name when omitted}
        {--context=   : Target a specific kube-context}
        {--force      : Skip the confirmation prompt}';

    protected $description = 'Grow a PersistentVolumeClaim in place, where the StorageClass supports it';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = Kubectl::forContext($context)->prefix();

        $claim = (string) ($this->option('pvc') ?: '');
        $namespace = (string) ($this->option('namespace') ?: '');

        if ($claim === '') {
            $claim = $this->promptForClaim($kubectl);
        }

        if ($claim === '') {
            $this->laraKubeError('No PersistentVolumeClaim given. Pass --pvc=<name>.');

            return 1;
        }

        if ($namespace === '') {
            $namespace = $this->discoverNamespace($kubectl, $claim) ?? '';
        }

        if ($namespace === '') {
            $this->laraKubeError("No PersistentVolumeClaim named '{$claim}' found on this cluster. Pass --namespace= if it lives outside the LaraKube namespaces.");

            return 1;
        }

        $current = $this->liveVolumeSizes($kubectl, $namespace)[$claim] ?? null;

        if ($current === null) {
            $this->laraKubeError("PersistentVolumeClaim '{$claim}' does not exist in namespace '{$namespace}'.");

            return 1;
        }

        $size = (string) ($this->option('size') ?: '');

        if ($size === '' || $this->quantityToBytes($size) === 0) {
            $this->laraKubeError('Pass a valid --size, e.g. --size=20Gi.');

            return 1;
        }

        // Kubernetes rejects a shrink outright rather than truncating, so catch
        // it here with an explanation instead of surfacing the API's message.
        if ($this->quantityToBytes($size) <= $this->quantityToBytes($current)) {
            $this->laraKubeError("'{$claim}' already requests {$current}. A volume can only grow — Kubernetes refuses any smaller value.");

            return 1;
        }

        $storageClass = $this->claimStorageClass($kubectl, $namespace, $claim);

        if (! $this->storageClassAllowsExpansion($kubectl, $storageClass)) {
            return $this->reportUnexpandable($kubectl, $claim, $current, $storageClass);
        }

        $owners = $this->owningDeployments($kubectl, $namespace, $claim);

        $this->laraKubeInfo("Growing '{$claim}' in {$namespace}: {$current} → {$size}");
        $this->line('  <fg=gray>StorageClass:</> <fg=cyan>'.($storageClass ?: $this->defaultStorageClass($kubectl)).'</> <fg=gray>(expandable)</>');
        $this->line('  <fg=gray>Mounted by:</>   <fg=cyan>'.($owners === [] ? 'nothing — unmounted claim' : implode(', ', $owners)).'</>');
        $this->newLine();

        if (! $this->option('force') && ! confirm("Grow '{$claim}' to {$size}?", default: true)) {
            $this->laraKubeInfo('Resize cancelled.');

            return 0;
        }

        $patch = json_encode(['spec' => ['resources' => ['requests' => ['storage' => $size]]]]);

        $patched = $this->withSpin("Patching {$claim}...", fn () => Process::run(
            "{$kubectl} patch pvc {$claim} -n {$namespace} --type=merge -p ".escapeshellarg((string) $patch),
        )->successful());

        if (! $patched) {
            $this->laraKubeError("Failed to patch '{$claim}'. Check kubectl access to the cluster and re-run.");

            return 1;
        }

        $this->newLine();
        $this->laraKubeInfo("✅ '{$claim}' now requests {$size}.");

        // A CSI driver that cannot expand a mounted volume marks the claim
        // FileSystemResizePending and completes on the next pod start. Say so
        // rather than reporting a finished resize that has not happened yet.
        if ($owners !== [] && $this->resizePending($kubectl, $namespace, $claim)) {
            $this->newLine();
            $this->laraKubeWarn(
                'The filesystem grows when the pod restarts — this driver cannot expand a mounted volume. '
                .'Restart '.implode(', ', $owners).' when you are ready.',
            );
        }

        $this->newLine();

        return 0;
    }

    /**
     * Explain, rather than attempt, a resize the StorageClass cannot perform.
     *
     * `local-path` is the case that matters here: it is hostPath-backed, so it
     * neither enforces the request nor can raise it. Changing the number would
     * alter nothing on disk — the volume already has the whole filesystem. The
     * real operation on such a class is a migration to a different one, which
     * is a different job from growing a claim in place.
     */
    protected function reportUnexpandable(string $kubectl, string $claim, string $current, ?string $storageClass): int
    {
        $name = $storageClass ?: ($this->defaultStorageClass($kubectl) ?? 'the default StorageClass');

        $this->laraKubeError("StorageClass '{$name}' does not support volume expansion, so '{$claim}' cannot be grown in place.");
        $this->newLine();
        $this->line("  <fg=gray>Current request:</> <fg=cyan>{$current}</>");
        $this->newLine();

        if (str_contains($name, 'local-path')) {
            $this->line('  <fg=gray>This claim is hostPath-backed. The request is advisory — nothing enforces it,</>');
            $this->line('  <fg=gray>and the volume can already use whatever the node has free. Raising the</>');
            $this->line('  <fg=gray>number would change the manifest and nothing on disk.</>');
            $this->newLine();
        }

        $this->line('  <fg=gray>To get a volume that enforces and grows, move it to an expandable class:</>');
        $this->line('  <fg=gray>`larakube nfs:init` (larakube-nfs) self-hosted, or do-block-storage on DOKS.</>');
        $this->newLine();

        return 1;
    }

    /** Namespaces LaraKube puts claims in, most specific first. */
    protected function discoverNamespace(string $kubectl, string $claim): ?string
    {
        $result = Process::run(
            "{$kubectl} get pvc --all-namespaces -o jsonpath='{range .items[?(@.metadata.name==\"{$claim}\")]}{.metadata.namespace}{\"\\n\"}{end}'",
        );

        if (! $result->successful()) {
            return null;
        }

        $first = trim(strtok(trim($result->output()), "\n") ?: '');

        return $first === '' ? null : $first;
    }

    protected function claimStorageClass(string $kubectl, string $namespace, string $claim): ?string
    {
        $result = Process::run(
            "{$kubectl} get pvc {$claim} -n {$namespace} -o jsonpath='{.spec.storageClassName}'",
        );

        $name = $result->successful() ? trim($result->output()) : '';

        return $name === '' ? null : $name;
    }

    /**
     * The Deployments that mount this claim — never `--all`.
     *
     * @return list<string>
     */
    protected function owningDeployments(string $kubectl, string $namespace, string $claim): array
    {
        $result = Process::run(
            "{$kubectl} get deployments -n {$namespace} -o jsonpath='{range .items[?(@.spec.template.spec.volumes[*].persistentVolumeClaim.claimName==\"{$claim}\")]}{.metadata.name}{\"\\n\"}{end}'",
        );

        if (! $result->successful()) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", trim($result->output())))));
    }

    protected function resizePending(string $kubectl, string $namespace, string $claim): bool
    {
        $result = Process::run(
            "{$kubectl} get pvc {$claim} -n {$namespace} -o jsonpath='{.status.conditions[*].type}'",
        );

        return $result->successful() && str_contains($result->output(), 'FileSystemResizePending');
    }

    protected function promptForClaim(string $kubectl): string
    {
        if ($this->option('no-interaction') || $this->option('force')) {
            return '';
        }

        $result = Process::run(
            "{$kubectl} get pvc --all-namespaces -o jsonpath='{range .items[*]}{.metadata.namespace}/{.metadata.name}={.spec.resources.requests.storage}{\"\\n\"}{end}'",
        );

        $options = [];

        foreach (explode("\n", trim($result->output())) as $line) {
            $line = trim($line);

            if ($line === '' || ! str_contains($line, '=')) {
                continue;
            }

            [$path, $size] = explode('=', $line, 2);
            [, $name] = array_pad(explode('/', $path, 2), 2, '');
            $options[$name] = "{$path} ({$size})";
        }

        if ($options === []) {
            return '';
        }

        return (string) select(label: 'Which volume do you want to grow?', options: $options);
    }
}
