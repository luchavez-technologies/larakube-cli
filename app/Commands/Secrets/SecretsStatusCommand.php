<?php

namespace App\Commands\Secrets;

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Services\Kubectl;
use App\Traits\DeploysClusterTool;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithToolRegistry;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesToolCustomEnv;
use App\Traits\ResolvesToolEngine;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\SyncsClusterSecrets;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * Read-only fleet-wide summary of OpenBao's live state — bootstrapped/sealed,
 * which tools are wired to static-role DB rotation, and how many custom env
 * vars each registered tool instance carries. Backs Desktop's OpenBao
 * section; no counterpart existed before (secretsBackendAvailable()/
 * isOpenBaoBootstrapped() are plain booleans only). Never reveals secret
 * values — counts only, same restraint as `tool:env` without --reveal.
 */
class SecretsStatusCommand extends Command
{
    use DeploysClusterTool, EmitsJsonOutput, InteractsWithToolRegistry, LaraKubeOutput, ManagesToolCustomEnv, ResolvesToolEngine, ResolvesToolEnvironment, SyncsClusterSecrets;

    protected $signature = 'secrets:status
        {environment=local : Environment to inspect}
        {--context= : Target a specific kube-context}
        {--json : Emit machine-readable JSON output}';

    protected $description = "Report OpenBao's live bootstrap/seal state, DB-rotation wiring, and custom env var counts";

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        if ($json) {
            $this->enableJsonMode();
        } else {
            $this->renderHeader();
        }

        $env = $this->resolveToolEnvironment(ClusterTool::OPENBAO);
        $context = $this->resolveToolContext($env, $this->option('context'));
        $kubectl = Kubectl::forContext($context)->prefix();

        $ns = $this->secretsNamespace();
        $installed = $this->isSecretsInstalled($kubectl, $ns);
        $bootstrapped = $installed && $this->isOpenBaoBootstrapped($kubectl, $ns);

        $sealed = null;
        if ($bootstrapped) {
            $sealStatus = $this->openBaoApi($kubectl, 'GET', '/v1/sys/seal-status');
            $sealed = $sealStatus !== null ? (bool) ($sealStatus['sealed'] ?? true) : null;
        }

        $staticRoles = $bootstrapped && $sealed === false
            ? $this->staticRoleSummary($kubectl)
            : [];

        $customEnv = $installed
            ? $this->customEnvSummary($kubectl, $bootstrapped && $sealed === false)
            : [];

        $payload = [
            'success' => true,
            'installed' => $installed,
            'bootstrapped' => $bootstrapped,
            'sealed' => $sealed,
            'staticRoles' => $staticRoles,
            'customEnv' => $customEnv,
        ];

        if ($json) {
            $this->jsonOutput($payload);

            return 0;
        }

        if (! $installed) {
            $this->laraKubeInfo('OpenBao is not deployed. Run `larakube tool:init --tool=openbao` first.');

            return 0;
        }

        $this->laraKubeInfo($bootstrapped
            ? ($sealed ? 'OpenBao is bootstrapped but currently SEALED — secret access is blocked cluster-wide.' : 'OpenBao is bootstrapped and unsealed.')
            : 'OpenBao is deployed but not yet bootstrapped.');

        if ($staticRoles !== []) {
            table(
                headers: ['Tool', 'Instance', 'Role', 'Rotation Period', 'Wired'],
                rows: array_map(fn (array $row) => [
                    $row['tool'],
                    $row['instance'] !== '' ? $row['instance'] : '—',
                    $row['role'],
                    $row['rotationPeriod'] ?? '—',
                    $row['wired'] ? 'yes' : 'no',
                ], $staticRoles),
            );
        }

        if ($customEnv !== []) {
            table(
                headers: ['Tool', 'Instance', 'Plain Vars', 'Secret Vars', 'Vaulted'],
                rows: array_map(fn (array $row) => [
                    $row['tool'],
                    $row['instance'] !== '' ? $row['instance'] : '—',
                    (string) $row['plainCount'],
                    (string) $row['secretCount'],
                    $row['vaulted'] ? 'yes' : 'no',
                ], $customEnv),
            );
        }

        return 0;
    }

    /**
     * Every DB-rotatable tool's static-role wiring, reusing the exact same
     * role-name resolution as secrets:rotate (tenant name, falling back to
     * 'tenant-{tenant}') — no new OpenBao policy-listing plumbing.
     *
     * One OpenBao API call (staticRoleRotationInfo(), the same helper
     * plex:show already uses) does double duty as both the existence check
     * and the rotation-period/last-rotated read — it used to be two separate
     * round trips (staticRoleExists() then a second GET), each opening and
     * tearing down its own port-forward. At 9 real wired tools that was up
     * to 18 port-forwards per sync, comfortably enough to blow past
     * SyncOpenBaoJob's timeout in Desktop even though a direct CLI run
     * (warmer, no job-queue overhead) stayed fast enough to look fine.
     * Confirmed live 2026-11-03: Desktop's background sync kept landing
     * empty while the same command run by hand returned all 9 roles.
     *
     * @return list<array{tool: string, slug: string, instance: string, role: string, rotationPeriod: string|null, lastRotatedAt: string|null, wired: bool}>
     */
    protected function staticRoleSummary(string $kubectl): array
    {
        $capable = array_filter(ClusterTool::shippedCases(), fn (ClusterTool $t) => $t->dbSecretRef() !== null);

        $rows = [];
        foreach ($capable as $tool) {
            $host = $this->getToolHost($kubectl, $tool);
            $instance = $host !== null ? $this->resolveInstanceForDomain($kubectl, $tool, $host) : '';
            $engine = $tool->engines() !== [] ? $this->resolveInstanceEngine($kubectl, $tool, $instance, null) : null;
            $tenant = $tool->commonsDatabases($instance, $engine)[0] ?? null;
            if ($tenant === null) {
                continue;
            }

            if (! Kubectl::fromPrefix($kubectl)->hasDeployment($tool->namespace(), $tool->deploymentName($instance, $engine))) {
                continue;
            }

            $roleName = $tenant;
            $info = $this->staticRoleRotationInfo($kubectl, $roleName);
            $wired = $info !== null;

            if (! $wired && ! str_contains((string) $this->lastSecretsBackendError, 'HTTP 404')) {
                // Unreachable/sealed, not a confirmed "doesn't exist" — don't
                // guess at the 'tenant-' fallback name off an unknown state.
                $roleName = $tenant;
            } elseif (! $wired) {
                $roleName = 'tenant-'.$tenant;
                $info = $this->staticRoleRotationInfo($kubectl, $roleName);
                $wired = $info !== null;
            }

            $rows[] = [
                'tool' => $tool->getLabel(),
                'slug' => $tool->value,
                'instance' => $instance,
                'role' => $roleName,
                'rotationPeriod' => $info !== null ? self::formatDuration($info['rotation_period']) : null,
                'lastRotatedAt' => $info !== null && $info['last_vault_rotation'] !== '' ? $info['last_vault_rotation'] : null,
                'wired' => $wired,
            ];
        }

        return $rows;
    }

    /**
     * Every registered tool instance's custom-env counts (keys only, never
     * values), reusing tool:env's own read path.
     *
     * @return list<array{tool: string, slug: string, instance: string, plainCount: int, secretCount: int, vaulted: bool}>
     */
    protected function customEnvSummary(string $kubectl, bool $vaulted): array
    {
        $kc = Kubectl::fromPrefix($kubectl);
        $rows = [];

        foreach ($this->getRegisteredTools($kubectl) as $row) {
            $tool = ClusterTool::tryFrom((string) ($row['tool'] ?? ''));
            if ($tool === null || $tool === ClusterTool::OPENBAO) {
                continue;
            }

            $instance = (string) ($row['instance'] ?? '');
            if ($instance === '') {
                // A legacy row not yet healed onto a host-derived slug
                // (ToolInstance::forInstance() requires one) — nothing to
                // summarize until it's re-registered.
                continue;
            }

            $engine = (string) ($row['engine'] ?? '') ?: null;
            $names = ToolInstance::forInstance($tool, $instance, $engine);

            $configMap = $this->readCustomConfigMap($kc, $names->namespace(), $this->customEnvConfigMapName($names));
            $secret = $this->readCustomSecret($kc, $names->namespace(), $this->customEnvSecretName($names));

            if ($configMap === null && $secret === null) {
                continue;
            }

            $rows[] = [
                'tool' => $tool->getLabel(),
                'slug' => $tool->value,
                'instance' => $instance,
                'plainCount' => count($configMap ?? []),
                'secretCount' => count($secret ?? []),
                'vaulted' => $vaulted && count($secret ?? []) > 0,
            ];
        }

        return $rows;
    }

    /**
     * Whole days/hours/minutes, e.g. "7d" or "168h" → "7d", "90m" → "1h 30m".
     * gmdate('H:i:s', …) was wrong here: it wraps every 24h, so any
     * whole-day period (168h = 7×24h) always rendered "00:00:00".
     */
    private static function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0m';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = "{$days}d";
        }
        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }
        if ($minutes > 0 && $days === 0) {
            $parts[] = "{$minutes}m";
        }

        return $parts === [] ? '<1m' : implode(' ', $parts);
    }
}
