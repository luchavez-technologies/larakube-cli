<?php

namespace App\Commands;

use App\Enums\DatabaseDriver;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\multiselect;

use LaravelZero\Framework\Commands\Command;

class TunnelCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'tunnel
                            {services?* : The service(s) to tunnel to (mysql, postgres, redis)}
                            {--environment=local : The environment to use}';

    /**
     * The console command description.
     */
    protected $description = 'Create secure tunnels to database services';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $environment = $this->option('environment');
        $namespace = $this->getNamespace($environment);

        // Detect available services using the DatabaseEngine enum
        $availableServices = $this->getAvailableServices($namespace);

        if (empty($availableServices)) {
            $this->laraKubeError("No tunnelable services found in namespace '{$namespace}'.");

            return 1;
        }

        $selectedKeys = $this->argument('services');
        if (empty($selectedKeys)) {
            $options = collect($availableServices)->mapWithKeys(fn ($s, $key) => [$key => $s['label']])->all();

            $selectedKeys = multiselect(
                'Which services would you like to tunnel to?',
                $options,
                required: true,
            );
        }

        $activeTunnels = [];
        $usedPorts = [];

        foreach ($selectedKeys as $key) {
            if (! isset($availableServices[$key])) {
                $this->laraKubeError("Service '{$key}' not found or not tunnelable. Skipping...");

                continue;
            }

            $service = $availableServices[$key];
            $targetPort = $service['port'];
            $localPort = $this->findAvailablePort($targetPort, $usedPorts);
            $usedPorts[] = $localPort;

            $activeTunnels[] = [
                'label' => $service['label'],
                'svc' => $service['svc'],
                'localPort' => $localPort,
                'targetPort' => $targetPort,
                'user' => $service['user'] ?? null,
            ];
        }

        if (empty($activeTunnels)) {
            $this->laraKubeError('No valid services selected for tunneling.');

            return 1;
        }

        $this->laraKubeInfo('Activating tunnels...');
        $kubectlCmds = [];

        foreach ($activeTunnels as $tunnel) {
            $this->line("  <fg=blue;options=bold>● {$tunnel['label']}</>");
            $this->line("    <fg=gray>Local:</> 127.0.0.1:{$tunnel['localPort']} ↔ <fg=gray>Cluster:</> {$tunnel['svc']}:{$tunnel['targetPort']}");
            if ($tunnel['user'] && $tunnel['user'] !== 'root') {
                $this->line("    <fg=gray>User:</> {$tunnel['user']} | <fg=gray>Pass:</> secretpassword");
            }
            $this->line('');

            $kubectlCmds[] = "kubectl port-forward svc/{$tunnel['svc']} {$tunnel['localPort']}:{$tunnel['targetPort']} -n {$namespace}";
        }

        $this->laraKubeInfo('Tunnels active. Press Ctrl+C to stop all.');

        // Run all port-forwards in parallel and wait
        $fullCmd = implode(' & ', $kubectlCmds).' & wait';
        passthru($fullCmd);

        return 0;
    }

    protected function getAvailableServices(string $namespace): array
    {
        $services = [];
        $output = Process::run("kubectl get svc -n {$namespace} -o json")->output();
        if ($output === '') {
            return [];
        }

        $data = json_decode($output, true);
        $runningServices = collect($data['items'] ?? [])->map(fn ($item) => $item['metadata']['name'])->toArray();

        foreach (DatabaseDriver::cases() as $engine) {
            if (in_array($engine->dbHost(), $runningServices)) {
                $services[strtolower($engine->name)] = [
                    'label' => $engine->value,
                    'svc' => $engine->dbHost(),
                    'port' => $engine->dbPort(),
                    'user' => $engine->dbUsername(),
                ];
            }
        }

        return $services;
    }

    protected function findAvailablePort(int $startPort, array $excludePorts = []): int
    {
        $port = $startPort;
        while (in_array($port, $excludePorts) || ! $this->isPortAvailable($port)) {
            $port++;
        }

        return $port;
    }

    protected function isPortAvailable(int $port): bool
    {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if (is_resource($connection)) {
            fclose($connection);

            return false;
        }

        return true;
    }
}
