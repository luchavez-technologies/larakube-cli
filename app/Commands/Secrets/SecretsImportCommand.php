<?php

namespace App\Commands\Secrets;

use App\Enums\ClusterTool;
use App\Enums\SecretsBackend;
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

        if (! is_array($data) || ! isset($data['environments']) || ! is_array($data['environments'])) {
            $this->laraKubeError("Invalid export file format: {$input}");

            return 1;
        }

        $environments = $data['environments'];
        $totalKeys = array_sum(array_map('count', $environments));

        $this->laraKubeInfo(sprintf(
            'Found %d secret(s) across %d environment(s) in %s',
            $totalKeys,
            count($environments),
            $input,
        ));

        $env = $this->resolveToolEnvironment(ClusterTool::SECRETS);
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = $this->secretsKubectl($context);
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

        foreach ($environments as $envSlug => $secrets) {
            foreach ($secrets as $key => $value) {
                $this->withSpin("Writing {$envSlug}/{$key}...", function () use ($kubectl, $token, $envSlug, $key, $value, &$result): void {
                    $result = $this->openBaoApi($kubectl, 'POST', "/v1/secret/data/{$envSlug}/{$key}", [
                        'data' => ['value' => $value],
                    ], $token);
                });

                $ok = $result !== null;
                $rows[] = [$envSlug, $key, $ok ? '✅ Written' : '❌ Failed'];

                if (! $ok) {
                    $failed++;
                }
            }
        }

        $this->laraKubeNewLine();

        if (! empty($rows)) {
            table(['Environment', 'Secret Key', 'Status'], $rows);
        }

        $this->laraKubeNewLine();

        if ($failed > 0) {
            $this->laraKubeError("{$failed} secret(s) failed to write. Check OpenBao logs and retry.");

            return 1;
        }

        $this->laraKubeInfo("✅ Imported {$totalKeys} secret(s) into OpenBao (secret/<env>/<key>).");

        return 0;
    }

    protected function resolveEngine(): SecretsBackend
    {
        return SecretsBackend::OPENBAO;
    }
}
