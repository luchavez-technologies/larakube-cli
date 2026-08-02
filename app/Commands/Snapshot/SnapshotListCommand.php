<?php

namespace App\Commands\Snapshot;

use App\Traits\CheckPrerequisites;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class SnapshotListCommand extends Command
{
    use CheckPrerequisites, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'snapshot:list
        {environment=local : Target environment}
        {--json : Emit machine-readable JSON}';

    /**
     * The console command description.
     */
    protected $description = 'List all Kubernetes VolumeSnapshots with friendly application and PVC mappings';

    public function handle(): int
    {
        $this->renderHeader();

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);
        $namespace = $config ? $config->getName() : 'default';

        $this->laraKubeInfo("Fetching VolumeSnapshots in namespace '{$namespace}'...");

        $cmd = "kubectl get volumesnapshot -n {$namespace} -o json 2>&1";
        $result = Process::run($cmd);

        $snapshots = [];
        if ($result->exitCode() === 0) {
            $json = json_decode($result->output(), true) ?? [];
            $items = $json['items'] ?? [];

            foreach ($items as $item) {
                $name = $item['metadata']['name'] ?? 'unknown';
                $pvc = $item['spec']['source']['persistentVolumeClaimName'] ?? 'unknown';
                $created = $item['metadata']['creationTimestamp'] ?? 'unknown';
                $ready = ($item['status']['readyToUse'] ?? false) ? '🟢 Ready' : '🟡 Creating';
                $restoreSize = $item['status']['restoreSize'] ?? 'N/A';

                // App/Tool Identification from labels or PVC prefix
                $pvcParts = explode('-', $pvc);
                $appTag = $item['metadata']['labels']['app.kubernetes.io/name']
                    ?? $item['metadata']['labels']['app']
                    ?? $pvcParts[0];

                $snapshots[] = [
                    'name' => $name,
                    'app' => $appTag,
                    'pvc' => $pvc,
                    'ready' => $ready,
                    'size' => $restoreSize,
                    'created' => $created,
                ];
            }
        }

        if (empty($snapshots)) {
            $this->newLine();
            $this->line("  <fg=gray>No VolumeSnapshots found in namespace '{$namespace}'.</>");
            $this->line('  <fg=gray>Create one using:</> <fg=yellow>larakube snapshot:create {pvc}</>');
            $this->newLine();

            return 0;
        }

        $rows = array_map(fn ($s) => [
            $s['name'],
            $s['app'],
            $s['pvc'],
            $s['ready'],
            $s['size'],
            $s['created'],
        ], $snapshots);

        $this->newLine();
        table(
            headers: ['Snapshot Name', 'App / Tool', 'Target PVC', 'Status', 'Size', 'Created At'],
            rows: $rows,
        );
        $this->newLine();

        return 0;
    }
}
