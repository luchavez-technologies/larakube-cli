<?php

namespace App\Services;

use App\Enums\ClusterTool;
use App\Http\Integrations\LaraKubeCloud\LaraKubeCloudConnector;
use App\Http\Integrations\LaraKubeCloud\Requests\LatestRelease;
use App\Http\Integrations\LaraKubeCloud\Requests\SigningKeys;
use App\Support\Templates\CanonicalJson;
use App\Support\Templates\ReleaseVerifier;
use App\Support\Templates\TemplateApp;
use App\Support\Templates\TrustedKeys;
use Closure;
use Illuminate\Support\Facades\View;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Saloon\Http\Response;
use Throwable;

class TemplateStore
{
    private const FILE_PATH_PATTERN = '/^[a-z0-9][a-z0-9_.\/-]*\.blade\.php$/';

    /**
     * Releases with local edits that match lock.trusted_hash ("<app>/<engine>").
     *
     * @var list<string>
     */
    private array $modified = [];

    /**
     * Releases with untrusted local edits or broken files ("<app>/<engine>").
     *
     * @var list<string>
     */
    private array $refused = [];

    /**
     * Injectable resolver for the bundled views directory of a template folder.
     *
     * @var (Closure(string): string)|null
     */
    private ?Closure $bundledPathResolver = null;

    public function __construct(
        private readonly ?LaraKubeCloudConnector $connector = null,
    ) {}

    /**
     * Override how bundled template directories are located (used in tests to simulate removed bundled folders).
     *
     * @param  (Closure(string): string)|null  $resolver
     */
    public function resolveBundledPathUsing(?Closure $resolver): self
    {
        $this->bundledPathResolver = $resolver;

        return $this;
    }

    public function root(): string
    {
        return home_path('.larakube/templates');
    }

    public function lockPath(): string
    {
        return $this->root().'/lock.json';
    }

    /**
     * Download, verify, and atomically install the latest compatible release for an app.
     *
     * @return array{version: string, contract: int, key_id: string, fetched_at: string, trusted_hash: null}
     */
    public function download(ClusterTool|TemplateApp $tool, ?string $engine = null): array
    {
        $tool = $tool instanceof ClusterTool ? TemplateApp::forTool($tool) : $tool;
        $engineSlug = ($engine !== null && $engine !== '') ? $engine : 'default';
        $engineForTool = $engineSlug === 'default' ? null : $engineSlug;
        $contracts = $tool->contracts($engineForTool);

        $connector = $this->connector ?? new LaraKubeCloudConnector;

        // (a) GET latest with $tool->contracts($engine)
        try {
            $response = $connector->send(new LatestRelease($tool->slug, $engineSlug, $contracts));
        } catch (Throwable $e) {
            throw new RuntimeException("Failed to fetch template release for '{$tool->slug}': {$e->getMessage()}", 0, $e);
        }

        if ($response->status() === 402) {
            throw new RuntimeException('This app needs a LaraKube Cloud plan.');
        }

        if ($response->status() === 404) {
            throw new RuntimeException($this->extractErrorMessage($response, "No compatible template release found for '{$tool->slug}'."));
        }

        if (! $response->successful()) {
            throw new RuntimeException($this->extractErrorMessage($response, "LaraKube Cloud returned HTTP {$response->status()} for '{$tool->slug}'."));
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Invalid release payload from LaraKube Cloud.');
        }

        $manifest = $payload['manifest'] ?? null;
        $signature = $payload['signature'] ?? null;
        $keyId = $payload['key_id'] ?? null;

        if (! is_array($manifest) || ! is_string($signature) || $signature === '' || ! is_string($keyId) || $keyId === '') {
            throw new RuntimeException('Release response is missing manifest, signature, or key_id.');
        }

        // (b) key_id must be in TrustedKeys::all() and not revoked
        $localTrusted = TrustedKeys::all();
        if (! isset($localTrusted[$keyId])) {
            throw new RuntimeException("Untrusted signing key '{$keyId}'.");
        }

        $revokedKeyIds = $this->fetchRevokedKeyIds($connector);
        $trustedKeys = TrustedKeys::withoutRevoked($revokedKeyIds);
        if (! isset($trustedKeys[$keyId])) {
            throw new RuntimeException("Signing key '{$keyId}' has been revoked.");
        }

        $publicKey = $trustedKeys[$keyId];

        // (c) ReleaseVerifier::verify(manifest, signature, key) must pass
        if (! ReleaseVerifier::verify($manifest, $signature, $publicKey)) {
            throw new RuntimeException('Release signature verification failed.');
        }

        // (d) manifest.app == tool value, manifest.engine == engine slug ('default' when null), manifest.contract in templateContracts
        $manifestApp = $manifest['app'] ?? null;
        if ($manifestApp !== $tool->slug) {
            throw new RuntimeException("Manifest app '{$manifestApp}' does not match requested tool '{$tool->slug}'.");
        }

        $manifestEngine = $manifest['engine'] ?? null;
        if ($manifestEngine !== $engineSlug) {
            throw new RuntimeException("Manifest engine '{$manifestEngine}' does not match requested engine '{$engineSlug}'.");
        }

        $manifestContract = $manifest['contract'] ?? null;
        if (! is_int($manifestContract) || ! in_array($manifestContract, $contracts, true)) {
            throw new RuntimeException('Manifest contract is not supported by this CLI.');
        }

        $version = $manifest['version'] ?? null;
        if (! is_string($version) || $version === '' || str_contains($version, '..') || str_contains($version, '/') || str_contains($version, '\\')) {
            throw new RuntimeException('Manifest version is missing or invalid.');
        }

        // (e) returned file paths must equal keys in manifest.files, match regex & no '..', and match sha256
        $manifestFiles = $manifest['files'] ?? null;
        if (! is_array($manifestFiles) || $manifestFiles === []) {
            throw new RuntimeException('Manifest contains no template files.');
        }

        $returnedFiles = $this->normalizeReturnedFiles($payload['files'] ?? null);

        foreach (array_keys($manifestFiles) as $manifestPath) {
            $this->assertValidRelativeTemplatePath((string) $manifestPath);
        }

        foreach (array_keys($returnedFiles) as $returnedPath) {
            $this->assertValidRelativeTemplatePath((string) $returnedPath);
        }

        $expectedPaths = array_map('strval', array_keys($manifestFiles));
        $actualPaths = array_map('strval', array_keys($returnedFiles));
        sort($expectedPaths, SORT_STRING);
        sort($actualPaths, SORT_STRING);

        if ($expectedPaths !== $actualPaths) {
            throw new RuntimeException('Returned template files do not match manifest file list.');
        }

        foreach ($manifestFiles as $path => $expectedDigest) {
            $path = (string) $path;
            $content = $returnedFiles[$path];
            $actualDigest = 'sha256:'.hash('sha256', $content);

            if (! is_string($expectedDigest) || ! hash_equals($expectedDigest, $actualDigest)) {
                throw new RuntimeException("Template file hash mismatch for '{$path}'.");
            }
        }

        // (f) write everything into a temp dir under root, then rename() to the final version dir (reuse if already exists and verifies)
        $root = $this->root();
        $versionDir = "{$root}/{$tool->slug}/{$engineSlug}/{$version}";
        $templateFolder = $tool->folder;

        if (! is_dir($versionDir) || ! $this->releaseDirectoryVerifies($versionDir, $templateFolder, $manifest, $signature, $publicKey)) {
            $this->ensureDirectory("{$root}/{$tool->slug}/{$engineSlug}");
            $tempDir = "{$root}/.tmp-{$tool->slug}-{$engineSlug}-".bin2hex(random_bytes(8));
            $this->ensureDirectory($tempDir);

            try {
                $this->writeFile("{$tempDir}/manifest.json", CanonicalJson::encode($manifest));
                $this->writeFile("{$tempDir}/manifest.sig", $signature);
                $this->writeFile("{$tempDir}/key_id", $keyId);

                $viewsBase = "{$tempDir}/views/k8s/{$templateFolder}";
                $this->ensureDirectory($viewsBase);

                foreach ($returnedFiles as $relativePath => $content) {
                    $targetPath = "{$viewsBase}/{$relativePath}";
                    $this->ensureDirectory(dirname($targetPath));
                    $this->writeFile($targetPath, $content);
                }

                if (is_dir($versionDir)) {
                    $this->deleteDirectory($versionDir);
                }

                if (! @rename($tempDir, $versionDir)) {
                    throw new RuntimeException("Failed to move verified release into '{$versionDir}'.");
                }
                @chmod($versionDir, 0755);
            } catch (Throwable $e) {
                $this->deleteDirectory($tempDir);

                throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
            }
        }

        // (g) update lock (trusted_hash reset to null)
        $lockEntry = [
            'version' => $version,
            'contract' => $manifestContract,
            'key_id' => $keyId,
            'fetched_at' => now()->toIso8601String(),
            'trusted_hash' => null,
        ];

        $lock = $this->readLock();
        $lock['apps']["{$tool->slug}/{$engineSlug}"] = $lockEntry;
        $this->writeLock($lock);

        return $lockEntry;
    }

    /**
     * Check every installed release in lock.json and prepend verified (or explicitly trusted) views to the View finder.
     */
    public function activate(): void
    {
        $this->modified = [];
        $this->refused = [];

        $finder = View::getFinder();
        $root = $this->root();
        $resolvedRoot = realpath($root) ?: $root;

        $cleanedPaths = array_values(array_filter(
            $finder->getPaths(),
            static fn (string $path): bool => ! str_starts_with($path, $root.'/')
                && ! str_starts_with($path, $resolvedRoot.'/')
                && ! str_contains(str_replace('\\', '/', $path), '/.larakube/templates/'),
        ));
        $finder->setPaths($cleanedPaths);
        $finder->flush();

        $installed = $this->installed();
        if ($installed === []) {
            return;
        }

        foreach ($installed as $lockKey => $entry) {
            [$app, $engine] = $this->splitLockKey($lockKey);
            $version = (string) ($entry['version'] ?? '');

            if ($app === '' || $version === '') {
                $this->refused[] = $lockKey;

                continue;
            }

            $tool = TemplateApp::find($app);
            $templateFolder = $tool?->folder ?? $app;
            $releaseDir = "{$root}/{$app}/{$engine}/{$version}";
            $manifestPath = "{$releaseDir}/manifest.json";

            if (! is_file($manifestPath)) {
                $this->refused[] = $lockKey;

                continue;
            }

            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            $manifestFiles = is_array($manifest) ? ($manifest['files'] ?? null) : null;

            if (! is_array($manifestFiles) || $manifestFiles === [] || ! $this->cachedSignatureVerifies($releaseDir, $manifest)) {
                $this->refused[] = $lockKey;

                continue;
            }

            $viewsDir = "{$releaseDir}/views";
            $folderDir = "{$viewsDir}/k8s/{$templateFolder}";
            [$allMatch, $combinedHash] = $this->inspectReleaseFiles($folderDir, $manifestFiles);

            if ($allMatch) {
                $finder->prependLocation($viewsDir);

                continue;
            }

            $trustedHash = $entry['trusted_hash'] ?? null;
            if (is_string($trustedHash) && $trustedHash !== '' && hash_equals($trustedHash, $combinedHash)) {
                $finder->prependLocation($viewsDir);
                $this->modified[] = $lockKey;
            } else {
                $this->refused[] = $lockKey;
            }
        }
    }

    /**
     * Record the current combined file hash as trusted_hash for an installed app/engine.
     */
    public function trust(ClusterTool|TemplateApp|string $app, ?string $engine = null): string
    {
        $appSlug = $app instanceof TemplateApp ? $app->slug : ($app instanceof ClusterTool ? $app->value : strtolower(trim($app)));
        $tool = TemplateApp::find($appSlug);
        $engineSlug = ($engine !== null && $engine !== '') ? $engine : ($tool?->defaultEngine() ?? 'default');
        $lockKey = "{$appSlug}/{$engineSlug}";

        $lock = $this->readLock();
        $entry = $lock['apps'][$lockKey] ?? null;
        if (! is_array($entry)) {
            throw new RuntimeException("No installed template release found for '{$lockKey}'.");
        }

        $version = (string) ($entry['version'] ?? '');
        $releaseDir = "{$this->root()}/{$appSlug}/{$engineSlug}/{$version}";
        $manifestPath = "{$releaseDir}/manifest.json";

        if (! is_file($manifestPath)) {
            throw new RuntimeException("Manifest file is missing for '{$lockKey}'.");
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $manifestFiles = is_array($manifest) ? ($manifest['files'] ?? null) : null;
        if (! is_array($manifestFiles) || $manifestFiles === []) {
            throw new RuntimeException("Manifest file is invalid for '{$lockKey}'.");
        }

        $templateFolder = $tool?->folder ?? $appSlug;
        $folderDir = "{$releaseDir}/views/k8s/{$templateFolder}";
        [, $combinedHash] = $this->inspectReleaseFiles($folderDir, $manifestFiles);

        $lock['apps'][$lockKey]['trusted_hash'] = $combinedHash;
        $this->writeLock($lock);

        return $combinedHash;
    }

    /**
     * Return relative file paths in an installed release whose hash differs from the manifest.
     *
     * @return list<string>
     */
    public function modifiedFiles(ClusterTool|TemplateApp|string $app, ?string $engine = null): array
    {
        $appSlug = $app instanceof TemplateApp ? $app->slug : ($app instanceof ClusterTool ? $app->value : strtolower(trim($app)));
        $tool = TemplateApp::find($appSlug);
        $engineSlug = ($engine !== null && $engine !== '') ? $engine : ($tool?->defaultEngine() ?? 'default');
        $lockKey = "{$appSlug}/{$engineSlug}";

        $installed = $this->installed();
        $entry = $installed[$lockKey] ?? null;
        if (! is_array($entry)) {
            throw new RuntimeException("No installed template release found for '{$lockKey}'.");
        }

        $version = (string) ($entry['version'] ?? '');
        $releaseDir = "{$this->root()}/{$appSlug}/{$engineSlug}/{$version}";
        $manifestPath = "{$releaseDir}/manifest.json";

        if (! is_file($manifestPath)) {
            return ['manifest.json'];
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $manifestFiles = is_array($manifest) ? ($manifest['files'] ?? null) : null;
        if (! is_array($manifestFiles)) {
            return ['manifest.json'];
        }

        $templateFolder = $tool?->folder ?? $appSlug;
        $folderDir = "{$releaseDir}/views/k8s/{$templateFolder}";
        $changed = [];

        foreach ($manifestFiles as $relativePath => $expectedDigest) {
            $relativePath = (string) $relativePath;
            $fullPath = "{$folderDir}/{$relativePath}";

            if (! is_file($fullPath)) {
                $changed[] = $relativePath;

                continue;
            }

            $actualDigest = 'sha256:'.hash_file('sha256', $fullPath);
            if (! is_string($expectedDigest) || ! hash_equals($expectedDigest, $actualDigest)) {
                $changed[] = $relativePath;
            }
        }

        foreach ($this->scanBladeFiles($folderDir) as $diskRelativePath) {
            if (! array_key_exists($diskRelativePath, $manifestFiles)) {
                $changed[] = $diskRelativePath;
            }
        }

        sort($changed, SORT_STRING);

        return array_values(array_unique($changed));
    }

    /**
     * Lock entries keyed by "<app>/<engine>".
     *
     * @return array<string, array{version: string, contract: int, key_id: string, fetched_at: string, trusted_hash: string|null}>
     */
    public function installed(): array
    {
        $lock = $this->readLock();

        return is_array($lock['apps'] ?? null) ? $lock['apps'] : [];
    }

    /**
     * @return list<string>
     */
    public function modified(): array
    {
        return $this->modified;
    }

    /**
     * @return list<string>
     */
    public function refused(): array
    {
        return $this->refused;
    }

    /**
     * Ensure templates exist for a k8s view folder: when the bundled directory is absent
     * and no lock entry exists for the matching tool, download and activate it.
     */
    public function ensureFor(string $folder): void
    {
        if (is_dir($this->bundledPathFor($folder))) {
            return;
        }

        $tool = TemplateApp::forFolder($folder);

        // Checking whether a template exists must never reach the network for an app that still ships in this binary.
        if ($tool === null || ! in_array($tool->slug, (array) config('templates.downloadable', []), true)) {
            return;
        }

        $installed = $this->installed();
        foreach (array_keys($installed) as $lockKey) {
            if (str_starts_with($lockKey, $tool->slug.'/')) {
                return;
            }
        }

        $this->download($tool, $tool->defaultEngine());
        $this->activate();
    }

    /**
     * Re-checks the saved manifest against its saved signature, offline, so editing a file and its
     * manifest together on disk still can't pass as a signed release.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function cachedSignatureVerifies(string $releaseDir, array $manifest): bool
    {
        $signature = trim((string) @file_get_contents("{$releaseDir}/manifest.sig"));
        $keyId = trim((string) @file_get_contents("{$releaseDir}/key_id"));
        $key = TrustedKeys::all()[$keyId] ?? null;

        return $signature !== '' && is_string($key) && ReleaseVerifier::verify($manifest, $signature, $key);
    }

    private function bundledPathFor(string $folder): string
    {
        if ($this->bundledPathResolver !== null) {
            return ($this->bundledPathResolver)($folder);
        }

        return base_path("resources/views/k8s/{$folder}");
    }

    /**
     * @return list<string>
     */
    private function fetchRevokedKeyIds(LaraKubeCloudConnector $connector): array
    {
        try {
            $response = $connector->send(new SigningKeys);
            if (! $response->successful()) {
                return [];
            }

            $body = $response->json();
            $items = $body['keys'] ?? $body['data'] ?? (is_array($body) && array_is_list($body) ? $body : []);
            if (! is_array($items)) {
                return [];
            }

            $revoked = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $id = $item['key_id'] ?? $item['id'] ?? null;
                $isRevoked = ($item['revoked'] ?? false) === true || ! empty($item['revoked_at']);

                if (is_string($id) && $id !== '' && $isRevoked) {
                    $revoked[] = $id;
                }
            }

            return $revoked;
        } catch (Throwable) {
            // If the signing-keys request fails, continue with local trust only.
            return [];
        }
    }

    /**
     * Normalize returned files from either `{"path.blade.php": "content"}` or `[{"path": "...", "content": "..."}]`.
     *
     * @return array<string, string>
     */
    private function normalizeReturnedFiles(mixed $rawFiles): array
    {
        if (! is_array($rawFiles) || $rawFiles === []) {
            throw new RuntimeException('Release payload contains no template files.');
        }

        $normalized = [];

        if (array_is_list($rawFiles)) {
            foreach ($rawFiles as $item) {
                if (! is_array($item) || ! is_string($item['path'] ?? null) || ! is_string($item['content'] ?? null)) {
                    throw new RuntimeException('Malformed file entry in release payload.');
                }

                $path = $item['path'];
                if (array_key_exists($path, $normalized)) {
                    throw new RuntimeException("Duplicate template file path '{$path}' in release payload.");
                }

                $normalized[$path] = $item['content'];
            }

            return $normalized;
        }

        foreach ($rawFiles as $path => $value) {
            if (! is_string($path)) {
                throw new RuntimeException('Malformed file path key in release payload.');
            }

            if (is_string($value)) {
                $normalized[$path] = $value;
            } elseif (is_array($value) && is_string($value['content'] ?? null)) {
                $normalized[$path] = $value['content'];
            } else {
                throw new RuntimeException("Malformed content for template file '{$path}'.");
            }
        }

        return $normalized;
    }

    private function assertValidRelativeTemplatePath(string $path): void
    {
        if (str_contains($path, '..') || preg_match(self::FILE_PATH_PATTERN, $path) !== 1) {
            throw new RuntimeException("Invalid template file path '{$path}'.");
        }
    }

    /**
     * @param  array<string, mixed>  $manifestFiles
     * @return array{0: bool, 1: string}
     */
    private function inspectReleaseFiles(string $folderDir, array $manifestFiles): array
    {
        $allMatch = true;
        $hashesByPath = [];

        foreach ($manifestFiles as $relativePath => $expectedDigest) {
            $relativePath = (string) $relativePath;
            $fullPath = "{$folderDir}/{$relativePath}";

            if (! is_file($fullPath)) {
                $allMatch = false;
                $hashesByPath[$relativePath] = 'missing';

                continue;
            }

            $hex = (string) hash_file('sha256', $fullPath);
            $actualDigest = 'sha256:'.$hex;
            $hashesByPath[$relativePath] = $hex;

            if (! is_string($expectedDigest) || ! hash_equals($expectedDigest, $actualDigest)) {
                $allMatch = false;
            }
        }

        foreach ($this->scanBladeFiles($folderDir) as $diskRelativePath) {
            if (! array_key_exists($diskRelativePath, $hashesByPath)) {
                $allMatch = false;
                $hashesByPath[$diskRelativePath] = (string) hash_file('sha256', "{$folderDir}/{$diskRelativePath}");
            }
        }

        ksort($hashesByPath, SORT_STRING);

        $lines = [];
        foreach ($hashesByPath as $path => $sha256) {
            $lines[] = "{$path}:{$sha256}";
        }

        return [$allMatch, hash('sha256', implode("\n", $lines))];
    }

    /**
     * @return list<string>
     */
    private function scanBladeFiles(string $folderDir): array
    {
        if (! is_dir($folderDir)) {
            return [];
        }

        $paths = [];
        $baseLen = strlen(rtrim($folderDir, '/\\')) + 1;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folderDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (! $fileInfo->isFile()) {
                continue;
            }

            $relativePath = str_replace('\\', '/', substr($fileInfo->getPathname(), $baseLen));
            if (str_ends_with($relativePath, '.blade.php')) {
                $paths[] = $relativePath;
            }
        }

        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $expectedManifest
     */
    private function releaseDirectoryVerifies(
        string $versionDir,
        string $templateFolder,
        array $expectedManifest,
        string $expectedSignature,
        string $publicKey,
    ): bool {
        $manifestPath = "{$versionDir}/manifest.json";
        $sigPath = "{$versionDir}/manifest.sig";

        if (! is_file($manifestPath) || ! is_file($sigPath)) {
            return false;
        }

        $diskManifest = json_decode((string) file_get_contents($manifestPath), true);
        $diskSig = trim((string) file_get_contents($sigPath));

        if (! is_array($diskManifest) || $diskSig !== $expectedSignature) {
            return false;
        }

        if (! ReleaseVerifier::verify($diskManifest, $diskSig, $publicKey)) {
            return false;
        }

        $manifestFiles = $expectedManifest['files'] ?? null;
        if (! is_array($manifestFiles)) {
            return false;
        }

        [$allMatch] = $this->inspectReleaseFiles("{$versionDir}/views/k8s/{$templateFolder}", $manifestFiles);

        return $allMatch;
    }

    /**
     * @return array{apps: array<string, array{version: string, contract: int, key_id: string, fetched_at: string, trusted_hash: string|null}>}
     */
    private function readLock(): array
    {
        $path = $this->lockPath();
        if (! is_file($path)) {
            return ['apps' => []];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ! is_array($decoded['apps'] ?? null)) {
            throw new RuntimeException('Template lock file is corrupted.');
        }

        return ['apps' => $decoded['apps']];
    }

    /**
     * @param  array{apps: array<string, mixed>}  $lock
     */
    private function writeLock(array $lock): void
    {
        $root = $this->root();
        $this->ensureDirectory($root);

        $tempPath = "{$root}/.lock.json.tmp.".bin2hex(random_bytes(8));
        $encoded = json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        $this->writeFile($tempPath, $encoded);

        if (! @rename($tempPath, $this->lockPath())) {
            @unlink($tempPath);

            throw new RuntimeException('Failed to atomically write template lock.json.');
        }

        @chmod($this->lockPath(), 0644);
    }

    private function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            $parent = dirname($dir);
            if ($parent !== $dir && ! is_dir($parent)) {
                $this->ensureDirectory($parent);
            }

            if (! @mkdir($dir, 0755) && ! is_dir($dir)) {
                throw new RuntimeException("Unable to create directory '{$dir}'.");
            }
        }

        @chmod($dir, 0755);
    }

    private function writeFile(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException("Unable to write file '{$path}'.");
        }

        @chmod($path, 0644);
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitLockKey(string $lockKey): array
    {
        if (! str_contains($lockKey, '/')) {
            return [$lockKey, 'default'];
        }

        [$app, $engine] = explode('/', $lockKey, 2);

        return [$app, $engine !== '' ? $engine : 'default'];
    }

    private function extractErrorMessage(Response $response, string $fallback): string
    {
        $json = $response->json();
        if (is_array($json)) {
            $message = $json['message'] ?? $json['error'] ?? null;
            if (is_string($message) && trim($message) !== '') {
                return trim($message);
            }
        }

        $body = trim($response->body());

        return $body !== '' ? $body : $fallback;
    }
}
