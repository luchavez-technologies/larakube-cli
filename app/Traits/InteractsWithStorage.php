<?php

namespace App\Traits;

use App\Enums\StorageDriver;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

trait InteractsWithStorage
{
    use InteractsWithPlex;

    /**
     * Resolve which S3 storage driver to inspect/operate on for the target environment.
     * Auto-detects if 1 driver is active in Commons; prompts if multiple exist and none is specified.
     */
    protected function resolveStorageDriver(string $env, ?string $explicitDriver = null): ?StorageDriver
    {
        $explicitDriver = strtolower(trim((string) $explicitDriver));
        if ($explicitDriver !== '') {
            $driver = StorageDriver::tryFrom($explicitDriver);
            if ($driver === null) {
                $this->laraKubeError("Unknown storage driver '{$explicitDriver}'.");
                $this->line('  <fg=gray>Supported drivers:</> seaweedfs, minio, garage');

                return null;
            }

            return $driver;
        }

        $spec = $this->getCommonsSpec();
        if ($spec === null) {
            $this->laraKubeError("No Plex Commons found for '{$env}'.");
            $this->line('  <fg=gray>Run</> <fg=blue>larakube plex:init</> <fg=gray>first.</>');

            return null;
        }

        $services = $this->enabledCommonsServices($spec);
        $active = [];
        foreach (['seaweedfs', 'minio', 'garage'] as $candidate) {
            if (in_array($candidate, $services, true)) {
                $driver = StorageDriver::tryFrom($candidate);
                if ($driver !== null) {
                    $active[$candidate] = $driver;
                }
            }
        }

        if (empty($active)) {
            $this->laraKubeError("No S3 storage service (SeaweedFS, MinIO, Garage) is enabled in the Plex Commons for '{$env}'.");
            $this->line('  <fg=gray>Enable one with</> <fg=blue>larakube plex:init</><fg=gray>.</>');

            return null;
        }

        if (count($active) === 1) {
            return array_values($active)[0];
        }

        // Multiple active drivers coexisting: prompt user to select
        $options = [];
        foreach ($active as $key => $driver) {
            $options[$key] = $driver->getLabel();
        }

        $chosen = select(
            label: 'Multiple storage engines detected. Which one do you want to inspect?',
            options: $options,
        );

        return $active[$chosen] ?? null;
    }

    /**
     * List all buckets on the active S3 storage driver.
     *
     * @return array<int, string>
     */
    protected function fetchStorageBuckets(string $kubectl, StorageDriver $driver): array
    {
        $ns = $this->plexNamespace();
        $service = $driver->value;
        $cmd = $driver->commonsBucketListCommand();

        $res = Process::run(
            $kubectl.' exec -n '.escapeshellarg($ns).' deploy/'.$service.' -- sh -c '.escapeshellarg($cmd),
        );

        if (! $res->successful()) {
            return [];
        }

        $lines = array_map('trim', explode("\n", trim($res->output())));
        $buckets = [];

        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/\x1b\[[0-9;]*[mGKB]/', '', $line));
            if ($line === '' || str_starts_with($line, 'mc:') || str_starts_with($line, '>') || str_contains($line, 'Command:')) {
                continue;
            }

            $tokens = preg_split('/\s+/', $line) ?: [];
            foreach ($tokens as $token) {
                $token = rtrim(trim($token), '/');
                if (is_numeric($token) || strlen($token) < 2) {
                    continue;
                }
                if (in_array(strtolower($token), ['local', 'ok', 'id', 'name', 'bucket', 'buckets', 'index', 'size', 'date'], true)) {
                    continue;
                }
                if (preg_match('/^[a-z0-9][a-z0-9\.\-]{1,61}[a-z0-9]$/i', $token)) {
                    $buckets[] = $token;
                }
            }
        }

        return array_values(array_unique($buckets));
    }

    /**
     * Create an S3 bucket on the active storage engine.
     */
    protected function createStorageBucket(string $kubectl, StorageDriver $driver, string $bucket): bool
    {
        return $this->allocateStorageBucket($driver, $bucket);
    }

    /**
     * List files/objects inside a bucket.
     *
     * @return array<int, string>
     */
    protected function fetchBucketObjects(string $kubectl, StorageDriver $driver, string $bucket, ?string $path = null): array
    {
        $ns = $this->plexNamespace();
        $service = $driver->value;
        $cmd = $driver->commonsObjectListCommand($bucket, $path);

        $res = Process::run(
            $kubectl.' exec -n '.escapeshellarg($ns).' deploy/'.$service.' -- sh -c '.escapeshellarg($cmd),
        );

        if (! $res->successful()) {
            return [];
        }

        $lines = array_map('trim', explode("\n", trim($res->output())));

        return array_values(array_filter($lines, fn ($l) => $l !== ''));
    }
}
