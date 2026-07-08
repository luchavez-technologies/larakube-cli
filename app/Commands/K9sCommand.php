<?php

namespace App\Commands;

use App\Traits\DetectsWsl;
use App\Traits\InstallsK9s;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithOs;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class K9sCommand extends Command
{
    use DetectsWsl, InstallsK9s, InteractsWithClusterContext, InteractsWithEnvironments, InteractsWithOs, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'k9s {environment=local : The environment to monitor (defaults to local)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Launch K9s terminal UI pre-scoped to your project namespace';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! Process::run('which k9s')->successful() && ! is_file(home_path('.larakube/bin/k9s')) && ! $this->setupK9s()) {
            return 1;
        }

        if (! $this->isLaraKubeProject(showError: false)) {
            $context = $this->askForClusterContext();
            if (! $context) {
                $this->laraKubeError('No Kubernetes contexts found.');

                return 1;
            }

            $this->laraKubeInfo("Launching k9s on context: {$context}...");
            $this->executeK9s('', ' --context '.escapeshellarg($context));

            return 0;
        }

        // Defensive: signature default is 'local', but internal $this->call()
        // invocations may pass null explicitly (e.g. from ConsoleCommand).
        $environment = $this->argument('environment') ?: 'local';

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);
        $namespace = $this->getNamespace($environment, $config->getName());

        // Browse the ENV's OWN context (never switch the global one). Local uses
        // the current context; a cloud env targets its saved context — a managed
        // kube-context (DOKS/EKS/…) OR a VPS's larakube-<ip>. We don't prompt here
        // — k9s is read-only browsing — just hint if unset.
        $contextFlag = '';
        $context = $this->environmentContextOrCurrent($config, $environment);
        if ($context) {
            $contextFlag = ' --context '.escapeshellarg($context);
        } elseif ($environment !== 'local') {
            $this->laraKubeWarn("No deploy target saved for '{$environment}' — opening k9s on your current context. Run `larakube cloud:configure {$environment}` to record it.");
        }

        $this->laraKubeInfo("Launching K9s for project <fg=cyan;options=bold>{$config->getName()}</> in namespace: <fg=yellow;options=bold>{$namespace}</>...");

        $this->executeK9s($namespace, $contextFlag);

        return 0;
    }

    /**
     * Execute k9s
     */
    protected function executeK9s(string $namespace, string $contextFlag): void
    {
        $k9s = $this->resolveK9sBin() ?: 'k9s';
        $namespaceFlag = $namespace !== '' ? ' -n '.escapeshellarg($namespace) : '';
        passthru(escapeshellarg($k9s).$contextFlag.$namespaceFlag);
    }

    /**
     * Offer to install k9s when it's missing, then confirm it's usable.
     * Mirrors InteractsWithTrust::setupDnsmasq(). Returns true once k9s is
     * usable; false if the user declined or the install needs a new shell.
     */
    protected function setupK9s(): bool
    {
        $this->newLine();
        $this->line('  <fg=yellow>💡 Recommended:</> Install <fg=cyan>k9s</> for the best Kubernetes browsing experience.');
        $this->newLine();

        if (! confirm('Install k9s now?', true)) {
            $manual = 'https://k9scli.io/topics/install/';
            $this->line("  <fg=gray>Skipped — install it later: {$manual}</>");

            return false;
        }

        if (! $this->installK9s()) {
            return false;
        }

        // Managed install goes to ~/.larakube/bin/k9s — no shell restart needed.
        if ($this->resolveK9sBin() !== null) {
            $this->laraKubeInfo('k9s installed successfully.');

            return true;
        }

        // brew/snap may not be on the current shell's PATH hash yet.
        $this->laraKubeWarn('k9s installed — open a new terminal and re-run `larakube k9s`.');

        return false;
    }
}
