<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\StorageDriver;
use FilesystemIterator;
use Illuminate\Support\Facades\Process;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Build a static site and publish it into a Plex Commons bucket.
 *
 * The bundle is the deploy artifact, not a container image: one generic upstream
 * Caddy image serves every project, so shipping a content change needs neither an
 * image build nor a registry. That is what lets a Forgejo repo with no registry
 * configured deploy at all.
 *
 * Uploads go through the PUBLIC S3 endpoint rather than `kubectl exec` into
 * larakube-plex, because the same code has to run from CI, which has no cluster
 * access. `aws` runs in a container so the developer machine needs nothing
 * installed — the same reasoning as running create-next-app in Docker.
 */
trait PublishesStaticSite
{
    use InteractsWithPlex;

    /** Image already pinned and vetted elsewhere in this repo (backup CronJob). */
    protected const AWS_CLI_IMAGE = 'amazon/aws-cli:2.27.22';

    /**
     * Build and upload. Returns the release prefix to point the cluster at, or
     * null if any step failed.
     */
    protected function publishStaticSite(ConfigData $config, string $environment): ?string
    {
        $framework = $config->framework;
        $projectPath = $config->getPath();

        $buildCommand = $framework?->staticBuildCommand($config->getPackageManager());
        $outputDir = $framework?->staticOutputDir();

        if ($buildCommand === null || $outputDir === null) {
            $this->laraKubeError('This project is not a static site — nothing to publish.');

            return null;
        }

        // 1. Build.
        $this->laraKubeInfo("Building the static bundle ({$buildCommand})...");
        if (! Process::path($projectPath)->forever()->run($buildCommand)->successful()) {
            $this->laraKubeError("Build failed: {$buildCommand}");

            return null;
        }

        $distPath = "{$projectPath}/{$outputDir}";
        if (! is_dir($distPath) || $this->staticSiteIsEmpty($distPath)) {
            $this->laraKubeError("Build produced nothing in {$outputDir}/ — refusing to publish an empty site.");

            return null;
        }

        if (! $this->assertNoLocalHostsInBundle($distPath, $environment)) {
            return null;
        }

        // 2. Commons bucket.
        $storage = $config->getObjectStorage() ?? StorageDriver::SEAWEEDFS;
        $bucket = $this->staticSiteBucket($config);

        if (! $this->ensureCommons([$storage->value])) {
            return null;
        }
        if (! $this->allocateStorageBucket($storage, $bucket)) {
            return null;
        }

        $credentials = $this->readCommonsS3Credentials();
        if ($credentials === null) {
            $this->laraKubeError('Could not read the Commons S3 credentials (secret plex-admin).');

            return null;
        }

        $endpoints = $this->resolveCommonsS3Endpoints($storage, 'Static Site');
        $endpoint = $endpoints['public'] ?? $endpoints['internal'] ?? null;
        if ($endpoint === null) {
            $this->laraKubeError('The Commons object storage has no reachable endpoint.');

            return null;
        }

        // 3. Upload under an immutable, per-release prefix. A half-finished
        //    upload can never be served, because nothing points at the new
        //    prefix until the release ConfigMap is rewritten.
        $release = $environment.'/'.$this->staticSiteReleaseId($projectPath);

        $this->laraKubeInfo("Publishing to s3://{$bucket}/{$release}/ ...");

        foreach ($this->staticSiteUploadCommands($distPath, $bucket, $release, $endpoint, $credentials) as $label => $command) {
            if ($this->runStreaming($command) !== 0) {
                $this->laraKubeError("Upload failed during the {$label} pass.");

                return null;
            }
        }

        return $release;
    }

    /**
     * Refuse to publish a bundle that still points at a local host.
     *
     * Vite bakes env vars at BUILD time, not runtime: `vite build` loads
     * .env.{mode} and falls back to .env when it is missing. So a project whose
     * .env.production was never written ships the LOCAL PocketBase URL to
     * production — a working build, a green deploy, and a site that quietly
     * talks to a host nobody outside the developer's machine can resolve.
     *
     * Nothing downstream can catch this: the bytes are already compiled, so the
     * only place to notice is here, between build and upload.
     */
    protected function assertNoLocalHostsInBundle(string $distPath, string $environment): bool
    {
        $tlds = implode('|', array_map('preg_quote', GlobalConfigData::ALLOWED_TLDS));
        $pattern = '#https?://[a-z0-9.-]+\.('.$tlds.')(?![a-z0-9-])#i';

        $found = [];

        foreach ($this->bundleTextFiles($distPath) as $file) {
            if (preg_match_all($pattern, (string) file_get_contents($file), $m)) {
                foreach ($m[0] as $hit) {
                    $found[$hit] = true;
                }
            }
        }

        if ($found === []) {
            return true;
        }

        $this->laraKubeError('The built bundle still references local hosts — refusing to publish.');
        foreach (array_keys($found) as $url) {
            $this->line("  <fg=red>- {$url}</>");
        }
        $this->newLine();
        $this->line("  <fg=gray>Vite reads .env.{$environment} at BUILD time. Point it at real hosts, then re-deploy:</>");
        $this->line("  <fg=yellow>larakube data:wire {$environment}</> <fg=gray>(and check .env.{$environment})</>");

        return false;
    }

    /**
     * Built assets worth scanning — compiled JS/CSS and HTML. Images and fonts
     * cannot carry a baked URL, and reading them would only be slow.
     *
     * @return list<string>
     */
    protected function bundleTextFiles(string $distPath): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($distPath, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), ['js', 'mjs', 'cjs', 'css', 'html', 'json', 'map'], true)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Two passes, because the cache policy differs by file class and getting it
     * wrong is silent: a cached index.html keeps returning visitors on the old
     * bundle's asset URLs long after a deploy.
     *
     * @param  array{access: string, secret: string}  $credentials
     * @return array<string, string>
     */
    protected function staticSiteUploadCommands(
        string $distPath,
        string $bucket,
        string $release,
        string $endpoint,
        array $credentials,
    ): array {
        $base = 'docker run --rm '
            .'-v '.escapeshellarg($distPath).':/dist '
            .'-e AWS_ACCESS_KEY_ID='.escapeshellarg($credentials['access']).' '
            .'-e AWS_SECRET_ACCESS_KEY='.escapeshellarg($credentials['secret']).' '
            .'-e AWS_DEFAULT_REGION=us-east-1 '
            .self::AWS_CLI_IMAGE.' '
            .'--endpoint-url '.escapeshellarg($endpoint).' --no-progress '
            .'s3 sync /dist '.escapeshellarg("s3://{$bucket}/{$release}/");

        return [
            // Hashed filenames are content-addressed and can never go stale.
            'immutable assets' => $base.' --exclude "*.html" '
                .'--cache-control "public, max-age=31536000, immutable"',
            // The entry point must always be revalidated.
            'html entry points' => $base.' --exclude "*" --include "*.html" '
                .'--cache-control "no-cache"',
        ];
    }

    /**
     * Release identifier. Git sha when available so a release is traceable back
     * to a commit; a timestamp otherwise so an untracked directory still gets a
     * unique, ordered prefix.
     */
    protected function staticSiteReleaseId(string $projectPath): string
    {
        $sha = trim(Process::path($projectPath)->run('git rev-parse --short HEAD')->output());

        return $sha !== '' ? $sha.'-'.time() : (string) time();
    }

    protected function staticSiteIsEmpty(string $path): bool
    {
        $entries = scandir($path) ?: [];

        return array_values(array_diff($entries, ['.', '..'])) === [];
    }
}
