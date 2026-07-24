<?php

namespace App\Commands\Storage;

use App\Data\ConfigData;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithStorage;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class StorageListCommand extends Command
{
    use DeploysClusterTool, InteractsWithPlex, InteractsWithStorage, LaraKubeOutput;

    protected $signature = 'storage:list
        {environment=local : Environment whose bucket files to list}
        {--bucket=  : Name of the S3 bucket to list}
        {--path=    : Optional subpath inside the bucket}
        {--driver=  : Specific storage driver (seaweedfs, minio, garage)}
        {--context= : Target a specific kube-context}';

    protected $description = 'List objects and files inside an S3 bucket';

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
                label: 'Name of the S3 bucket to list files from',
                placeholder: 'e.g. stalwart',
                required: true,
            );
        }

        $bucket = strtolower(trim($bucket));
        $path = (string) ($this->option('path') ?? '');

        $objects = $this->fetchBucketObjects($kubectl, $driver, $bucket, $path);

        $this->newLine();
        $target = $path !== '' ? "{$bucket}/".ltrim($path, '/') : $bucket;
        $this->line(" <fg=blue>Files in '{$target}' ({$driver->getLabel()})</>");
        $this->line(' '.str_repeat('─', 50));
        $this->newLine();

        if (empty($objects)) {
            $this->line('  <fg=gray>(bucket is empty or path has no items)</>');
            $this->newLine();

            return 0;
        }

        \Laravel\Prompts\table(
            headers: ['Object / Key'],
            rows: array_map(fn ($o) => [$o], $objects),
        );
        $this->newLine();

        return 0;
    }
}
