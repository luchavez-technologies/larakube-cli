<?php

namespace App\Support\Templates;

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
use Illuminate\Support\Facades\File;
use RuntimeException;

class ReleaseManifestBuilder
{
    /**
     * Describe a Cluster Tool's release metadata for `tool:release-manifest`.
     *
     * @return array{
     *     tool: string,
     *     engine: string,
     *     contracts: list<int>,
     *     cli_version: string,
     *     services: list<array{key: string, role: string, optional: bool, toggle_flag: null, bundled_only: bool, shares_primary_secret: bool, backup_paths: list<string>}>,
     *     images: object,
     *     requirements: list<array{kind: string, name: string, optional: bool}>,
     *     files: list<array{path: string, sha256: string}>
     * }
     */
    public function describe(ClusterTool $tool, ?string $engine = null): array
    {
        [$engineForTool, $engineSlug] = $this->resolveEngine($tool, $engine);
        $rawFiles = $this->collectTemplateFiles($tool);

        $files = array_map(
            static fn (array $file): array => [
                'path' => $file['path'],
                'sha256' => $file['sha256'],
            ],
            $rawFiles,
        );

        $services = $this->buildServices($tool, $engineForTool);
        $vendor = $tool->vendor($engineForTool);
        $images = $vendor instanceof HasImages ? $vendor->images() : [];
        $requirements = $this->buildRequirements($vendor);

        return [
            'tool' => $tool->value,
            'engine' => $engineSlug,
            'contracts' => array_values(array_map('intval', $tool->templateContracts($engineForTool))),
            'cli_version' => (string) config('app.version'),
            'services' => $services,
            'images' => (object) $images,
            'requirements' => $requirements,
            'files' => $files,
        ];
    }

    /**
     * Build a complete signed-ready release manifest and template file bundle for LaraKube Cloud.
     *
     * @return array{
     *     manifest: array{
     *         app: string,
     *         engine: string,
     *         version: string,
     *         contract: int,
     *         security: bool,
     *         images: array<string, string>,
     *         files: array<string, string>,
     *         services: list<array{key: string, role: string, optional: bool, toggle_flag: null, bundled_only: bool, shares_primary_secret: bool, backup_paths: list<string>}>,
     *         requirements: list<array{kind: string, name: string, optional: bool}>
     *     },
     *     files: list<array{path: string, content: string}>
     * }
     */
    public function build(
        ClusterTool $tool,
        ?string $engine,
        string $version,
        bool $security,
        ImageDigestResolver $digests,
    ): array {
        [$engineForTool, $engineSlug] = $this->resolveEngine($tool, $engine);
        $collectedFiles = $this->collectTemplateFiles($tool);

        $manifestFiles = [];
        $bundleFiles = [];

        foreach ($collectedFiles as $file) {
            $manifestFiles[$file['path']] = 'sha256:'.$file['sha256'];
            $bundleFiles[] = [
                'path' => $file['path'],
                'content' => $file['content'],
            ];
        }

        ksort($manifestFiles, SORT_STRING);

        $vendor = $tool->vendor($engineForTool);
        $rawImages = $vendor instanceof HasImages ? $vendor->images() : [];
        ksort($rawImages, SORT_STRING);

        $pinnedImages = [];
        foreach ($rawImages as $key => $reference) {
            $pinnedImages[(string) $key] = $digests->pin((string) $reference);
        }

        $contracts = array_values(array_map('intval', $tool->templateContracts($engineForTool)));
        $contract = $contracts !== [] ? (int) max($contracts) : 1;

        $services = $this->buildServices($tool, $engineForTool);
        $requirements = $this->buildRequirements($vendor);

        return [
            'manifest' => [
                'app' => $tool->value,
                'engine' => $engineSlug,
                'version' => $version,
                'contract' => $contract,
                'security' => $security,
                'images' => $pinnedImages,
                'files' => $manifestFiles,
                'services' => $services,
                'requirements' => $requirements,
            ],
            'files' => $bundleFiles,
        ];
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function resolveEngine(ClusterTool $tool, ?string $engine): array
    {
        $trimmed = trim((string) ($engine ?? ''));

        if ($trimmed === '' || $trimmed === 'default') {
            $default = $tool->defaultEngine();
            $engineForTool = $trimmed === 'default' ? null : $default;
            $engineSlug = $engineForTool ?? 'default';

            return [$engineForTool, $engineSlug];
        }

        return [$trimmed, $trimmed];
    }

    /**
     * @return list<array{path: string, sha256: string, content: string}>
     */
    private function collectTemplateFiles(ClusterTool $tool): array
    {
        $templateDir = base_path('resources/views/k8s/'.$tool->templateFolder());

        if (! File::isDirectory($templateDir)) {
            throw new RuntimeException("Template folder 'resources/views/k8s/{$tool->templateFolder()}' not found for tool '{$tool->value}'.");
        }

        $files = [];
        foreach (File::allFiles($templateDir) as $file) {
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());
            if (! str_ends_with($relativePath, '.blade.php')) {
                continue;
            }

            $content = (string) File::get($file->getPathname());

            $files[] = [
                'path' => $relativePath,
                'sha256' => hash('sha256', $content),
                'content' => $content,
            ];
        }

        usort($files, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $files;
    }

    /**
     * @return list<array{key: string, role: string, optional: bool, toggle_flag: null, bundled_only: bool, shares_primary_secret: bool, backup_paths: list<string>}>
     */
    private function buildServices(ClusterTool $tool, ?string $engine): array
    {
        return array_values(array_map(
            static fn (ClusterToolComponentData $component): array => [
                'key' => $component->key,
                'role' => $component->role->value,
                'optional' => false,
                'toggle_flag' => null,
                'bundled_only' => $component->bundledOnly,
                'shares_primary_secret' => $component->sharesPrimarySecret,
                'backup_paths' => array_values(array_map('strval', $component->backupPaths)),
            ],
            $tool->components(null, $engine),
        ));
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
