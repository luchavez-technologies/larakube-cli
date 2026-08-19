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

class SecretsExportCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithSecrets, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment;

    protected $signature = 'secrets:export
        {environment=local      : Target environment}
        {--engine=              : Source engine to export from ("openbao")}
        {--output=              : Path to write the JSON file (default: ./secrets-export.json)}
        {--context=             : Target a specific kube-context}';

    protected $description = 'Export all secrets from the secrets manager to a local JSON file';

    public function handle(): int
    {
        $this->renderHeader();

        $engine = $this->resolveEngine();
        $output = (string) ($this->option('output') ?: 'secrets-export.json');

        if ($engine === null) {
            return 1;
        }

        if ($engine !== SecretsBackend::OPENBAO) {
            $this->laraKubeError("Export from engine \"{$engine->getLabel()}\" is not supported.");

            return 1;
        }

        $env = $this->resolveToolEnvironment(ClusterTool::SECRETS);
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = $this->secretsKubectl($context);
        $ns = $this->secretsNamespace();

        $token = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'root-token');

        if ($token === null) {
            $this->laraKubeError('OpenBao is not bootstrapped on this cluster. Run secrets:init first.');

            return 1;
        }

        $export = [
            'engine' => 'openbao',
            'exported_at' => now()->toIso8601String(),
            'environments' => [],
        ];

        $rows = [];
        $totalKeys = 0;

        $this->withSpin('Listing secret mounts from OpenBao...', function () use ($kubectl, $token, &$listResponse): void {
            $listResponse = $this->openBaoApi($kubectl, 'GET', '/v1/secret/metadata?list=true', null, $token);
        });

        $keys = $listResponse['data']['keys'] ?? [];

        foreach ($keys as $envSlug) {
            $envSlug = rtrim($envSlug, '/');
            if ($envSlug === '') {
                continue;
            }

            $this->withSpin("Listing keys for environment \"{$envSlug}\"...", function () use ($kubectl, $token, $envSlug, &$subKeysResponse): void {
                $subKeysResponse = $this->openBaoApi($kubectl, 'GET', "/v1/secret/metadata/{$envSlug}?list=true", null, $token);
            });

            $subKeys = $subKeysResponse['data']['keys'] ?? [];
            $envSecrets = [];

            foreach ($subKeys as $key) {
                $key = rtrim($key, '/');
                if ($key === '') {
                    continue;
                }

                $this->withSpin("Reading {$envSlug}/{$key}...", function () use ($kubectl, $token, $envSlug, $key, &$secretData): void {
                    $secretData = $this->openBaoApi($kubectl, 'GET', "/v1/secret/data/{$envSlug}/{$key}", null, $token);
                });

                $val = $secretData['data']['data']['value'] ?? null;
                if ($val !== null) {
                    $envSecrets[$key] = $val;
                    $rows[] = [$envSlug, $key];
                    $totalKeys++;
                }
            }

            $export['environments'][$envSlug] = $envSecrets;
        }

        $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (file_put_contents($output, $json) === false) {
            $this->laraKubeError("Failed to write export file: {$output}");

            return 1;
        }

        $this->laraKubeNewLine();

        if (! empty($rows)) {
            table(['Environment', 'Secret Key'], $rows);
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ Exported {$totalKeys} secret(s) to: {$output}");
        $this->newLine();
        $this->line('  <fg=yellow>⚠  This file contains raw secret values. Treat it like a .env file.</>');
        $this->line('  <fg=gray>Delete it after import or store it securely.</>');
        $this->newLine();

        return 0;
    }

    protected function resolveEngine(): SecretsBackend
    {
        return SecretsBackend::OPENBAO;
    }
}
