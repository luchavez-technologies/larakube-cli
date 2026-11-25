<?php

namespace App\Support\Templates;

use App\Contracts\HasCommonsBuckets;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasCommonsRedisKeys;
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
    public function describe(ClusterTool|TemplateApp $app, ?string $engine = null): array
    {
        $app = $app instanceof ClusterTool ? TemplateApp::forTool($app) : $app;
        [$engineForTool, $engineSlug] = $this->resolveEngine($app, $engine);
        $rawFiles = $this->collectTemplateFiles($app);

        $files = array_map(
            static fn (array $file): array => [
                'path' => $file['path'],
                'sha256' => $file['sha256'],
            ],
            $rawFiles,
        );

        $services = $this->buildServices($app, $engineForTool);
        $images = $app->images($engineForTool);
        $requirements = $this->buildRequirements($app, $engineForTool);

        return [
            'tool' => $app->slug,
            'engine' => $engineSlug,
            'contracts' => $app->contracts($engineForTool),
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
        ClusterTool|TemplateApp $app,
        ?string $engine,
        string $version,
        bool $security,
        ImageDigestResolver $digests,
    ): array {
        $app = $app instanceof ClusterTool ? TemplateApp::forTool($app) : $app;
        [$engineForTool, $engineSlug] = $this->resolveEngine($app, $engine);
        $collectedFiles = $this->collectTemplateFiles($app);

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

        $rawImages = $app->images($engineForTool);
        ksort($rawImages, SORT_STRING);

        $pinnedImages = [];
        foreach ($rawImages as $key => $reference) {
            $pinnedImages[(string) $key] = $digests->pin((string) $reference);
        }

        $contracts = $app->contracts($engineForTool);
        $contract = $contracts !== [] ? (int) max($contracts) : 1;

        $services = $this->buildServices($app, $engineForTool);
        $requirements = $this->buildRequirements($app, $engineForTool);

        return [
            'manifest' => [
                'app' => $app->slug,
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
    private function resolveEngine(TemplateApp $app, ?string $engine): array
    {
        $trimmed = trim((string) ($engine ?? ''));

        if ($trimmed === '' || $trimmed === 'default') {
            $default = $app->defaultEngine();
            $engineForTool = $trimmed === 'default' ? null : $default;
            $engineSlug = $engineForTool ?? 'default';

            return [$engineForTool, $engineSlug];
        }

        return [$trimmed, $trimmed];
    }

    /**
     * @return list<array{path: string, sha256: string, content: string}>
     */
    private function collectTemplateFiles(TemplateApp $app): array
    {
        $templateDir = base_path('resources/views/k8s/'.$app->folder);

        if (! File::isDirectory($templateDir)) {
            throw new RuntimeException("Template folder 'resources/views/k8s/{$app->folder}' not found for app '{$app->slug}'.");
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
    private function buildServices(TemplateApp $app, ?string $engine): array
    {
        if ($app->tool === null) {
            return [];
        }

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
            $app->tool->components(null, $engine),
        ));
    }

    /**
     * @return list<array{kind: string, name: string, optional: bool}>
     */
    private function buildRequirements(TemplateApp $app, ?string $engine): array
    {
        if ($app->tool === null) {
            return [];
        }

        $vendor = $app->tool->vendor($engine);
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
