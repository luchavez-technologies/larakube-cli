<?php

namespace App\Commands\Preview;

use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithHosts;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

/**
 * Remove the preview workload, leaving the dev server alone.
 *
 * `larakube down` also reclaims it, but only by deleting the whole namespace —
 * which takes the dev server, the ConfigMaps and Secrets, and the node_modules
 * PVC with it. This is the scoped counterpart to `preview:up`.
 */
class PreviewDownCommand extends Command
{
    use InteractsWithDocker, InteractsWithEnvironments, InteractsWithHosts, InteractsWithProjectConfig, LaraKubeOutput, StreamsProcessOutput;

    protected $signature = 'preview:down
                            {--image : Also delete the local preview Docker image}';

    protected $description = 'Frontend-only stacks (Vite/Astro/Docusaurus): remove the local production-build preview';

    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $config = $this->getProjectConfig(getcwd());

        if (! $config) {
            return 1;
        }

        if ($refusal = $this->previewModeRefusal($config->framework)) {
            [$error, $details] = $refusal;
            $this->laraKubeError($error);
            foreach ($details as $detail) {
                $this->line("  <fg=gray>{$detail}</>");
            }

            return 1;
        }

        $namespace = $config->getNamespace('local');

        // --ignore-not-found so a second run, or a preview that was never
        // brought up, is a clean no-op instead of three kubectl errors.
        $this->laraKubeInfo('Removing the preview workload...');
        $this->runStreaming(
            "kubectl delete deployment,service,ingress web-preview -n {$namespace} --ignore-not-found",
        );

        // The /etc/hosts block preview:up wrote (absent on machines where
        // dnsmasq wildcards the TLD, in which case this is a no-op).
        $this->removeHostsBlock($config->getName().'-preview');

        if ($this->option('image')) {
            $image = $config->getName().':preview';
            $this->laraKubeInfo("Removing local image '{$image}'...");
            Process::run('docker rmi '.escapeshellarg($image));
        }

        $this->newLine();
        $this->line('  <fg=green>✓</> <fg=gray>Preview removed. Your dev server at</> <fg=cyan>https://'.$config->getWebHost('local').'</> <fg=gray>is untouched.</>');

        if (! $this->option('image')) {
            // Named rather than silently left behind: it is the largest thing
            // this command does NOT clean up, and rebuilding it is cheap.
            $this->line('  <fg=gray>The</> <fg=yellow>'.$config->getName().':preview</> <fg=gray>image is still on this machine — drop it with</> <fg=yellow>preview:down --image</><fg=gray>.</>');
        }

        return 0;
    }
}
