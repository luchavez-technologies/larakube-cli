<?php

namespace App\Traits;

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Services\Kubectl;
use Illuminate\Support\Facades\Http;
use Throwable;

trait InteractsWithOcisExtensions
{
    /**
     * Catalog feed URL for oCIS Marketplace extensions.
     */
    protected string $ocisCatalogFeedUrl = 'https://marketplace.owncloud.com/api/ocis/v1/apps.json';

    /**
     * Fetch the live oCIS marketplace catalog feed.
     *
     * @return array<int, array{id: string, name: string, description: string, version: string, download_url: string}>
     */
    protected function fetchOcisMarketplaceCatalog(): array
    {
        try {
            $response = Http::timeout(5)->get($this->ocisCatalogFeedUrl);
            if ($response->successful()) {
                $json = $response->json();
                $rawApps = $json['apps'] ?? (is_array($json) ? $json : []);
                $catalog = [];

                foreach ($rawApps as $app) {
                    if (is_array($app) && isset($app['id'])) {
                        $downloadUrl = '';
                        if (isset($app['versions'][0]['url'])) {
                            $downloadUrl = (string) $app['versions'][0]['url'];
                        } elseif (isset($app['download_url'])) {
                            $downloadUrl = (string) $app['download_url'];
                        }

                        $version = 'v0.1.0';
                        if (isset($app['versions'][0]['version'])) {
                            $version = 'v'.ltrim((string) $app['versions'][0]['version'], 'v');
                        } elseif (isset($app['version'])) {
                            $version = (string) $app['version'];
                        }

                        $catalog[] = [
                            'id' => (string) $app['id'],
                            'name' => (string) ($app['name'] ?? $app['id']),
                            'description' => (string) ($app['description'] ?? $app['subtitle'] ?? ''),
                            'version' => $version,
                            'download_url' => $downloadUrl,
                        ];
                    }
                }

                if ($catalog !== []) {
                    return $catalog;
                }
            }
        } catch (Throwable $e) {
            // Fallback to static catalog if network/HTTP fails
        }

        return $this->fallbackOcisMarketplaceCatalog();
    }

    /**
     * Fallback curated catalog when live feed is unreachable.
     *
     * @return array<int, array{id: string, name: string, description: string, version: string, download_url: string}>
     */
    protected function fallbackOcisMarketplaceCatalog(): array
    {
        return [
            [
                'id' => 'com.github.lukashirt.excalidraw',
                'name' => 'Excalidraw',
                'description' => 'Whiteboard & diagramming tool',
                'version' => 'v0.1.0',
                'download_url' => 'https://github.com/owncloud/marketplace/releases/download/excalidraw/excalidraw-0.1.0.zip',
            ],
            [
                'id' => 'web-app-draw-io',
                'name' => 'Draw.io',
                'description' => 'Diagram editor (.drawio)',
                'version' => 'v0.2.0',
                'download_url' => 'https://github.com/owncloud/marketplace/releases/download/draw-io/draw-io-0.2.0.zip',
            ],
            [
                'id' => 'com.github.sawjan.3dviewer',
                'name' => '3D Model Viewer',
                'description' => 'View 3D models directly in oCIS',
                'version' => 'v0.1.0',
                'download_url' => 'https://github.com/owncloud/marketplace/releases/download/3dviewer/3dviewer-0.1.0.zip',
            ],
            [
                'id' => 'com.github.owncloud.web-extensions.dicom-viewer',
                'name' => 'DICOM Viewer',
                'description' => 'Preview DICOM medical image files',
                'version' => 'v0.1.0',
                'download_url' => 'https://github.com/owncloud/marketplace/releases/download/dicom-viewer/dicom-viewer-0.1.0.zip',
            ],
            [
                'id' => 'com.github.owncloud.web-extensions.markdown-presentation',
                'name' => 'Markdown Presentation Viewer',
                'description' => 'Convert & view markdown (.md) as presentations',
                'version' => 'v2.0.0',
                'download_url' => 'https://github.com/owncloud/marketplace/releases/download/markdown-presentation/markdown-presentation-2.0.0.zip',
            ],
            [
                'id' => 'com.github.owncloud.web-extensions.json-viewer',
                'name' => 'JSON Viewer',
                'description' => 'View JSON files with syntax highlighting',
                'version' => 'v0.2.0',
                'download_url' => 'https://github.com/owncloud/marketplace/releases/download/json-viewer/json-viewer-0.2.0.zip',
            ],
        ];
    }

    /**
     * This cluster's oCIS Deployment. The name is per instance, so it is read
     * from the registry rather than written out — a literal here silently
     * stopped matching the moment Drive moved onto the naming convention.
     */
    protected function driveDeployment(string $kubectl): string
    {
        $names = ToolInstance::registered($kubectl, ClusterTool::DRIVE)[0] ?? null;

        return $names?->deployment() ?? ClusterTool::DRIVE->deploymentName();
    }

    /**
     * Read currently installed web extension IDs from /var/lib/ocis/web/apps/ inside the oCIS pod.
     *
     * @return list<string>
     */
    protected function getInstalledOcisExtensions(string $kubectl, string $ns = 'larakube-shared'): array
    {
        $res = Kubectl::fromPrefix($kubectl)->exec($ns, 'deploy/'.$this->driveDeployment($kubectl), ['ls', '-1', '/var/lib/ocis/web/apps']);
        if (! $res->ok) {
            return [];
        }

        $lines = array_map('trim', explode("\n", $res->output));

        return array_values(array_filter($lines, fn ($line) => $line !== '' && ! str_contains($line, 'No such file')));
    }

    /**
     * Ensure WEB_ASSET_APPS_PATH=/var/lib/ocis/web/apps is set on the oCIS Deployment.
     */
    protected function ensureOcisWebAssetAppsPath(string $kubectl, string $ns = 'larakube-shared'): bool
    {
        $kube = Kubectl::fromPrefix($kubectl);
        $current = trim($kube->raw([
            'get', 'deployment', $this->driveDeployment($kubectl), '-n', $ns,
            '-o', 'jsonpath={.spec.template.spec.containers[0].env[?(@.name=="WEB_ASSET_APPS_PATH")].value}',
        ])->output);

        if ($current === '/var/lib/ocis/web/apps') {
            return true;
        }

        return $kube->raw(['set', 'env', 'deployment/'.$this->driveDeployment($kubectl), '-n', $ns, 'WEB_ASSET_APPS_PATH=/var/lib/ocis/web/apps'])->ok;
    }

    /**
     * Install an oCIS web extension by downloading and extracting into /var/lib/ocis/web/apps/<id>.
     */
    protected function installOcisExtension(string $kubectl, string $ns, string $extension, ?string $customUrl = null): bool
    {
        $downloadUrl = $customUrl;

        if (! $downloadUrl) {
            $catalog = $this->fetchOcisMarketplaceCatalog();
            foreach ($catalog as $item) {
                if ($item['id'] === $extension
                    || strtolower($item['id']) === strtolower($extension)
                    || str_ends_with(strtolower($item['id']), '.'.strtolower($extension))
                    || strtolower($item['name']) === strtolower($extension)
                ) {
                    $downloadUrl = $item['download_url'];
                    break;
                }
            }
        }

        if (! $downloadUrl) {
            if (str_contains(strtolower($extension), 'excalidraw')) {
                $downloadUrl = 'https://github.com/owncloud/marketplace/releases/download/excalidraw/excalidraw-0.1.0.zip';
            } else {
                $parts = explode('.', $extension);
                $short = end($parts);
                $downloadUrl = "https://github.com/owncloud/marketplace/releases/download/{$short}/{$short}-0.1.0.zip";
            }
        }

        $parts = explode('.', $extension);
        $folder = end($parts);

        $script = 'set -e && mkdir -p /var/lib/ocis/web/apps /tmp/ocis-ext && '
            .'(apk add --no-cache curl unzip tar 2>/dev/null || true) && '
            ."TMP_FILE=\"/tmp/ocis-ext-{$folder}.archive\" && "
            .'curl -sSL '.escapeshellarg($downloadUrl).' -o "$TMP_FILE" && '
            .'if echo "$TMP_FILE" | grep -q \'\\.zip$\' || unzip -t "$TMP_FILE" >/dev/null 2>&1 || file "$TMP_FILE" | grep -qi \'zip\'; then '
            .'unzip -o -q "$TMP_FILE" -d /var/lib/ocis/web/apps/; '
            .'else '
            ."mkdir -p \"/var/lib/ocis/web/apps/{$folder}\" && tar -xzf \"\$TMP_FILE\" -C \"/var/lib/ocis/web/apps/{$folder}\"; "
            .'fi && rm -f "$TMP_FILE"';

        return Kubectl::fromPrefix($kubectl)->exec($ns, 'deploy/'.$this->driveDeployment($kubectl), ['sh', '-c', $script])->ok;
    }

    /**
     * Delete an oCIS web extension folder from /var/lib/ocis/web/apps/<id>.
     */
    protected function removeOcisExtension(string $kubectl, string $ns, string $extension): bool
    {
        $parts = explode('.', $extension);
        $folder = end($parts);

        return Kubectl::fromPrefix($kubectl)->exec($ns, 'deploy/'.$this->driveDeployment($kubectl), [
            'rm', '-rf', "/var/lib/ocis/web/apps/{$extension}", "/var/lib/ocis/web/apps/{$folder}",
        ])->ok;
    }
}
