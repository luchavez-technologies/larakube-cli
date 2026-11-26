<?php

namespace App\Commands\Secrets;

use App\Enums\ClusterTool;
use App\Enums\SecretsBackend;
use App\Services\Kubectl;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithSecrets;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class SecretsImportCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithSecrets, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment;

    protected $signature = 'secrets:import
        {environment=local      : Target environment}
        {--engine=              : Target engine to import into ("openbao")}
        {--input=               : Path to the JSON export file (default: ./secrets-export.json)}
        {--context=             : Target a specific kube-context}
        {--force                : Skip confirmation prompt}';

    protected $description = 'Import secrets from a local JSON export file into the secrets manager';

    public function handle(): int
    {
        $this->renderHeader();

        $engine = $this->resolveEngine();
        $input = (string) ($this->option('input') ?: 'secrets-export.json');

        if ($engine === null) {
            return 1;
        }

        if ($engine !== SecretsBackend::OPENBAO) {
            $this->laraKubeError("Import into engine \"{$engine->getLabel()}\" is not yet supported. Only \"OpenBao\" is supported.");

            return 1;
        }

        // ── 1. Read + validate the export file ───────────────────────────────
        if (! file_exists($input)) {
            $this->laraKubeError("Export file not found: {$input}");
            $this->line('  Run <fg=blue>larakube secrets:export</> first to create a backup.');

            return 1;
        }

        $data = json_decode((string) file_get_contents($input), true);

        if (! is_array($data) || (! isset($data['environments']) && ! isset($data['secrets']))) {
            $this->laraKubeError("Invalid export file format: {$input}");

            return 1;
        }

        /** @var array<string, array<string, mixed>> $secretsMap */
        $secretsMap = [];

        if (isset($data['secrets']) && is_array($data['secrets'])) {
            foreach ($data['secrets'] as $path => $payload) {
                $path = trim((string) $path, '/');
                if ($path === '' || ! is_array($payload)) {
                    $this->laraKubeError("Invalid secret entry in {$input}: {$path}");

                    return 1;
                }
                $secretsMap[$path] = $payload;
            }
        } elseif (isset($data['environments']) && is_array($data['environments'])) {
            foreach ($data['environments'] as $envSlug => $secrets) {
                if (! is_array($secrets)) {
                    continue;
                }
                foreach ($secrets as $key => $value) {
                    $secretsMap["{$envSlug}/{$key}"] = is_array($value) ? $value : ['value' => $value];
                }
            }
        }

        /** @var array<string, string> $policies */
        $policies = is_array($data['policies'] ?? null) ? $data['policies'] : [];

        $totalKeys = count($secretsMap);

        $this->laraKubeInfo(sprintf(
            'Found %d secret(s) in %s',
            $totalKeys,
            $input,
        ));

        $env = $this->resolveToolEnvironment(ClusterTool::OPENBAO);
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = $this->secretsNamespace();

        // ── 2. Init + unseal OpenBao if needed ────────────────────────────────
        $token = $this->ensureOpenBaoReady($kubectl, $ns);

        if ($token === null) {
            return 1;
        }

        // ── 3. Enable KV v2 engine at secret/ ────────────────────────────────
        $this->withSpin('Ensuring KV v2 engine is mounted at secret/...', function () use ($kubectl, $token): void {
            // Attempt to mount — 400 = already mounted, which is fine.
            $this->openBaoApi($kubectl, 'POST', '/v1/sys/mounts/secret', [
                'type' => 'kv',
                'options' => ['version' => '2'],
            ], $token);
        });

        // ── 4. Write secrets ──────────────────────────────────────────────────
        $rows = [];
        $failed = 0;

        foreach ($secretsMap as $path => $payload) {
            $result = null;
            $this->withSpin("Writing {$path}...", function () use ($kubectl, $token, $path, $payload, &$result): void {
                $result = $this->openBaoApi($kubectl, 'POST', "/v1/secret/data/{$path}", [
                    'data' => $payload,
                ], $token);
            });

            $ok = $result !== null;
            [$envSlug, $keySlug] = array_pad(explode('/', $path, 2), 2, $path);
            $rows[] = [$envSlug, $keySlug, $ok ? '✅ Written' : '❌ Failed'];

            if (! $ok) {
                $failed++;
            }
        }

        // ── 5. Restore custom ACL policies (if included in export) ───────────
        foreach ($policies as $policyName => $hcl) {
            if (! is_string($hcl)) {
                continue;
            }
            $result = null;
            $this->withSpin("Writing policy {$policyName}...", function () use ($kubectl, $token, $policyName, $hcl, &$result): void {
                $result = $this->openBaoApi($kubectl, 'PUT', "/v1/sys/policies/acl/{$policyName}", [
                    'policy' => $hcl,
                ], $token);
            });

            if ($result === null) {
                $failed++;
            }
        }

        $this->laraKubeNewLine();

        if (! empty($rows)) {
            table(['Environment', 'Secret Key', 'Status'], $rows);
        }

        $this->laraKubeNewLine();

        if ($failed > 0) {
            $this->laraKubeError("{$failed} item(s) failed to write. Check OpenBao logs and retry.");

            return 1;
        }

        $this->laraKubeInfo("✅ Imported {$totalKeys} secret(s) into OpenBao (secret/<path>).");

        return 0;
    }

    protected function resolveEngine(): SecretsBackend
    {
        return SecretsBackend::OPENBAO;
    }
}
