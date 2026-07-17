<?php

namespace App\Commands\Dns;

use App\Data\ConfigData;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\PromotesIngressDns;
use App\Traits\ResolvesEnvironmentContext;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class DnsInitCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithProjectConfig, LaraKubeOutput, PromotesIngressDns, ResolvesEnvironmentContext, StreamsProcessOutput;

    protected $signature = 'dns:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--env=      : Legacy alias for the environment}
        {--remove    : Tear down the DNS stack}';

    protected $description = 'Deploy ExternalDNS to automate Cloudflare DNS records for your cluster';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->option('remove')
            ? $this->removeDns()
            : $this->deployDns();
    }

    protected function deployDns(): int
    {
        $env = $this->resolveEnvironment();

        if ($env === 'local') {
            $this->laraKubeError('ExternalDNS is only supported on cloud environments.');

            return 1;
        }

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = (string) $this->option('context') ?: null;
        if (! $context && $config && $env !== 'local') {
            [$config, $context] = $this->resolveEnvironmentContext($config, $env, $projectPath);
        }

        $kubectl = $context ? "kubectl --context={$context}" : 'kubectl';
        $ns = 'larakube-shared';

        $token = $this->getCloudflareToken();
        if (! $token) {
            $this->newLine();
            $this->info('To automate DNS, you need a Cloudflare API Token.');
            $this->line('  1. Go to <fg=blue>https://dash.cloudflare.com/profile/api-tokens</>');
            $this->line('  2. Click "Create Token" -> "Create Custom Token"');
            $this->line('  3. Under Permissions, choose: <fg=yellow>Zone</> - <fg=yellow>DNS</> - <fg=yellow>Edit</>');
            $this->line('  4. Under Zone Resources, choose: <fg=yellow>Include</> - <fg=yellow>Specific Zone</> - <fg=yellow>(Your Domain)</>');
            $this->line('     (Or "All zones" if you want to use it for multiple domains)');
            $this->newLine();

            $token = $this->ask('Cloudflare API Token');
            if (! $token) {
                $this->laraKubeError('A Cloudflare API token is required to deploy ExternalDNS.');

                return 1;
            }
            $this->setCloudflareToken($token);
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing Cloudflare token...', function () use ($kubectl, $ns, $token) {
            Process::run(
                "{$kubectl} create secret generic cloudflare-api-token -n {$ns} "
                .'--from-literal=token='.escapeshellarg($token).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
        });

        $this->line('  Applying ExternalDNS resources...');
        $this->newLine();

        $manifest = view('k8s.dns.shared')->render();

        $exit = $this->runStreaming('echo '.escapeshellarg($manifest)." | {$kubectl} apply -f -", timeoutSeconds: 300);

        if ($exit === 0) {
            $this->newLine();
            $this->line('  <fg=green;options=bold>✔</> ExternalDNS deployed successfully!');
            $this->line('  <fg=gray>It will now automatically manage Cloudflare A/CNAME records for your Ingress hosts.</>');
            $this->newLine();
        }

        return $exit;
    }

    protected function removeDns(): int
    {
        $env = $this->resolveEnvironment();

        if ($env === 'local') {
            $this->laraKubeError('ExternalDNS is only supported on cloud environments.');

            return 1;
        }

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = (string) $this->option('context') ?: null;
        if (! $context && $config && $env !== 'local') {
            $context = $this->environmentContextOrCurrent($config, $env);
        }

        $kubectl = $context ? "kubectl --context={$context}" : 'kubectl';

        $this->line('  Removing ExternalDNS resources...');
        $this->newLine();

        $manifest = view('k8s.dns.shared')->render();
        $exit = $this->runStreaming('echo '.escapeshellarg($manifest)." | {$kubectl} delete --ignore-not-found -f -", timeoutSeconds: 300);

        if ($exit === 0) {
            $this->newLine();
            $this->line('  <fg=green;options=bold>✔</> ExternalDNS removed.');
        }

        return $exit;
    }

    protected function resolveEnvironment(): string
    {
        $explicit = (string) ($this->argument('environment') ?: $this->option('env') ?: '');
        if ($explicit !== '') {
            return $explicit;
        }

        if ($this->option('no-interaction')) {
            return 'local';
        }

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $envs = $config ? array_merge(['local'], $config->getCloudEnvironments()) : ['local'];

        return select(
            label: 'Which environment is this DNS install for?',
            options: array_combine($envs, $envs),
            default: 'local',
        );
    }
}
