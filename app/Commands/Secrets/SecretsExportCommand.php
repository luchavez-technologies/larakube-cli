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

class SecretsExportCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithSecrets, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment;

    protected $signature = 'secrets:export
        {environment=local      : Target environment}
        {--engine=              : Source engine to export from ("openbao")}
        {--output=              : Path to write the JSON file (default: ./secrets-export.json)}
        {--include-policies     : Also export custom OpenBao ACL policies (excluding built-in policies)}
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

        $env = $this->resolveToolEnvironment(ClusterTool::OPENBAO);
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = $this->secretsNamespace();

        $token = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'root-token');

        if ($token === null) {
            $this->laraKubeError('OpenBao is not bootstrapped on this cluster. Run tool:init --tool=openbao first.');

            return 1;
        }

        $export = [
            'engine' => 'openbao',
            'exported_at' => now()->toIso8601String(),
            'environments' => [],
            'secrets' => [],
        ];

        $rows = [];
        $paths = [];

        $walkError = null;
        $walk = function (string $prefix) use (&$walk, $kubectl, $token, &$paths, &$walkError): void {
            if ($walkError !== null) {
                return;
            }

            $listEndpoint = $prefix === ''
                ? '/v1/secret/metadata?list=true'
                : "/v1/secret/metadata/{$prefix}?list=true";

            $listResponse = $this->openBaoApi($kubectl, 'GET', $listEndpoint, null, $token);
            if ($listResponse === null || ! isset($listResponse['data']['keys']) || ! is_array($listResponse['data']['keys'])) {
                $label = $prefix === '' ? 'secret/' : "secret/{$prefix}";
                $walkError = "Failed to list OpenBao path \"{$label}\"".($this->lastSecretsBackendError !== null ? " ({$this->lastSecretsBackendError})" : '.');

                return;
            }

            foreach ($listResponse['data']['keys'] as $entry) {
                $entry = (string) $entry;
                if ($entry === '') {
                    continue;
                }

                $isFolder = str_ends_with($entry, '/');
                $trimmed = rtrim($entry, '/');
                if ($trimmed === '') {
                    continue;
                }

                $full = $prefix === '' ? $trimmed : "{$prefix}/{$trimmed}";

                if ($isFolder) {
                    $walk($full);
                    if ($walkError !== null) {
                        return;
                    }
                } else {
                    $paths[] = $full;
                }
            }
        };

        $listed = $this->withSpin('Listing secrets recursively from OpenBao...', fn () => $walk(''));

        if (! $listed || $walkError !== null) {
            $this->laraKubeError($walkError ?? 'Failed to list secrets from OpenBao.');

            return 1;
        }

        foreach ($paths as $path) {
            $secretData = null;
            $readOk = $this->withSpin("Reading {$path}...", function () use ($kubectl, $token, $path, &$secretData): void {
                $secretData = $this->openBaoApi($kubectl, 'GET', "/v1/secret/data/{$path}", null, $token);
            });

            $payload = $secretData['data']['data'] ?? null;
            if (! $readOk || $secretData === null || ! is_array($payload)) {
                $this->laraKubeError(
                    "Failed to read OpenBao secret \"secret/{$path}\""
                    .($this->lastSecretsBackendError !== null ? " ({$this->lastSecretsBackendError})" : '.'),
                );

                return 1;
            }

            $export['secrets'][$path] = $payload;

            $segments = explode('/', $path, 2);
            $envSlug = $segments[0];
            $keySlug = $segments[1] ?? '';

            // Preserve legacy environments[env][key] shape for single-segment keys with a `value` field.
            if ($keySlug !== '' && ! str_contains($keySlug, '/') && array_key_exists('value', $payload) && is_scalar($payload['value'])) {
                $export['environments'][$envSlug][$keySlug] = (string) $payload['value'];
            } else {
                $export['environments'][$envSlug] ??= [];
            }

            $rows[] = [$envSlug, $keySlug !== '' ? $keySlug : $path];
        }

        if ($this->option('include-policies')) {
            $policies = $this->exportCustomAclPolicies($kubectl, $token);
            if ($policies === null) {
                return 1;
            }
            $export['policies'] = $policies;
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
        $totalKeys = count($export['secrets']);
        $this->laraKubeInfo("✅ Exported {$totalKeys} secret(s) to: {$output}");
        $this->newLine();
        $this->line('  <fg=yellow>⚠  This file contains raw secret values. Treat it like a .env file.</>');
        $this->line('  <fg=gray>Delete it after import or store it securely.</>');
        $this->newLine();

        return 0;
    }

    /**
     * Export custom KV ACL policies (excluding OpenBao built-ins and LaraKube system policies).
     *
     * @return array<string, string>|null
     */
    protected function exportCustomAclPolicies(string $kubectl, string $token): ?array
    {
        $list = $this->openBaoApi($kubectl, 'GET', '/v1/sys/policies/acl?list=true', null, $token);
        if ($list === null || ! isset($list['data']['keys']) || ! is_array($list['data']['keys'])) {
            $this->laraKubeError('Failed to list OpenBao ACL policies.');

            return null;
        }

        $reserved = array_merge(['root', 'default'], array_keys(SecretsBackend::OPENBAO->policies()));
        $policies = [];

        foreach ($list['data']['keys'] as $name) {
            $name = (string) $name;
            if ($name === '' || in_array($name, $reserved, true)) {
                continue;
            }

            $policy = $this->openBaoApi($kubectl, 'GET', "/v1/sys/policies/acl/{$name}", null, $token);
            $hcl = $policy['data']['policy'] ?? $policy['policy'] ?? null;
            if ($policy === null || ! is_string($hcl)) {
                $this->laraKubeError("Failed to read OpenBao ACL policy \"{$name}\".");

                return null;
            }

            $policies[$name] = $hcl;
        }

        return $policies;
    }

    protected function resolveEngine(): SecretsBackend
    {
        return SecretsBackend::OPENBAO;
    }
}
