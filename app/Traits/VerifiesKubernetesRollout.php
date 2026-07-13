<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

/**
 * Shared "apply a manifest, then actually verify it rolled out" mechanics —
 * extracted after the same false-success bug turned up independently in all
 * three of LaraKube's Traefik installers (local, VPS, DOKS): a successful
 * `kubectl apply` only means the API server accepted the manifest, not that
 * the pod ever came up, but each installer printed success unconditionally
 * regardless. One shared, tested implementation instead of three drifting
 * copies of the same fix.
 */
trait VerifiesKubernetesRollout
{
    use LaraKubeOutput;

    /**
     * Apply a rendered manifest file, then wait for the named Deployment to
     * actually roll out. Returns false (with an error already printed) the
     * moment either step fails, so callers never report success past a
     * failure the way all three installers used to.
     *
     * @param  string  $kubectl  the full kubectl invocation prefix (e.g. "kubectl", "KUBECONFIG=... kubectl --context X")
     * @param  string  $extraApplyFlags  appended verbatim to the apply command (e.g. '--validate=false')
     */
    protected function applyAndVerifyRollout(string $kubectl, string $manifestPath, string $namespace, string $deployment, int $timeoutSeconds = 120, string $extraApplyFlags = ''): bool
    {
        $applyFlags = $extraApplyFlags !== '' ? ' '.$extraApplyFlags : '';
        if (! Process::run("{$kubectl} apply -f ".escapeshellarg($manifestPath).' --request-timeout=60s'.$applyFlags)->successful()) {
            $this->laraKubeError("Could not apply the {$deployment} manifest — see the output above.");

            return false;
        }

        if (! Process::run("{$kubectl} rollout status deployment/{$deployment} -n ".escapeshellarg($namespace)." --timeout={$timeoutSeconds}s")->successful()) {
            $this->laraKubeError("{$deployment} manifest applied, but the Deployment never became Ready — check `kubectl get pods -n {$namespace}`.");

            return false;
        }

        return true;
    }
}
