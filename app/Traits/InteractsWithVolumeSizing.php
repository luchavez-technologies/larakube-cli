<?php

namespace App\Traits;

use Closure;
use Illuminate\Support\Facades\Process;

/**
 * Keeps a PersistentVolumeClaim's declared size from fighting the cluster.
 *
 * A PVC's `storage:` lives in a Blade template, but a volume can be grown on a
 * live cluster (`storage:resize`). Kubernetes only ever permits a PVC to GROW,
 * so a template that re-applies its original literal after a resize is rejected
 * outright by the API server — the tool's `:init` breaks permanently until
 * someone hand-edits the manifest. Same class of trap as ADR 0018.
 *
 * The cluster is therefore the source of truth for a volume that already
 * exists, and the template default is the floor for one that does not.
 * See docs/decisions/0023-cluster-tool-volume-sizing-and-growth.md.
 */
trait InteractsWithVolumeSizing
{
    /** Set once a growth-volume warning has been emitted, so one run warns once. */
    protected bool $warnedUnexpandableGrowth = false;

    /**
     * A closure for Blade: `storage: {{ $volumeSize('chat-synapse-data', '5Gi', true) }}`.
     *
     * Returns whichever is larger — the live claim or the template's default —
     * so a resize survives re-apply AND a raised default still takes effect.
     * One live lookup per namespace, not per claim.
     *
     * The third argument marks a GROWTH volume (ADR 0023): one whose size is
     * driven by inbound data nobody gates — user uploads, received mail, chat
     * media, git pushes, retained metrics. Declaring it at the volume keeps the
     * classification next to the thing it describes rather than in a central
     * table that drifts. A growth volume on a StorageClass that cannot expand
     * is warned about, never blocked — `local` is disposable and `local-path`
     * is the right answer there — but it must not be silent, because the
     * failure is a full node months later with no prior signal.
     *
     * @return Closure(string, string, bool): string
     */
    protected function volumeSizeResolver(string $kubectl, string $namespace): Closure
    {
        $live = $this->liveVolumeSizes($kubectl, $namespace);
        $expandable = $this->storageClassAllowsExpansion($kubectl, null);

        return function (string $claim, string $default, bool $growth = false) use ($live, $expandable): string {
            if ($growth && ! $expandable && ! $this->warnedUnexpandableGrowth) {
                $this->warnedUnexpandableGrowth = true;
                $this->laraKubeWarn(
                    "This cluster's default StorageClass cannot expand a volume, and this tool claims one that "
                    .'grows with use. Its declared size is advisory — nothing enforces it and nothing can raise '
                    .'it in place. See ADR 0023.',
                );
            }

            $current = $live[$claim] ?? null;

            if ($current === null) {
                return $default;
            }

            return $this->quantityToBytes($current) > $this->quantityToBytes($default)
                ? $current
                : $default;
        };
    }

    /**
     * Every claim in a namespace and its requested size, as `[name => '5Gi']`.
     *
     * Reads `spec.resources.requests`, not `status.capacity`: on a hostPath
     * provisioner (local-path) capacity is just the request echoed back, and
     * on a real one an in-flight expansion leaves the two disagreeing until
     * the resize completes. The request is what the next apply is compared
     * against, so the request is what matters here.
     *
     * @return array<string, string>
     */
    protected function liveVolumeSizes(string $kubectl, string $namespace): array
    {
        $result = Process::run(
            "{$kubectl} get pvc -n {$namespace} -o jsonpath='{range .items[*]}{.metadata.name}={.spec.resources.requests.storage}{\"\\n\"}{end}'",
        );

        if (! $result->successful()) {
            return [];
        }

        $sizes = [];

        foreach (explode("\n", trim($result->output())) as $line) {
            $line = trim($line);

            if ($line === '' || ! str_contains($line, '=')) {
                continue;
            }

            [$name, $size] = explode('=', $line, 2);

            if ($name !== '' && $size !== '') {
                $sizes[$name] = $size;
            }
        }

        return $sizes;
    }

    /**
     * A Kubernetes resource quantity as bytes, for comparison only.
     *
     * Handles the binary (Ki/Mi/Gi/Ti/Pi) and decimal (k/M/G/T/P) suffixes
     * Kubernetes accepts on storage. An unparseable value returns 0 so it
     * always loses a max() comparison rather than silently winning one.
     */
    protected function quantityToBytes(string $quantity): int
    {
        $quantity = trim($quantity);

        if (! preg_match('/^(\d+(?:\.\d+)?)\s*([EPTGMk]i?)?$/', $quantity, $m)) {
            return 0;
        }

        $multipliers = [
            '' => 1,
            'k' => 1000, 'M' => 1000 ** 2, 'G' => 1000 ** 3, 'T' => 1000 ** 4, 'P' => 1000 ** 5, 'E' => 1000 ** 6,
            'Ki' => 1024, 'Mi' => 1024 ** 2, 'Gi' => 1024 ** 3, 'Ti' => 1024 ** 4, 'Pi' => 1024 ** 5, 'Ei' => 1024 ** 6,
        ];

        return (int) ((float) $m[1] * ($multipliers[$m[2] ?? ''] ?? 1));
    }

    /**
     * Whether the StorageClass backing a claim supports in-place expansion.
     *
     * A claim with no explicit `storageClassName` uses the cluster default,
     * which is what every cluster-tool PVC does today — so resolve the default
     * rather than assuming the field is set.
     */
    protected function storageClassAllowsExpansion(string $kubectl, ?string $storageClass): bool
    {
        $storageClass ??= $this->defaultStorageClass($kubectl);

        if ($storageClass === null || $storageClass === '') {
            return false;
        }

        $result = Process::run(
            "{$kubectl} get storageclass {$storageClass} -o jsonpath='{.allowVolumeExpansion}'",
        );

        return $result->successful() && trim($result->output()) === 'true';
    }

    /** The cluster's default StorageClass name, or null when none is marked default. */
    protected function defaultStorageClass(string $kubectl): ?string
    {
        $result = Process::run(
            "{$kubectl} get storageclass -o jsonpath='{range .items[?(@.metadata.annotations.storageclass\\.kubernetes\\.io/is-default-class==\"true\")]}{.metadata.name}{\"\\n\"}{end}'",
        );

        if (! $result->successful()) {
            return null;
        }

        $name = trim(strtok(trim($result->output()), "\n") ?: '');

        return $name === '' ? null : $name;
    }
}
