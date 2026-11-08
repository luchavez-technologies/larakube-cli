<?php

namespace App\Commands;

use App\Traits\EmitsJsonOutput;
use App\Traits\FailsWithJson;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\ResolvesStaticScripts;
use App\Traits\StreamsProcessOutput;
use LaravelZero\Framework\Commands\Command;

/**
 * Builds a static-only project's production bundle on the host — no Docker,
 * no cluster — and points at where to deploy it for free. A static
 * Astro/Vite/Docusaurus site with no backend doesn't need a LaraKube
 * environment of its own: Cloudflare Pages/Netlify/Vercel/GitHub Pages
 * already have near-zero-friction drag-and-drop or git-push deploys. See
 * cli/plans/active/static-site-deploy.md for why a fuller OAuth-connected
 * integration was deliberately not built instead.
 */
class StaticBuildCommand extends Command
{
    use EmitsJsonOutput, FailsWithJson, InteractsWithProjectConfig, LaraKubeOutput, ReadsCommandOptions, ResolvesStaticScripts, StreamsProcessOutput;

    /** @var array<string, array{label: string, url: string, note: ?string}> */
    private const array FREE_HOSTS = [
        'cloudflare-pages' => ['label' => 'Cloudflare Pages', 'url' => 'https://pages.cloudflare.com', 'note' => null],
        'netlify' => ['label' => 'Netlify', 'url' => 'https://app.netlify.com/drop', 'note' => 'Drag-and-drop deploy, no account needed to try it'],
        'vercel' => ['label' => 'Vercel', 'url' => 'https://vercel.com/new', 'note' => null],
        'github-pages' => ['label' => 'GitHub Pages', 'url' => 'https://pages.github.com', 'note' => "Git-based, not drag-and-drop — push this folder to a gh-pages branch or your repo's Pages source"],
    ];

    protected $signature = 'static:build
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Build a static-only project locally and show where to deploy it for free';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        } else {
            $this->renderHeader();
        }

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $config = $this->getProjectConfig(getcwd());

        if (! $config) {
            return 1;
        }

        if (! ($config->framework?->isStaticSpa() ?? false)) {
            $label = $config->framework?->getLabel() ?? 'This project';

            return $this->failed("{$label} isn't a static-only site (Astro, Vite, Docusaurus) — static:build has nothing to hand off to a free host.");
        }

        $outputDir = $config->framework->staticOutputDir() ?? 'dist';
        $outputPath = $config->getPath().'/'.$outputDir;

        $built = $this->withSpin('Installing dependencies and building...', function () use ($config): bool {
            $path = escapeshellarg($config->getPath());
            $install = $config->getPackageManager()->installCommand();
            $build = $this->resolveBuildCommand($config);

            return $this->runStreaming("cd {$path} && {$install} && {$build}") === 0;
        });

        if (! $built) {
            return $this->failed('The build failed — see the output above.');
        }

        if (! is_dir($outputPath)) {
            return $this->failed("Build succeeded, but the expected output folder wasn't there: {$outputPath}");
        }

        $localHostWarning = $this->findLocalHostReferences($outputPath);

        if ($this->flag('json')) {
            $this->jsonOutput([
                'success' => true,
                'outputPath' => $outputPath,
                'localHostWarning' => $localHostWarning,
                'hosts' => self::FREE_HOSTS,
            ]);
        } else {
            $this->renderReady($outputPath, $localHostWarning);
        }

        return 0;
    }

    /**
     * Astro/Vite bake env values into the bundle (see the framework enum's own bakesEnvIntoBuild()) —
     * a build that still points at a .test/.kube/.local/.localhost/.internal
     * dev host would silently ship broken links. Non-fatal here (unlike the
     * Docker build's STRICT_HOSTS guard): this is a convenience tool, not the
     * deploy pipeline, so it warns rather than refusing the build outright.
     */
    protected function findLocalHostReferences(string $outputPath): ?string
    {
        $result = $this->runStreamingCaptured(
            "grep -rEl 'https?://[a-z0-9.-]+[.](kube|test|localhost|local|internal)([^a-z0-9-]|$)' ".escapeshellarg($outputPath).' 2>/dev/null',
        );

        $files = array_values(array_filter(array_map('trim', explode("\n", $result[1]))));

        return $files === [] ? null : 'Some built files still reference local dev hosts ('.implode(', ', array_slice($files, 0, 3)).(count($files) > 3 ? ', …' : '').') — check your .env before deploying.';
    }

    protected function renderReady(string $outputPath, ?string $localHostWarning): void
    {
        $this->laraKubeInfo("✅ Built to: {$outputPath}");

        if ($localHostWarning !== null) {
            $this->laraKubeWarn($localHostWarning);
        }

        $this->newLine();
        $this->line('  <fg=gray>Deploy that folder for free:</>');
        foreach (self::FREE_HOSTS as $host) {
            $this->line("  <fg=cyan>{$host['label']}</> <fg=blue>{$host['url']}</>".($host['note'] ? " <fg=gray>— {$host['note']}</>" : ''));
        }
        $this->newLine();
    }
}
