<?php

namespace App\Commands;

use App\Data\GlobalConfigData;
use App\Enums\AppFramework;
use App\Traits\ClonesRepositories;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class CloneCommand extends Command
{
    use ClonesRepositories, InteractsWithProjectConfig, LaraKubeOutput;

    protected $signature = 'clone
        {repo : Repository URL, SSH URL, or user/repo shorthand}
        {directory? : Target directory (defaults to repo name)}
        {--branch= : Branch to clone}
        {--provider=github : Git host for user/repo shorthand (github, gitlab, bitbucket)}
        {--no-install : Skip package installation (composer/npm/pip/go)}';

    protected $description = 'Clone a web repository (Laravel, Next.js, Django, Go, Rust, etc.) and prepare it for LaraKube CLI in one command';

    public function handle(): int
    {
        $this->renderHeader();

        $repo = (string) $this->argument('repo');
        $provider = (string) ($this->option('provider') ?: 'github');

        if (! in_array($provider, ['github', 'gitlab', 'bitbucket'], true)) {
            $this->laraKubeError("Unknown provider '{$provider}'. Use: github, gitlab, or bitbucket.");

            return 1;
        }

        $url = $this->resolveRepoUrl($repo, $provider);
        $directory = (string) ($this->argument('directory') ?: $this->deriveDirectoryName($url));
        $branch = $this->option('branch');
        $targetPath = getcwd().'/'.$directory;

        // Guard: directory already exists
        if (is_dir($targetPath)) {
            $this->laraKubeError("Directory '{$directory}' already exists.");
            $this->line('  <fg=gray>cd into it and run:</> <fg=yellow>larakube init</>');

            return 1;
        }

        // ── Step 1: Clone ──────────────────────────────────────────────────────

        $this->laraKubeInfo("Cloning {$url}…");
        $this->newLine();

        $cloneCode = $this->runGitClone($url, $targetPath, $branch);

        if ($cloneCode !== 0) {
            $this->laraKubeError('git clone failed. Check the URL and your network/credentials.');

            return 1;
        }

        $this->newLine();
        $this->laraKubeInfo("Cloned into {$directory}/");

        // ── Step 2: Detect platform framework ───────────────────────────

        $framework = AppFramework::detect($targetPath);
        if ($framework !== null) {
            $this->laraKubeInfo("Detected platform: {$framework->getLabel()}");
        } else {
            $this->laraKubeWarn("Unrecognized project framework layout in {$directory}/");

            if (! confirm('Continue initializing LaraKube anyway?', true)) {
                return 0;
            }
        }

        // ── Step 3: .env bootstrap ────────────────────────────────────────────

        try {
            $envResult = $this->bootstrapDotEnv($targetPath);
        } catch (RuntimeException $e) {
            $this->newLine();
            $this->laraKubeError($e->getMessage());
            $this->line('  <fg=gray>Create a .env.example in the repo and try again, or add a .env manually.</>');

            return 1;
        }

        $this->newLine();

        if ($envResult === 'copied') {
            $this->laraKubeInfo('.env created from .env.example with a fresh secret key.');

            // Patch APP_URL and ASSET_URL so they point to the local LaraKube domain
            $tld = GlobalConfigData::load()->getLocalTld();
            $appUrl = "https://{$directory}.{$tld}";
            $this->patchDotEnv($targetPath, [
                'APP_URL' => $appUrl,
                'ASSET_URL' => $appUrl,
            ]);
            $this->line("  <fg=gray>APP_URL / ASSET_URL set to:</> <fg=cyan>{$appUrl}</>");
        } else {
            $this->line('  <fg=gray>.env already exists — left untouched.</>');
        }

        // ── Step 4: Package Installation ─────────────────────────────────────

        if (! $this->option('no-install')) {
            $hasComposer = file_exists($targetPath.'/composer.json');
            $hasPackageJson = file_exists($targetPath.'/package.json');

            if ($hasComposer) {
                $this->newLine();
                $this->laraKubeInfo('Running composer install…');
                $this->newLine();

                $installCode = $this->runComposerInstall($targetPath);
                if ($installCode !== 0) {
                    $this->laraKubeWarn('composer install exited with warnings.');
                }
            } elseif ($hasPackageJson && in_array($framework, [AppFramework::NEXTJS, AppFramework::NESTJS, AppFramework::ADONISJS], true)) {
                $this->newLine();
                $this->laraKubeInfo('Running npm install…');
                $this->newLine();

                // Run npm install inside Node container or host
                passthru("cd $targetPath && npm install");
            }
        }

        // ── Step 5: Init ──────────────────────────────────────────────────────

        $hasLaraKubeJson = file_exists($targetPath.'/.larakube.json');

        if ($hasLaraKubeJson) {
            $this->newLine();
            $this->laraKubeInfo('Existing LaraKube CLI config found (.larakube.json) — skipping init wizard.');

            $projectConfig = $this->getProjectConfig($targetPath);
            if ($projectConfig) {
                $plexServices = $projectConfig->getPlex('local');

                if (! empty($plexServices)) {
                    $this->newLine();
                    $this->laraKubeWarn('This project uses shared Plex commons for: '.implode(', ', $plexServices).'.');
                    $this->line('  <fg=gray>Run <fg=yellow>plex:join local</> to reconnect your tenant credentials (requires your local cluster to be running).</>');
                    $this->newLine();

                    if (confirm('Join the local Plex commons now?', false)) {
                        chdir($targetPath);
                        $this->call('plex:join', ['environment' => 'local']);
                    }
                }
            }
        } else {
            $this->newLine();
            $this->laraKubeInfo('Running larakube init to configure this project…');
            $this->newLine();

            chdir($targetPath);
            $initCode = $this->call('init');

            if ($initCode !== 0) {
                $this->laraKubeWarn('Init did not complete successfully. Re-run: larakube init');
            }
        }

        // ── Done ──────────────────────────────────────────────────────────────

        $this->newLine();
        $this->laraKubeInfo("Ready! cd {$directory} && larakube up");

        return 0;
    }
}
