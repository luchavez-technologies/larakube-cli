<?php

namespace App\Commands\Secrets;

use App\Enums\ClusterTool;
use App\Traits\DeploysClusterTool;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class SecretsWireCommand extends Command
{
    use DeploysClusterTool, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment, StreamsProcessOutput, SyncsClusterSecrets;

    protected $signature = 'secrets:wire
        {environment=local : Environment whose deployment(s) to wire}
        {--tool=                : The tool whose Commons DB password OpenBao should take over rotating}
        {--all                  : Wire every installed, DB-rotatable tool}
        {--context=             : Target a specific kube-context}
        {--rotation-period=168h : How often OpenBao rotates the password (default: 7 days)}
        {--force                : Skip the confirmation prompt}';

    protected $description = "Hand a tool's Commons database password over to OpenBao static-role rotation";

    public function handle(): int
    {
        $this->renderHeader();

        $env = $this->resolveToolEnvironment(ClusterTool::SECRETS);
        $context = $this->resolveToolContext($env, $this->option('context'));
        $kubectl = $this->secretsKubectl($context);
        $rotationPeriod = (string) ($this->option('rotation-period') ?: '168h');

        if (! $this->secretsBackendAvailable($kubectl)) {
            $this->laraKubeWarn('OpenBao is not deployed. Run `larakube secrets:init` first.');

            return 1;
        }

        if (! $this->databaseEngineMounted($kubectl)) {
            $this->laraKubeWarn('The OpenBao database secrets engine is not mounted yet.');
            $this->line('  <fg=gray>Run</> <fg=blue>larakube plex:init</> <fg=gray>to wire it (idempotent — safe to re-run).</>');

            return 1;
        }

        if (! $this->kubernetesAuthEnabled($kubectl)) {
            $this->laraKubeWarn('Vault Kubernetes auth is not configured on OpenBao yet.');
            $this->line('  <fg=gray>Run</> <fg=blue>larakube plex:init</> <fg=gray>to wire it (idempotent — safe to re-run).</>');

            return 1;
        }

        $targets = $this->resolveTargets($kubectl);
        if ($targets === []) {
            return 1;
        }

        $label = count($targets) === 1 ? $targets[0]->getLabel() : count($targets).' tool(s)';
        if (! $this->option('force') && ! confirm(
            label: "Hand {$label}'s DB password to OpenBao, rotating it every {$rotationPeriod}?",
            default: true,
        )) {
            return 0;
        }

        $ok = true;
        foreach ($targets as $tool) {
            $ok = $this->wireTool($kubectl, $tool, $rotationPeriod) && $ok;
        }

        return $ok ? 0 : 1;
    }

    /** @return list<ClusterTool> */
    protected function resolveTargets(string $kubectl): array
    {
        $capable = array_filter(ClusterTool::cases(), fn (ClusterTool $t) => $t->dbSecretRef() !== null);

        $installed = array_values(array_filter(
            $capable,
            fn (ClusterTool $t) => $this->deploymentExists($kubectl, $t->namespace(), $t->deploymentName()),
        ));

        if ($this->option('all')) {
            if ($installed === []) {
                $this->laraKubeWarn('No DB-rotatable tools are installed.');
            }

            return $installed;
        }

        $slug = $this->option('tool');
        if ($slug !== null) {
            $tool = ClusterTool::tryFrom($slug);
            if ($tool === null || $tool->dbSecretRef() === null) {
                $this->laraKubeError("'{$slug}' does not have a Commons database password OpenBao can rotate.");

                return [];
            }
            if (! $this->deploymentExists($kubectl, $tool->namespace(), $tool->deploymentName())) {
                $this->laraKubeError("{$tool->getLabel()} is not installed.");

                return [];
            }

            return [$tool];
        }

        if ($installed === []) {
            $this->laraKubeWarn('No DB-rotatable tools are installed.');

            return [];
        }

        $options = [];
        foreach ($installed as $tool) {
            $options[$tool->value] = $tool->getLabel();
        }

        $chosen = $this->flagOrPrompt(
            'tool',
            fn () => select(label: "Which tool's DB password should OpenBao take over rotating?", options: $options),
            'the tool whose DB password to wire',
            '--tool='.array_key_first($options),
        );

        $tool = ClusterTool::tryFrom($chosen);
        if ($tool === null || ! isset($options[$chosen])) {
            $this->laraKubeError("'{$chosen}' does not have a Commons database password OpenBao can rotate.");

            return [];
        }

        return [$tool];
    }

    protected function wireTool(string $kubectl, ClusterTool $tool, string $rotationPeriod): bool
    {
        $ref = $tool->dbSecretRef();
        $tenant = $tool->commonsDatabases()[0] ?? null;

        if ($ref === null || $tenant === null) {
            $this->laraKubeError("{$tool->getLabel()} has no wireable Commons database.");

            return false;
        }

        // A tool can advertise a dbSecretRef yet have no password on this
        // install (e.g. a tool whose install never provisioned the Commons
        // tenant). Registering a phantom static role would silently fail every
        // rotation, so skip instead.
        $password = trim(Process::run(
            "{$kubectl} get secret {$ref['secret']} -n {$ref['namespace']} -o jsonpath='{.data.{$ref['key']}}'",
        )->output());

        if ($password === '') {
            $this->laraKubeWarn("{$tool->getLabel()} has no Commons database password ('{$ref['key']}' in secret/{$ref['secret']}) — nothing to rotate, skipping.");

            return true;
        }

        // Snapshot BEFORE rotating — registerStaticRole() below rotates the
        // password as a side effect the instant it runs, so this is
        // guaranteed stale relative to any sync that reflects the new value.
        $refreshTimeBefore = $this->externalSecretRefreshTime($kubectl, $ref['namespace'], "{$ref['secret']}-db");

        $registered = false;
        $this->withSpin("Registering {$tool->getLabel()}'s DB password as an OpenBao static role...", function () use ($kubectl, $tenant, $rotationPeriod, &$registered) {
            $registered = $this->registerStaticRole($kubectl, $tenant, 'plex-postgres', $tenant, $rotationPeriod);
        });

        if (! $registered) {
            $this->laraKubeError("Could not register the static role for {$tool->getLabel()}.");

            return false;
        }

        $this->withSpin("Wiring OpenBao rotation into {$ref['secret']}...", function () use ($kubectl, $tenant, $ref) {
            $manifest = view('k8s.secrets.eso-db-static', [
                'namespace' => $ref['namespace'],
                'secretsNamespace' => $this->secretsNamespace(),
                'secretName' => $ref['secret'],
                'roleName' => $tenant,
                'passwordKey' => $ref['key'],
            ])->render();

            $tmp = sys_get_temp_dir().'/larakube-eso-db-static-'.$tenant.'.yaml';
            file_put_contents($tmp, $manifest);
            Process::run("{$kubectl} apply -f ".escapeshellarg($tmp));
            @unlink($tmp);
        });

        // The manifest above is byte-identical across rotations (only the
        // OpenBao-side value changed), so ESO has no resourceVersion change
        // to notice and won't requeue this ExternalSecret on its own until
        // its refreshInterval ticks — nudge it now instead of passively
        // waiting out waitForExternalSecretSynced()'s 30s window.
        $this->forceExternalSecretReconcile($kubectl, $ref['namespace'], "{$ref['secret']}-db");

        // Restarting before the ExternalSecret actually finishes its first
        // sync is a real race, not theoretical — OpenBao rotates the password
        // the instant registerStaticRole() runs, so a naive "apply then
        // restart" restarts the pod against a password that's already stale.
        // Confirmed live 2026-07-30: it took Documenso down a second time.
        $synced = false;
        $this->withSpin("Waiting for {$ref['secret']} to sync...", function () use ($kubectl, $ref, $refreshTimeBefore, &$synced) {
            $synced = $this->waitForExternalSecretSynced($kubectl, $ref['namespace'], "{$ref['secret']}-db", $refreshTimeBefore);
        });

        if (! $synced) {
            $this->laraKubeError("{$ref['secret']}-db never reported Synced — not restarting {$tool->getLabel()} against a value that may not be live yet.");
            $this->line("  <fg=gray>Check:</> <fg=yellow>kubectl get externalsecret {$ref['secret']}-db -n {$ref['namespace']}</>");

            return false;
        }

        $this->withSpin("Restarting {$tool->getLabel()} to pick up the OpenBao-managed password...", fn () => Process::run(
            "{$kubectl} rollout restart deployment/{$tool->deploymentName()} -n {$ref['namespace']}",
        ));

        $this->laraKubeInfo("✅ {$tool->getLabel()}'s DB password is now rotated by OpenBao every {$rotationPeriod}.");

        return true;
    }

    /** Check if a deployment exists in a namespace. */
    protected function deploymentExists(string $kubectl, string $ns, string $deployment): bool
    {
        return trim(Process::run(
            "{$kubectl} get deployment {$deployment} -n {$ns} --no-headers --ignore-not-found",
        )->output()) !== '';
    }
}
