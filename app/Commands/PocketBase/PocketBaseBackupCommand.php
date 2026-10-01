<?php

namespace App\Commands\PocketBase;

use App\Enums\ClusterTool;
use App\Services\Kubectl;
use App\Traits\DeploysClusterTool;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class PocketBaseBackupCommand extends Command
{
    use DeploysClusterTool, LaraKubeOutput, ResolvesToolHost, StreamsProcessOutput;

    protected $signature = 'pocketbase:backup
        {environment=local : Environment to backup PocketBase from}
        {--context= : Target a specific kube-context}
        {--domain= : Target a specific registered instance by domain/host}
        {--output= : Destination path for the backup file (defaults to ./pocketbase-backup-{timestamp}.tar.gz)}';

    protected $description = 'Backup PocketBase data and SQLite database';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = Kubectl::forContext($context)->prefix();

        $domain = (string) ($this->option('domain') ?: '');
        $instance = $this->resolveInstanceForDomain($kubectl, ClusterTool::POCKETBASE, $domain);
        $deployment = ClusterTool::POCKETBASE->deploymentName($instance);
        $ns = ClusterTool::POCKETBASE->namespace();

        $output = (string) ($this->option('output') ?: 'pocketbase-backup-'.Carbon::now()->format('Y-m-d-His').'.tar.gz');

        $this->laraKubeInfo("Creating PocketBase backup for {$deployment} into {$output}...");

        $pod = trim(Process::run("{$kubectl} get pod -l app={$deployment} -n {$ns} -o jsonpath='{.items[0].metadata.name}'")->output());
        if ($pod === '') {
            $this->laraKubeError("No running PocketBase pod found for {$deployment}.");

            return 1;
        }

        $cmd = "{$kubectl} exec {$pod} -n {$ns} -- tar -czf - /pb_data > \"{$output}\"";
        $result = Process::run($cmd);

        if (! $result->successful()) {
            $this->laraKubeError('Backup failed: '.$result->errorOutput());

            return 1;
        }

        $this->laraKubeInfo("PocketBase backup created successfully: {$output}");

        return 0;
    }
}
