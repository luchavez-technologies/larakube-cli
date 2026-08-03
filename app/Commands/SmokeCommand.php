<?php

namespace App\Commands;

use App\Traits\InteractsWithEnvironments;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class SmokeCommand extends Command
{
    use InteractsWithEnvironments, LaraKubeOutput;

    protected $signature = 'smoke {environment=local : The environment to smoke-test}';

    protected $description = 'Smoke-test the deployed app over HTTP (curl the ingress, fall back to cluster bridge)';

    public function handle(): int
    {
        $this->renderHeader();

        $projectPath = getcwd();
        $ingressPath = $projectPath.'/.infrastructure/k8s/base/ingress.yaml';

        if (! file_exists($ingressPath)) {
            $this->laraKubeError('Ingress configuration not found.');

            return 1;
        }

        // Get the host from ingress
        $content = file_get_contents($ingressPath);
        if (preg_match('/host: (.*)/', $content, $matches)) {
            $host = trim($matches[1]);
            $protocols = ['https', 'http'];
            $maxAttempts = 5;
            $success = false;

            foreach ($protocols as $protocol) {
                $url = "{$protocol}://{$host}";
                $this->laraKubeInfo("Testing connectivity to {$url}...");

                // 🛡 PHASE 1: Standard Domain Check
                for ($i = 1; $i <= $maxAttempts; $i++) {
                    $httpCode = trim(Process::run("curl -k -s -o /dev/null -w \"%{http_code}\" {$url} --connect-timeout 2")->output());

                    if ($httpCode === '200' || $httpCode === '302' || $httpCode === '301') {
                        $this->laraKubeInfo("SUCCESS! Application reachable via {$protocol} (HTTP {$httpCode}).");
                        $success = true;
                        break 2;
                    }

                    if ($i < $maxAttempts) {
                        sleep(1);
                    }
                }

                // 🛡 PHASE 2: Cluster IP Bypass (Ensures app is healthy even if hosts are out of sync)
                $this->line('  <fg=gray>[INFO]</> Retrying via internal cluster bridge...');
                $externalIp = Process::run("kubectl get svc traefik -n traefik -o jsonpath='{.status.loadBalancer.ingress[0].ip}'")->output();
                $externalIp = $externalIp !== '' ? $externalIp : '127.0.0.1';

                // For a local cluster, localhost (127.0.0.1) is usually the correct bridge for the daemon
                $bypassUrl = "{$protocol}://127.0.0.1";
                $httpCode = trim(Process::run("curl -k -s -o /dev/null -w \"%{http_code}\" -H \"Host: {$host}\" {$bypassUrl} --connect-timeout 2")->output());

                if ($httpCode === '200' || $httpCode === '302' || $httpCode === '301') {
                    $this->laraKubeInfo("SUCCESS! Application verified via Cluster Bridge (HTTP {$httpCode}).");
                    $this->line("  <fg=yellow>⚠ NOTE: Your app is healthy, but you might need to run 'larakube hosts' to sync your domains.</>");
                    $success = true;
                    break;
                }
            }

            if (! $success) {
                $this->laraKubeError('FAILED. Could not reach your application after several attempts.');
                $this->line("Check your /etc/hosts and ensure your pods are running with 'larakube console'.");

                return 1;
            }
        }

        return 0;
    }
}
