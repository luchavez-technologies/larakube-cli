<?php

namespace App\Commands;

use App\Data\ConfigData;
use App\Traits\CheckPrerequisites;
use App\Traits\DetectsWsl;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithTraefik;
use App\Traits\InteractsWithTrust;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class DoctorCommand extends Command
{
    use CheckPrerequisites, DetectsWsl, HasConsoleInteraction, InteractsWithEnvironments, InteractsWithProjectConfig, InteractsWithTraefik, InteractsWithTrust, LaraKubeOutput;

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
        $config = $this->getProjectConfig();

        $this->laraKubeInfo("Diagnosing LaraKube environment: {$environment}...");

        $issues = $this->runDiagnostics($namespace, $environment, $config);

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
        }

        return 0;
    }

    protected function runDiagnostics(string $namespace, string $environment, ?ConfigData $config): array
    {
        $issues = [];

        // 1. Check for .larakube.json
        if (! $config) {
            $issues[] = [
                'title' => 'Missing Configuration',
                'description' => 'No .larakube.json file found in the current directory.',
                'fix' => 'Run larakube init to adopt this project.',
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

        // 4. Traefik health — cluster-wide (one ingress for every project), so
        // checked regardless of environment or whether a project config exists.
        $issues = array_merge($issues, $this->diagnoseTraefik());

        // 5/6. Trust chain + reachability/Windows-hosts sync only make sense for
        // the local dev cluster — cloud environments use real domains/certs, not
        // this machine's self-signed local CA or the WSL2/dnsmasq plumbing these
        // checks are diagnosing.
        if ($environment === 'local') {
            $issues = array_merge($issues, $this->diagnoseTrust());

            if ($config) {
                $issues = array_merge($issues, $this->diagnoseLocalReachability($config));
                $issues = array_merge($issues, $this->diagnoseTraefikRouting($config, $namespace));
            }
        }

        return $issues;
    }

    /**
     * Local HTTPS trust chain (CA, keychain, DNS, cert validity) — reuses the
     * exact same structured checks trust:check's detailed report is built
     * from (InteractsWithTrust::diagnoseTrustChain()), so cert/DNS problems
     * surface in doctor's unified issue list too, instead of requiring a
     * separate `larakube trust:check` run to notice them.
     */
    protected function diagnoseTrust(): array
    {
        $issues = [];

        foreach ($this->diagnoseTrustChain() as $item) {
            if ($item['ok']) {
                continue;
            }

            $issues[] = [
                'title' => $item['section'],
                'description' => '✗ '.trim($item['label']),
                'fix' => $item['fix'] ? "Run: {$item['fix']}" : null,
            ];
        }

        return $issues;
    }

    /**
     * Traefik is the single ingress point for every local host — a crash-
     * looping pod or a routing error here is exactly what "pods are Running
     * but nothing loads in the browser" looks like, and nothing before this
     * checked further than "does a Service exist" (isTraefikInstalled()).
     */
    protected function diagnoseTraefik(): array
    {
        $issues = [];

        if (! $this->isTraefikInstalled()) {
            $issues[] = [
                'title' => 'Traefik Not Installed',
                'description' => 'No ingress controller was found in the cluster.',
                'fix' => 'Run: larakube traefik:setup',
            ];

            return $issues;
        }

        $pods = Process::run('kubectl get pods -n traefik -o json')->output();
        if ($pods !== '') {
            $data = json_decode($pods, true);
            foreach ($data['items'] ?? [] as $pod) {
                $phase = $pod['status']['phase'];
                if ($phase !== 'Running' && $phase !== 'Succeeded') {
                    $issues[] = [
                        'title' => "Traefik Pod Failure: {$pod['metadata']['name']}",
                        'description' => "Pod is in state '{$phase}'.",
                        'fix' => 'Run: larakube traefik:logs',
                    ];
                }
            }
        }

        $errorCount = (int) trim(Process::run(
            'kubectl logs -n traefik -l app=traefik --tail=200 --since=10m 2>/dev/null | grep -ci level=error',
        )->output());

        if ($errorCount > 0) {
            $noun = $errorCount === 1 ? 'error' : 'errors';
            $issues[] = [
                'title' => 'Traefik Reporting Errors',
                'description' => "Found {$errorCount} recent {$noun} in the Traefik logs.",
                'fix' => 'Run: larakube traefik:logs to inspect',
            ];
        }

        return $issues;
    }

    /**
     * Cross-check EVERY project host (not just the primary app URL — see
     * diagnoseLocalReachability()) against Traefik's live router table. A
     * host can have a perfectly valid Ingress object (`kubectl get ingress`
     * looks fine) while Traefik itself never actually routes it — wrong
     * service port (the exact SeaweedFS s3-admin bug this check exists
     * because of), no matching IngressClass, or a reload that hasn't
     * happened yet. Skipped when Traefik's router API can't be queried at
     * all (already covered by diagnoseTraefik()'s own health check).
     */
    protected function diagnoseTraefikRouting(ConfigData $config, string $namespace): array
    {
        $routedHosts = $this->getTraefikRoutedHosts();
        if ($routedHosts === null) {
            return [];
        }

        $issues = [];
        foreach (array_keys($config->getAllHosts('local')) as $host) {
            if (in_array($host, $routedHosts, true)) {
                continue;
            }

            $issues[] = [
                'title' => "No Traefik Route: {$host}",
                'description' => "Traefik has no enabled router for {$host} — the Ingress may be missing, misconfigured, or pointing at the wrong service port.",
                'fix' => "Run: larakube up, then check: kubectl get ingress -n {$namespace}",
            ];
        }

        return $issues;
    }

    /**
     * Curl the app's own HTTPS host — catches "pods Running, Traefik logging
     * errors, but nothing shows in the browser" directly instead of leaving
     * it to a separate `larakube smoke` run. On WSL2 also verify the Windows
     * hosts file, since that's a second, invisible-from-WSL way to reproduce
     * the exact same symptom (see diagnoseWindowsHostsSync()).
     */
    protected function diagnoseLocalReachability(ConfigData $config): array
    {
        $issues = [];
        $url = $config->getAppUrl('local');

        $httpCode = trim(Process::run(
            "curl -k -s -o /dev/null -w \"%{http_code}\" {$url} --connect-timeout 3",
        )->output());

        if (! in_array($httpCode, ['200', '301', '302'], true)) {
            $issues[] = [
                'title' => 'App Not Reachable',
                'description' => "curl to {$url} did not return a successful response (got '".($httpCode ?: 'no response')."').",
                'fix' => 'Run: larakube smoke',
            ];
        }

        if ($this->isWsl()) {
            $issues = array_merge($issues, $this->diagnoseWindowsHostsSync($config));
        }

        return $issues;
    }

    /**
     * WSL2's node IP changes across reboots (NAT networking mode), but the
     * Windows hosts file is only resynced when `larakube up`/`larakube hosts`
     * runs — so it silently goes stale. The mismatch is invisible from inside
     * WSL (curl above can still succeed against the same still-live pod via
     * WSL's own network path) but leaves the Windows browser resolving to a
     * dead address — pods Running, Traefik healthy, nothing in Chrome.
     */
    protected function diagnoseWindowsHostsSync(ConfigData $config): array
    {
        $winHosts = '/mnt/c/Windows/System32/drivers/etc/hosts';
        if (! file_exists($winHosts)) {
            return [];
        }

        $expectedIp = $this->currentIngressIp();
        $content = (string) file_get_contents($winHosts);
        $stale = [];

        foreach (array_keys($config->getAllHosts('local')) as $host) {
            if (! preg_match('/^\s*(\S+)\s+.*\b'.preg_quote($host, '/').'\b/mi', $content, $matches) || $matches[1] !== $expectedIp) {
                $stale[] = $host;
            }
        }

        if ($stale === []) {
            return [];
        }

        return [[
            'title' => 'Windows Hosts File Out Of Sync',
            'description' => 'Your Windows browser resolves '.implode(', ', $stale)." to the wrong IP (expected {$expectedIp}) — WSL2's address changes across reboots.",
            'fix' => 'Run: larakube hosts',
        ]];
    }

    /**
     * The externally-reachable ingress IP: LoadBalancer IP when the cloud/
     * Docker Desktop assigns one, else the node's InternalIP — the only
     * address reliably routable from both WSL2 and the Windows browser for a
     * native k3s cluster. Mirrors InteractsWithHosts::resolveIngressIp().
     */
    protected function currentIngressIp(): string
    {
        $lbIp = trim(Process::run(
            "kubectl get svc traefik -n traefik -o jsonpath='{.status.loadBalancer.ingress[0].ip}'",
        )->output());

        if ($lbIp !== '') {
            return $lbIp;
        }

        return trim(Process::run(
            "kubectl get nodes -o jsonpath='{.items[0].status.addresses[?(@.type==\"InternalIP\")].address}'",
        )->output()) ?: '127.0.0.1';
    }
}
