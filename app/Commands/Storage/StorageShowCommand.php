<?php

namespace App\Commands\Storage;

use App\Data\ConfigData;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithStorage;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class StorageShowCommand extends Command
{
    use DeploysClusterTool, InteractsWithPlex, InteractsWithStorage, LaraKubeOutput;

    protected $signature = 'storage:show
        {environment=local : Environment whose storage service to show}
        {--driver=  : Specific storage driver (seaweedfs, minio, garage)}
        {--context= : Target a specific kube-context}';

    protected $description = 'Show Commons S3 storage status, endpoints, and credentials';

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

        $ns = $this->plexNamespace();
        $creds = $this->readCommonsS3Credentials();

        $this->newLine();
        $this->line(" <fg=blue>Commons S3 Storage ({$driver->getLabel()})</>");
        $this->line(' '.str_repeat('─', 50));
        $this->newLine();

        $host = $driver->value.".{$ns}.svc.cluster.local";
        $port = $driver->port();

        $this->line("  <fg=gray>Driver:</>          <fg=cyan>{$driver->getLabel()}</>");
        $this->line("  <fg=gray>Internal Endpoint:</> <fg=blue>http://{$host}:{$port}</>");
        $this->line('  <fg=gray>Region:</>            <fg=blue>us-east-1</>');

        if ($creds !== null) {
            $this->line("  <fg=gray>Access Key (Key ID):</> <fg=yellow>{$creds['access']}</>");
            $this->line("  <fg=gray>Secret Key:</>           <fg=yellow>{$creds['secret']}</>");
        }

        $this->newLine();

        $buckets = $this->fetchStorageBuckets($kubectl, $driver);
        $count = count($buckets);

        $this->line("  <fg=gray>Provisioned Buckets ({$count}):</>");
        if ($count === 0) {
            $this->line('    <fg=gray>(none)</>');
        } else {
            foreach ($buckets as $b) {
                $this->line("    • <fg=cyan>{$b}</>");
            }
        }

        $this->newLine();
        $this->line('  <fg=gray>Management commands:</>');
        $this->line("    • List buckets:  <fg=blue>larakube storage:buckets {$env}</>");
        $this->line("    • Create bucket: <fg=blue>larakube storage:make-bucket {$env} --bucket=<name></>");
        $this->line("    • List files:    <fg=blue>larakube storage:list {$env} --bucket=<name></>");
        $this->newLine();

        return 0;
    }
}
