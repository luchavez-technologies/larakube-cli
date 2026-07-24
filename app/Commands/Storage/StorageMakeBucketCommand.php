<?php

namespace App\Commands\Storage;

use App\Data\ConfigData;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithStorage;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class StorageMakeBucketCommand extends Command
{
    use DeploysClusterTool, InteractsWithPlex, InteractsWithStorage, LaraKubeOutput;

    protected $signature = 'storage:make-bucket
        {environment=local : Environment whose storage engine to create the bucket in}
        {--bucket=  : Name of the S3 bucket to create}
        {--driver=  : Specific storage driver (seaweedfs, minio, garage)}
        {--context= : Target a specific kube-context}';

    protected $description = 'Create an S3 bucket on the cluster storage engine';

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

        $bucket = (string) ($this->option('bucket') ?? '');
        if ($bucket === '') {
            $bucket = text(
                label: 'Name of the S3 bucket to create',
                placeholder: 'e.g. uploads',
                required: true,
            );
        }

        $bucket = strtolower(trim($bucket));

        if (! $this->createStorageBucket($kubectl, $driver, $bucket)) {
            return 1;
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ Created bucket '{$bucket}' on {$driver->getLabel()}.");
        $this->line("  <fg=gray>Inspect bucket files:</> <fg=blue>larakube storage:list {$env} --bucket={$bucket}</>");
        $this->newLine();

        return 0;
    }
}
