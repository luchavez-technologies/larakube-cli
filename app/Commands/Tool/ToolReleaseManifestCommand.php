<?php

namespace App\Commands\Tool;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsBuckets;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasCommonsRedisKeys;
use App\Contracts\HasImages;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasOpenbaoSync;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasVpnWiring;
use App\Data\ClusterToolComponentData;
use App\Enums\ClusterTool;
use App\Traits\EmitsJsonOutput;
use App\Traits\FailsWithJson;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * Describe one Cluster Tool's template release as JSON so LaraKube Cloud can import it.
 */
class ToolReleaseManifestCommand extends Command
{
    use EmitsJsonOutput, FailsWithJson, LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'tool:release-manifest
        {tool : The tool slug, e.g. openbao}
        {--engine= : The engine, for multi-engine tools}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = "Describe a Cluster Tool's template release as JSON for LaraKube Cloud import";

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $toolSlug = strtolower(trim((string) $this->argument('tool')));
        $tool = ClusterTool::tryFrom($toolSlug);

        if ($tool === null) {
            return $this->failed("Unknown tool '{$toolSlug}'.");
        }

        $engines = $tool->engines();
        $engineOption = trim((string) ($this->option('engine') ?? ''));

        if ($engineOption !== '') {
            $isValidEngine = $engines === []
                ? $engineOption === 'default'
                : array_key_exists($engineOption, $engines);

            if (! $isValidEngine) {
                return $this->failed("Unknown engine '{$engineOption}' for tool '{$tool->value}'.");
            }

            $engine = $engineOption === 'default' ? null : $engineOption;
            $engineSlug = $engineOption;
        } else {
            $engine = $tool->defaultEngine();
            $engineSlug = $engine ?? 'default';
        }

        $templateDir = base_path('resources/views/k8s/'.$tool->templateFolder());

        if (! File::isDirectory($templateDir)) {
            return $this->failed("Template folder 'resources/views/k8s/{$tool->templateFolder()}' not found for tool '{$tool->value}'.");
        }

        $files = [];
        foreach (File::allFiles($templateDir) as $file) {
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());
            if (! str_ends_with($relativePath, '.blade.php')) {
                continue;
            }

            $files[] = [
                'path' => $relativePath,
                'sha256' => (string) hash_file('sha256', $file->getPathname()),
            ];
        }

        usort($files, fn (array $a, array $b): int => $a['path'] <=> $b['path']);

        $services = array_map(
            fn (ClusterToolComponentData $component): array => [
                'key' => $component->key,
                'role' => $component->role->value,
                'optional' => false,
                'toggle_flag' => null,
                'bundled_only' => $component->bundledOnly,
                'shares_primary_secret' => $component->sharesPrimarySecret,
                'backup_paths' => $component->backupPaths,
            ],
            $tool->components(null, $engine),
        );

        $vendor = $tool->vendor($engine);
        $images = $vendor instanceof HasImages ? $vendor->images() : [];
        $requirements = $this->buildRequirements($vendor);

        $payload = [
            'tool' => $tool->value,
            'engine' => $engineSlug,
            'contracts' => $tool->templateContracts($engine),
            'cli_version' => (string) config('app.version'),
            'services' => $services,
            'images' => (object) $images,
            'requirements' => $requirements,
            'files' => $files,
        ];

        if ($this->flag('json')) {
            $this->jsonOutput($payload);

            return self::SUCCESS;
        }

        $this->renderHeader();

        table(
            headers: ['Service', 'Role'],
            rows: array_map(
                fn (array $service): array => [$service['key'], $service['role']],
                $services,
            ),
        );

        $this->line(sprintf(
            '  %d service(s), %d image(s), %d requirement(s), %d file(s)',
            count($services),
            count($images),
            count($requirements),
            count($files),
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<array{kind: string, name: string, optional: bool}>
     */
    private function buildRequirements(ClusterToolVendor $vendor): array
    {
        $requirements = [];

        if ($vendor instanceof HasCommonsDatabases) {
            foreach ($vendor->canonicalDatabaseList() as $name) {
                $requirements[] = [
                    'kind' => 'commons_postgres',
                    'name' => $name,
                    'optional' => false,
                ];
            }
        }

        if ($vendor instanceof HasCommonsBuckets) {
            foreach ($vendor->canonicalBucketList() as $name) {
                $requirements[] = [
                    'kind' => 'commons_s3',
                    'name' => $name,
                    'optional' => false,
                ];
            }
        }

        if ($vendor instanceof HasCommonsRedisKeys) {
            foreach ($vendor->commonsRedisKeys() as $name) {
                $requirements[] = [
                    'kind' => 'commons_redis',
                    'name' => $name,
                    'optional' => false,
                ];
            }
        }

        if ($vendor instanceof HasOidcWiring) {
            $requirements[] = [
                'kind' => 'oidc',
                'name' => 'oidc',
                'optional' => true,
            ];
        }

        if ($vendor instanceof HasSmtpWiring) {
            $requirements[] = [
                'kind' => 'smtp',
                'name' => 'smtp',
                'optional' => true,
            ];
        }

        if ($vendor instanceof HasVpnWiring) {
            $requirements[] = [
                'kind' => 'vpn',
                'name' => 'vpn',
                'optional' => true,
            ];
        }

        if ($vendor instanceof HasOpenbaoSync) {
            $requirements[] = [
                'kind' => 'openbao_sync',
                'name' => 'openbao_sync',
                'optional' => false,
            ];
        }

        return $requirements;
    }
}
