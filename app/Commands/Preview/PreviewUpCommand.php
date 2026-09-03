<?php

namespace App\Commands\Preview;

use App\Data\ConfigData;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithHosts;
use App\Traits\InteractsWithKustomize;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithTraefik;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesLocalCa;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

/**
 * Run the PRODUCTION build on the local cluster, alongside the dev server.
 *
 * A frontend-only project is the one case where local and production share no
 * workload: locally the framework's dev server both compiles and serves, while
 * production is a prebuilt bundle behind Caddy. Everything in that serving
 * layer — the SPA fallback, the cache headers, compression, the security
 * headers — therefore executes ONLY in production, which is how two real bugs
 * shipped on 2026-09-03: an immutable-cache matcher that never matched Vite's
 * `index-D5pO33-4.js` filenames, and a `header /index.html` rule that matched
 * neither `/` nor a deep link, because Caddy evaluates matchers before
 * try_files rewrites. Neither was reproducible locally — locally there was no
 * Caddy at all.
 *
 * Its own host and resource names, so it runs NEXT TO the dev server rather
 * than evicting it: two tabs, one built and one hot-reloading, is the
 * comparison this exists to make possible.
 */
class PreviewUpCommand extends Command
{
    use GeneratesProjectInfrastructure, InteractsWithDocker, InteractsWithEnvironments, InteractsWithHosts, InteractsWithKustomize, InteractsWithProjectConfig, InteractsWithTraefik, LaraKubeOutput, ManagesLocalCa, StreamsProcessOutput;

    protected $signature = 'preview:up
                            {--no-build : Reuse the existing preview image instead of rebuilding}';

    protected $description = 'Frontend-only stacks (Vite/Astro/Docusaurus): serve the production build locally, beside the dev server';

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

        $host = $config->getServiceHost('preview', 'local');
        $namespace = $config->getNamespace('local');
        $path = '.infrastructure/k8s/overlays/local/preview';

        // Stand alone rather than requiring a prior `larakube up`: the overlay
        // is generated, not authored, so a project that has never been up (or
        // one scaffolded before preview existed) still works.
        if (! is_dir(getcwd().'/'.$path)) {
            $this->withSpin('Generating the preview overlay...', function () use ($config): void {
                $this->generateK8sManifests($config);
                $this->generateDockerfiles($config);
            });
        }

        // Its own hosts block: passing custom hosts REPLACES the named block,
        // so reusing the project's would drop the project's own entries.
        // Skipped entirely when dnsmasq already wildcards the TLD.
        $this->ensureHostsAreSet([$host], $config->getName().'-preview');

        // The app certificate already carries a `*.{app}.{tld}` SAN, which
        // covers this host — but a project that has never been `up` has no app
        // certificate at all, and this is idempotent when it does.
        $this->withSpin('Syncing local TLS certificates...', function () use ($config): void {
            $this->refreshTraefikCerts($config->getName(), $config->getLocalTld());
        });

        if (! $this->option('no-build') && ! $this->buildStaticPreviewImage($config)) {
            return 1;
        }

        $this->withSpin("Ensuring namespace '{$namespace}' exists...", function () use ($namespace): void {
            Process::run("kubectl create namespace {$namespace} --dry-run=client -o yaml | kubectl apply -f -");
        });

        // Whether a rollout has to be forced after the apply. The image tag is
        // always `{app}:preview`, so a REBUILD leaves the Deployment spec
        // byte-identical and nothing would roll on its own. A first create
        // needs no such nudge, and restarting one immediately costs a second
        // full rollout for nothing.
        $existed = trim(Process::run(
            "kubectl get deployment web-preview -n {$namespace} --ignore-not-found -o name",
        )->output()) !== '';

        $this->ensureKustomizeReady();
        $this->laraKubeInfo('Applying the preview workload...');
        $this->runStreaming($this->kustomizeApplyCommand($path));

        if ($existed) {
            $this->runStreaming("kubectl rollout restart deployment/web-preview -n {$namespace}");
        }

        $this->runStreaming("kubectl rollout status deployment/web-preview -n {$namespace} --timeout=120s");

        $this->renderReady($config, $host);

        return 0;
    }

    protected function renderReady(ConfigData $config, string $host): void
    {
        $this->newLine();
        $this->line('  <fg=yellow>🔍 Preview</> <fg=gray>— the production build, served by Caddy at</> <fg=cyan>https://'.$host.'</>');
        $this->line('  <fg=gray>Your dev server keeps running at</> <fg=cyan>https://'.$config->getWebHost('local').'</><fg=gray>, so you can compare them side by side.</>');
        $this->line('  <fg=gray>No HMR here — the bundle is baked into the image, so re-run</> <fg=yellow>larakube preview:up</> <fg=gray>after changes.</>');
        $this->line('  <fg=gray>Done with it?</> <fg=yellow>larakube preview:down</>');
        $this->renderStarPrompt();
    }
}
