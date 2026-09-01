<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Enums\StorageDriver;
use Illuminate\Support\Facades\Process;

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
