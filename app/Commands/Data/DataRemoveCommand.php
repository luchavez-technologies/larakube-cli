<?php

namespace App\Commands\Data;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use App\Traits\InteractsWithData;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

class DataRemoveCommand extends AbstractToolRemoveCommand
{
    use InteractsWithData;

    protected $signature = 'data:remove
        {environment=local : Environment to remove Data / Headless CMS from}
        {--context=  : Target a specific kube-context (defaults to the environment\'s saved cloud target)}
        {--domain=   : The instance\'s domain/host — the same value you gave data:init, since that IS its identity. Omit for the default instance}
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
        return trim(Process::run(
            "{$kubectl} get secret data-secrets -n {$namespace} --ignore-not-found",
        )->output()) === '';
    }

    /**
     * Two engines can legitimately coexist on a cluster now (as separate
     * named instances), but never under the SAME instance — data:init's
     * engine-swap step tears down the previous engine before applying a new
     * one. So for any single instance, at most one engine should ever be
     * live at once. Deleting both unconditionally (the old behavior, copied
     * from Flow's "always remove both engines" precedent — safe there
     * because Flow has no instance concept) is exactly wrong here: it would
     * take out a real, intentional deployment just because it happens to
     * share this instance name with another engine's stale leftovers.
     * Detect what's actually there instead, and only ever touch that. If
     * both are genuinely deployed, ask (interactively, via flagOrPrompt() —
     * a select() prompt beats making every caller memorize --engine=) rather
     * than guess or hard-fail. Confirmed live 2026-08-08 — this would have
     * deleted both engines at once with no way to target just one.
     */
    protected function teardown(string $kubectl, string $namespace): bool
    {
        $instance = $this->resolveInstance($kubectl);
        // resolveInstance() can return null (unregistered, no --all/--domain
        // — see resolveInstanceTargets()) as well as '' or the legacy literal
        // 'main', all three meaning the same "default instance". Checking
        // only the literal string here silently produced a trailing-dash
        // name ("data-secrets-") for the null/'' cases (ADR 0012, amended
        // 2026-08-15).
        $isDefault = $instance === null || $instance === '' || $instance === 'main';
        $secretName = $isDefault ? 'data-secrets' : "data-secrets-{$instance}";
        $smtpSecret = $isDefault ? 'data-smtp' : "data-smtp-{$instance}";
        $oidcSecret = $isDefault ? 'data-oidc' : "data-oidc-{$instance}";

        // Directus's Service/Ingress are named after deploymentName()
        // (data-directus[-instance]) since the instance-parity fix.
        $directusDeploy = $isDefault ? 'data-directus' : "data-directus-{$instance}";
        $pocketbaseDeploy = ClusterTool::DATA->deploymentName($instance, 'pocketbase');

        $requested = strtolower((string) ($this->option('engine') ?: ''));
        $hasDirectus = $this->deploymentExists($kubectl, $namespace, $directusDeploy);
        $hasPocketbase = $this->deploymentExists($kubectl, $namespace, $pocketbaseDeploy);

        // Ambiguous only when both are actually there — never prompt/require
        // the flag otherwise, so the common single-engine case stays frictionless.
        if ($requested === '' && $hasDirectus && $hasPocketbase) {
            $requested = $this->flagOrPrompt(
                'engine',
                fn () => select(
                    label: "Instance '{$instance}' has both Directus and PocketBase deployed — remove which?",
                    options: [
                        'directus' => 'Directus only',
                        'pocketbase' => 'PocketBase only',
                        'all' => 'Both',
                    ],
                ),
                'which Data engine to remove — both are deployed for this instance',
                '--engine=directus',
            );
        }

        $removeDirectus = $requested === 'directus' || $requested === 'all' || ($requested === '' && $hasDirectus);
        $removePocketbase = $requested === 'pocketbase' || $requested === 'all' || ($requested === '' && $hasPocketbase);

        if (! $removeDirectus && ! $removePocketbase) {
            $this->laraKubeInfo("No Data engine deployment found for instance '{$instance}' — nothing to remove.");

            return true;
        }

        $labels = array_filter([$removeDirectus ? 'Directus' : null, $removePocketbase ? 'PocketBase' : null]);
        $this->laraKubeInfo('Removing '.implode(' and ', $labels)." for instance '{$instance}'...");

        $resources = '';
        if ($removeDirectus) {
            $resources .= "deployment/{$directusDeploy} service/{$directusDeploy} ingress/{$directusDeploy} "
                // Legacy pre-instance-parity names, harmless via --ignore-not-found if absent.
                ."service/data service/data-{$instance} ingress/data ingress/data-{$instance} ";
        }
        if ($removePocketbase) {
            $resources .= "deployment/{$pocketbaseDeploy} service/{$pocketbaseDeploy} "
                ."ingress/{$pocketbaseDeploy}-ingress configmap/{$pocketbaseDeploy}-hooks ";
        }

        return $this->removeResources(
            'Removing Data resources...',
            "{$kubectl} delete {$resources}secret/{$secretName} secret/{$smtpSecret} secret/{$oidcSecret} -n {$namespace} --ignore-not-found",
        );
    }
}
