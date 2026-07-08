<?php

namespace App\Commands;

use App\Data\ConfigData;
use App\Traits\CheckPrerequisites;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\info;

use LaravelZero\Framework\Commands\Command;

class DoctorCommand extends Command
{
    use CheckPrerequisites, HasConsoleInteraction, InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'doctor {--environment=local : The environment to diagnose}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan your LaraKube project and cluster for issues';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $environment = $this->option('environment');
        $namespace = $this->getNamespace($environment);

        $this->laraKubeInfo("Diagnosing LaraKube environment: {$environment}...");

        $issues = $this->runDiagnostics($namespace);
        $config = $this->getProjectConfig();

        if (empty($issues)) {
            $this->laraKubeInfo('✅ No critical issues detected! Your cluster health is looking like a masterpiece.');

            if ($config && $config->getId()) {
                $this->logToConsole($config->getId(), 'doctor', 'Doctor scan completed: Healthy', ['environment' => $environment]);
            }
        } else {
            $this->laraKubeError('Issues detected:');
            foreach ($issues as $issue) {
                $this->line("  ● <fg=red>{$issue['title']}</>: {$issue['description']}");
                if (isset($issue['fix'])) {
                    $this->line("    <fg=gray>👉 Fix: {$issue['fix']}</>");
                }
            }

            if ($config && $config->getId()) {
                $this->logToConsole($config->getId(), 'doctor', 'Doctor scan completed: Issues found', [
                    'environment' => $environment,
                    'issue_count' => count($issues),
                    'issues' => $issues,
                ]);
            }

            $this->line('');
            info('Pro Tip: Open the LaraKube Console for an automated recovery plan.');
        }

        return 0;
    }

    protected function runDiagnostics(string $namespace): array
    {
        $issues = [];

        // 1. Check for .larakube.json
        if (! file_exists(getcwd().'/'.ConfigData::CONFIG_FILE)) {
            $issues[] = [
                'title' => 'Missing Configuration',
                'description' => 'No .larakube.json file found in the current directory.',
                'fix' => 'Run larakube init to adopting this project.',
            ];
        }

        // 2. Check Cluster Connectivity
        $result = Process::run('kubectl cluster-info');
        $check = $result->output().$result->errorOutput();
        if (str_contains($check, 'refused') || str_contains($check, 'error')) {
            $issues[] = [
                'title' => 'Cluster Unreachable',
                'description' => 'Cannot connect to the Kubernetes cluster.',
                'fix' => 'Ensure your cluster (Docker Desktop, OrbStack, or k3s) is running.',
            ];

            return $issues; // Stop here if cluster is down
        }

        // 3. Check for failed pods
        $pods = Process::run("kubectl get pods -n {$namespace} -o json")->output();
        if ($pods !== '') {
            $data = json_decode($pods, true);
            foreach ($data['items'] ?? [] as $pod) {
                $phase = $pod['status']['phase'];
                if ($phase !== 'Running' && $phase !== 'Succeeded') {
                    $issues[] = [
                        'title' => "Pod Failure: {$pod['metadata']['name']}",
                        'description' => "Pod is in state '{$phase}'.",
                        'fix' => 'Check logs with: larakube logs '.str_replace('laravel-', '', $pod['metadata']['name']),
                    ];
                }
            }
        }

        return $issues;
    }
}
