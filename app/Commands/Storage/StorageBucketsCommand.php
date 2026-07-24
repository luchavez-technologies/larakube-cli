<?php

namespace App\Commands\Storage;

use App\Data\ConfigData;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithStorage;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class StorageBucketsCommand extends Command
{
    use DeploysClusterTool, InteractsWithPlex, InteractsWithStorage, LaraKubeOutput;

    protected $signature = 'storage:buckets
        {environment=local : Environment whose S3 buckets to list}
        {--driver=  : Specific storage driver (seaweedfs, minio, garage)}
        {--context= : Target a specific kube-context}';

    protected $description = 'List all S3 buckets provisioned on the storage engine';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = (string) $this->option('context') ?: null;
        if (! $context && $config && $env !== 'local') {
            $context = $this->environmentContextOrCurrent($config, $env);
        }

        $this->plexContext = $context;
        $kubectl = $this->plexKubectl();

        $driver = $this->resolveStorageDriver($env, (string) $this->option('driver'));
        if ($driver === null) {
            return 1;
        }

        $buckets = $this->fetchStorageBuckets($kubectl, $driver);

        $this->newLine();
        $this->line(" <fg=blue>Buckets on {$driver->getLabel()} ({$env})</>");
        $this->line(' '.str_repeat('─', 45));
        $this->newLine();

        if (empty($buckets)) {
            $this->line('  <fg=gray>No buckets found on this storage engine.</>');
            $this->line("  <fg=gray>Create one with:</> <fg=blue>larakube storage:make-bucket {$env} --bucket=<name></>");
            $this->newLine();

            return 0;
        }

        \Laravel\Prompts\table(
            headers: ['Bucket', 'Storage Driver'],
            rows: array_map(fn ($b) => [$b, $driver->getLabel()], $buckets),
        );
        $this->newLine();

        return 0;
    }
}
