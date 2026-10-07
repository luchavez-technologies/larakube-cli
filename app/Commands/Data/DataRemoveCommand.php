<?php

namespace App\Commands\Data;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;
use App\Traits\InteractsWithData;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

use LogicException;

abstract class DataRemoveCommand extends AbstractToolRemoveCommand
{
    use InteractsWithData;

    protected $signature = 'directus:remove
        {environment=local : Environment to remove Data / Headless CMS from}
        {--context=  : Target a specific kube-context (defaults to the environment\'s saved cloud target)}
        {--domain=   : The instance\'s domain/host — the same value you gave tool:init --tool=directus, since that IS its identity. Omit for the default instance}
        {--engine=   : Restrict removal to "directus", "pocketbase", or "all" — only asked when both are deployed for this instance}
        {--all       : Remove all registered instances of this tool}
        {--purge     : Also destroy persistent data — drop the Plex Commons database and release the Redis index. Irreversible.}
        {--force     : Skip the confirmation prompt (required for non-interactive runs)}';

    protected function tool(): ClusterTool
    {
        return ClusterTool::DATA;
    }

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return false;
    }

    /** The engine whose Deployment serves this instance. */
    protected function instanceEngine(string $kubectl, ?string $instance): ?string
    {
        if ($instance === null || $instance === '') {
            return null;
        }

        foreach (['pocketbase', 'directus', 'wordpress'] as $engine) {
            $names = ToolInstance::forInstance(ClusterTool::DATA, $instance, $engine);
            if ($this->deploymentExists($kubectl, $names->namespace(), $names->deployment())) {
                return $engine;
            }
        }

        $entry = $this->findToolInstanceEntry($kubectl, ClusterTool::DATA, $instance);

        return $entry['engine'] ?? null;
    }

    /**
     * Two engines can legitimately coexist on a cluster now (as separate
     * named instances), but never under the SAME instance — tool:init --tool=directus's
     * engine-swap step tears down the previous engine before applying a new
     * one. So for any single instance, at most one engine should ever be
     * live at once.
     */
    protected function teardown(string $kubectl, string $namespace): bool
    {
        $instance = $this->resolveInstance($kubectl);
        if ($instance === null || $instance === '') {
            $domain = (string) ($this->option('domain') ?? '');
            if ($domain !== '') {
                $instance = $this->tool()->instanceSlugFromHost($domain);
            } else {
                $host = $this->singleRecordedToolHost($kubectl, $this->tool())
                    ?? $this->tool()->service()?->hostFor(\App\Data\GlobalConfigData::load()->getLocalTld());
                $instance = $host !== null ? $this->tool()->instanceSlugFromHost($host) : null;
            }
        }

        $instance ??= throw new LogicException('data: an instance is always a host-derived slug, never empty.');

        $namesPb = ToolInstance::forInstance(ClusterTool::DATA, $instance, 'pocketbase');
        $namesDir = ToolInstance::forInstance(ClusterTool::DATA, $instance, 'directus');
        $namesWp = ToolInstance::forInstance(ClusterTool::DATA, $instance, 'wordpress');

        $directusDeploy = $namesDir->deployment();
        $pocketbaseDeploy = $namesPb->deployment();
        $wpDeploy = $namesWp->deployment();

        $requested = $this->hasOption('engine')
            ? strtolower((string) ($this->option('engine') ?: ''))
            : ($this->instanceEngine($kubectl, $instance) ?? '');
        $hasDirectus = $this->deploymentExists($kubectl, $namespace, $directusDeploy);
        $hasPocketbase = $this->deploymentExists($kubectl, $namespace, $pocketbaseDeploy);
        $hasWp = $this->deploymentExists($kubectl, $namespace, $wpDeploy);

        // Ambiguous only when 2+ are actually there
        $deployedCount = (int) $hasDirectus + (int) $hasPocketbase + (int) $hasWp;
        if ($requested === '' && $deployedCount > 1) {
            $requested = $this->flagOrPrompt(
                'engine',
                fn () => select(
                    label: "Instance '{$instance}' has multiple Data engines deployed — remove which?",
                    options: array_filter([
                        $hasDirectus ? 'directus' : null => 'Directus only',
                        $hasPocketbase ? 'pocketbase' : null => 'PocketBase only',
                        $hasWp ? 'wordpress' : null => 'WordPress only',
                        'all' => 'All',
                    ]),
                ),
                'which Data engine to remove — multiple are deployed for this instance',
                '--engine=all',
            );
        }

        $removeDirectus = $requested === 'directus' || $requested === 'all' || ($requested === '' && $hasDirectus);
        $removePocketbase = $requested === 'pocketbase' || $requested === 'all' || ($requested === '' && $hasPocketbase);
        $removeWp = $requested === 'wordpress' || $requested === 'all' || ($requested === '' && $hasWp);

        // If neither deployment is found and no specific engine requested,
        // clean up all so any lingering resources or PVCs are purged.
        if (! $removeDirectus && ! $removePocketbase && ! $removeWp && $requested === '') {
            $removeDirectus = true;
            $removePocketbase = true;
            $removeWp = true;
        }

        $labels = array_filter([$removeDirectus ? 'Directus' : null, $removePocketbase ? 'PocketBase' : null, $removeWp ? 'WordPress' : null]);
        $this->laraKubeInfo('Removing '.implode(' and ', $labels)." for instance '{$instance}'...");

        $resources = '';
        $secretsToDelete = [];
        if ($removeDirectus) {
            $resources .= "deployment/{$directusDeploy} service/{$directusDeploy} ingress/{$directusDeploy} ";
            $secretsToDelete[] = $namesDir->secret();
            $secretsToDelete[] = $namesDir->secret(SecretKind::SMTP);
            $secretsToDelete[] = $namesDir->secret(SecretKind::OIDC);
        }
        if ($removePocketbase) {
            $resources .= "deployment/{$pocketbaseDeploy} service/{$pocketbaseDeploy} "
                ."ingress/{$pocketbaseDeploy}-ingress configmap/{$namesPb->configMap('hooks')} ";
            $secretsToDelete[] = $namesPb->secret();
            $secretsToDelete[] = $namesPb->secret(SecretKind::SMTP);
            $secretsToDelete[] = $namesPb->secret(SecretKind::OIDC);
        }
        if ($removeWp) {
            $resources .= "deployment/{$wpDeploy} service/{$wpDeploy} ingress/{$wpDeploy} ";
            $secretsToDelete[] = $namesWp->secret();
            $secretsToDelete[] = $namesWp->secret(SecretKind::SMTP);
        }

        $secretArgs = implode(' ', array_map(fn ($s) => "secret/{$s}", array_unique($secretsToDelete)));
        $ok = $this->removeResources(
            'Removing Data resources...',
            "{$kubectl} delete {$resources}{$secretArgs} -n {$namespace} --ignore-not-found",
        );

        if ($removePocketbase && $this->option('purge')) {
            Process::run("{$kubectl} wait --for=delete pod -l app.kubernetes.io/name={$pocketbaseDeploy} -n {$namespace} --timeout=60s 2>/dev/null || true");

            $ok = $this->removeResources(
                'Removing PocketBase storage...',
                "{$kubectl} delete pvc/".$this->pocketbaseVolume($instance)." -n {$namespace} --ignore-not-found",
            ) && $ok;
        }

        if ($removeWp && $this->option('purge')) {
            Process::run("{$kubectl} wait --for=delete pod -l app.kubernetes.io/name={$wpDeploy} -n {$namespace} --timeout=60s 2>/dev/null || true");

            $ok = $this->removeResources(
                'Removing WordPress storage...',
                "{$kubectl} delete pvc/{$namesWp->volume()} -n {$namespace} --ignore-not-found",
            ) && $ok;
        }

        return $ok;
    }

    protected function teardownWarning(string $env): array
    {
        $lines = parent::teardownWarning($env);

        if ($this->option('purge')) {
            $lines[] = 'PocketBase data volume(s) WILL BE DESTROYED: its database, users and uploaded files.';
        }

        return $lines;
    }

    /** The volume tool:init --tool=directus gives a PocketBase instance. */
    private function pocketbaseVolume(string $instance): string
    {
        return ToolInstance::forInstance(ClusterTool::DATA, $instance, 'pocketbase')->volume();
    }
}
