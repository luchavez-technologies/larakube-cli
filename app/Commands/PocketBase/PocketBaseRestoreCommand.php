<?php

namespace App\Commands\PocketBase;

use App\Enums\ClusterTool;
use App\Services\Kubectl;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class PocketBaseRestoreCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolHost, StreamsProcessOutput;

    protected $signature = 'pocketbase:restore
        {file : Path to the backup tar.gz file to restore}
        {--environment=local : Environment to restore PocketBase to}
        {--context= : Target a specific kube-context}
        {--domain= : Target a specific registered instance by domain/host}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Restore PocketBase data and SQLite database from a backup file';

    public function handle(): int
    {
        $this->renderHeader();

        $file = (string) $this->argument('file');
        if (! file_exists($file)) {
            $this->laraKubeError("Backup file '{$file}' does not exist.");

            return 1;
        }

        $env = (string) ($this->option('environment') ?: 'local');
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = Kubectl::forContext($context)->prefix();

        $domain = (string) ($this->option('domain') ?: '');
        $instance = $this->resolveInstanceForDomain($kubectl, ClusterTool::POCKETBASE, $domain);
        $deployment = ClusterTool::POCKETBASE->deploymentName($instance);
        $ns = ClusterTool::POCKETBASE->namespace();

        if (! $this->confirmDestructive(["Restore PocketBase data from {$file}? This will overwrite active /pb_data."])) {
            return 0;
        }

        $pod = trim(Process::run("{$kubectl} get pod -l app={$deployment} -n {$ns} -o jsonpath='{.items[0].metadata.name}'")->output());
        if ($pod === '') {
            $this->laraKubeError("No running PocketBase pod found for {$deployment}.");

            return 1;
        }

        $this->laraKubeInfo("Restoring PocketBase data into {$deployment}...");

        $cmd = "cat \"{$file}\" | {$kubectl} exec -i {$pod} -n {$ns} -- tar -xzf - -C /";
        $result = Process::run($cmd);

        if (! $result->successful()) {
            $this->laraKubeError('Restore failed: '.$result->errorOutput());

            return 1;
        }

        $this->withSpin('Restarting PocketBase deployment...', fn () => Process::run("{$kubectl} rollout restart deployment/{$deployment} -n {$ns}"));

        $this->laraKubeInfo('PocketBase restored successfully and deployment restarted.');

        return 0;
    }
}
