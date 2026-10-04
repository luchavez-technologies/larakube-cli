<?php

namespace App\Commands\Share;

use App\Services\Kubectl;
use App\Traits\AppliesShareEnvironment;
use App\Traits\ResolvesEnvironmentContext;
use App\Traits\SharesUnderDomain;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class ShareShowCommand extends Command
{
    use AppliesShareEnvironment, ResolvesEnvironmentContext, SharesUnderDomain;

    protected $signature = 'share:show
        {environment=local : The environment to show (public names exist only for local)}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Show the public names this project has from share:domain, and whether they are up';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $project = $this->domainProject();

        if ($project === null) {
            return 1;
        }

        [$config, $appName, $namespace] = $project;

        $environment = (string) $this->argument('environment');

        if ($environment !== 'local') {
            return $this->failed("Public names exist only for the local environment, not '{$environment}'.");
        }

        $kubectl = Kubectl::forContext($this->environmentContextOrCurrent($config, $environment));

        $saved = $this->getGlobalConfig()->getShareDomain($appName);
        $urls = is_array($saved['urls'] ?? null) ? $saved['urls'] : [];
        $running = false;

        if ($urls !== []) {
            $result = Process::run($kubectl->prefix().' get deployment larakube-share -n '.escapeshellarg($namespace).' -o name');
            $running = $result->successful() && trim($result->output()) !== '';
        }

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'mode' => $urls === [] ? 'none' : 'domain', 'zone' => $saved['zone'] ?? null, 'urls' => $urls, 'running' => $running]);

            return 0;
        }

        if ($urls === []) {
            $this->laraKubeInfo('This project has no public names. Make them with larakube share:domain.');

            return 0;
        }

        $this->printShareUrls($urls, 'named', waiting: false);
        $this->line($running ? '  <fg=green>The tunnel is running.</>' : '  <fg=yellow>The tunnel is not running. larakube share:domain brings it back.</>');

        return 0;
    }
}
