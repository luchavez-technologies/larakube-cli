<?php

namespace App\Commands;

use App\Traits\DetectsWsl;
use App\Traits\InstallsK9s;
use App\Traits\InteractsWithOs;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class SetupCommand extends Command
{
    use DetectsWsl, InstallsK9s, InteractsWithOs, LaraKubeOutput;

    protected $signature = 'setup';

    protected $description = 'First-time setup: install Docker Engine, k3s cluster, and optional tools';

    public function handle(): int
    {
        $this->renderHeader();
        $this->renderSetupHeader();

        if (! $this->isLinux()) {
            $this->renderError('larakube setup only runs on Linux and WSL2.');
            $this->laraKubeLine('On macOS, use OrbStack or Docker Desktop\'s built-in Kubernetes.');
            $this->laraKubeLine('On Windows, open your WSL2 terminal and run this command there.');

            return 1;
        }

        // Step 1 — Docker Engine
        $this->renderStep('Container Runtime', 'Docker Engine for building images');
        if (! $this->ensureDockerInstalled()) {
            return 1;
        }
        $this->renderDivider();

        // Step 2 — k3s cluster (delegates entirely to cluster:setup)
        $this->renderStep('Kubernetes Cluster', 'Lightweight k3s distribution');
        $result = $this->call('cluster:setup');

        if ($result !== 0) {
            return $result;
        }
        $this->renderDivider();

        // Step 3 — k9s (optional terminal UI for browsing the cluster)
        $this->renderStep('Optional Tools', 'k9s terminal UI for cluster browsing');
        $this->offerK9s();
        $this->renderDivider();

        // ── Done ──
        $this->renderCompletion();

        return 0;
    }

    // ─────────────────────────────────────────────
    //   Aesthetics
    // ─────────────────────────────────────────────

    protected function renderSetupHeader(): void
    {
        $this->line('');
        $this->line('  <fg=blue;options=bold>Environment Setup</>');
        $this->line('  <fg=gray>One-time configuration for your local development environment</>');
        $this->line('');
    }

    protected function renderStep(string $title, string $subtitle): void
    {
        $this->line('');
        $this->line("  <fg=white;options=bold>  > {$title}</>");
        $this->line("  <fg=gray>  {$subtitle}</>");
        $this->line('');
    }

    protected function renderDivider(): void
    {
        $this->line('  <fg=gray>'.str_repeat('─', 50).'</>');
    }

    protected function renderInfo(string $message): void
    {
        $this->line("  <fg=cyan;options=bold>[i]</>  <fg=white>{$message}</>");
    }

    protected function renderSuccess(string $message): void
    {
        $this->line("  <fg=green;options=bold>[+]</>  <fg=green>{$message}</>");
    }

    protected function renderWarn(string $message): void
    {
        $this->line("  <fg=yellow;options=bold>[!]</>  <fg=yellow>{$message}</>");
    }

    protected function renderError(string $message): void
    {
        $this->line("  <fg=red;options=bold>[x]</>  <fg=red>{$message}</>");
    }

    protected function renderCompletion(): void
    {
        $this->line('');
        $this->line('  <fg=gray>'.str_repeat('─', 53).'</>');
        $this->line('');
        $this->line('  <fg=green;options=bold>[+]  LaraKube environment is ready!</>');
        $this->line('');
        $this->line('  <fg=gray>   ┌─────────────────────────────────────────────────────┐</>');
        $this->line('  <fg=gray>   │  Quick Start                                        │</>');
        $this->line('  <fg=gray>   │                                                     │</>');
        $this->line('  <fg=gray>   │</>  <fg=cyan;options=bold>larakube up</>      Start your project                <fg=gray>│</>');
        $this->line('  <fg=gray>   │</>  <fg=cyan;options=bold>larakube new</>     Scaffold a new Laravel project    <fg=gray>│</>');
        $this->line('  <fg=gray>   │</>  <fg=cyan;options=bold>k9s</>             Browse your cluster                <fg=gray>│</>');
        $this->line('  <fg=gray>   │                                                     │</>');
        $this->line('  <fg=gray>   └─────────────────────────────────────────────────────┘</>');
        $this->line('');
    }

    // ─────────────────────────────────────────────
    //   Docker
    // ─────────────────────────────────────────────

    protected function ensureDockerInstalled(): bool
    {
        $hasDocker = trim((string) shell_exec('command -v docker 2>/dev/null')) !== '';

        if ($hasDocker) {
            exec('docker info 2>/dev/null', $_, $code);

            if ($code === 0) {
                $os = trim((string) shell_exec('docker info --format \'{{.OperatingSystem}}\' 2>/dev/null'));

                if (str_contains($os, 'Docker Desktop')) {
                    $this->renderWarn('Docker Desktop detected.');
                    $this->line('  LaraKube works with it, but Docker Engine installed directly in WSL2 is more reliable.');
                    $this->line('  See: <fg=cyan>https://cli.larakube.app/onboarding/operating-systems/windows</>');
                } else {
                    $this->renderSuccess('Docker Engine already installed and running.');
                }

                return true;
            }

            // No docker.service unit means the docker CLI comes from Docker Desktop's
            // WSL integration — there is no local daemon to start via systemctl.
            $hasDockerService = trim((string) shell_exec('systemctl cat docker 2>/dev/null')) !== '';

            if (! $hasDockerService) {
                $this->renderWarn('Docker Desktop is installed but not running.');
                $this->laraKubeLine('Docker Desktop\'s daemon cannot be started from WSL2.');
                $this->laraKubeNewLine();
                $this->laraKubeLine('You have two options:');
                $this->line('  <fg=yellow>A)</> Start Docker Desktop from Windows and re-run <fg=cyan>larakube setup</>.');
                $this->line('  <fg=yellow>B)</> Install Docker Engine natively in WSL2 (works even when Docker Desktop is off).');
                $this->laraKubeNewLine();

                if (! confirm('Install Docker Engine natively now?', default: true)) {
                    $this->laraKubeLine('Start Docker Desktop from Windows, then re-run larakube setup.');

                    return false;
                }

                return $this->installDockerEngine();
            }

            $this->renderInfo('Docker Engine found — starting the service...');
            passthru('sudo systemctl start docker 2>/dev/null', $startCode);

            if ($startCode !== 0) {
                $this->renderError('Could not start Docker. Run: sudo systemctl start docker');

                return false;
            }

            $this->renderSuccess('Docker Engine running.');

            return true;
        }

        return $this->installDockerEngine();
    }

    protected function installDockerEngine(): bool
    {
        // If the docker-ce package is already installed (e.g. a prior run that left
        // the service stopped), skip the installer and just enable the service.
        $alreadyInstalled = trim((string) shell_exec('dpkg -l docker-ce 2>/dev/null | grep -c "^ii"')) === '1';

        if ($alreadyInstalled) {
            $this->renderInfo('Docker Engine package found — enabling service...');
            shell_exec('sudo systemctl enable --now docker 2>/dev/null');
            $this->renderSuccess('Docker Engine running.');

            return true;
        }

        $this->renderInfo('Installing Docker Engine...');
        // The get.docker.com script warns about an existing docker CLI (Docker Desktop)
        // and about running inside WSL — both warnings are expected here and safe to
        // ignore. Each warning pauses for 20s before continuing automatically.
        $this->line('  <fg=gray>The installer may warn about Docker Desktop or WSL — this is expected. It will continue automatically.</>');
        $this->newLine();
        passthru('curl -fsSL https://get.docker.com | sh', $installCode);

        if ($installCode !== 0) {
            $this->renderError('Docker Engine installation failed. See output above.');

            return false;
        }

        $user = getenv('USER') ?: get_current_user();
        if ($user) {
            shell_exec('sudo usermod -aG docker '.escapeshellarg((string) $user).' 2>/dev/null');
        }

        shell_exec('sudo systemctl enable --now docker 2>/dev/null');

        $this->renderSuccess('Docker Engine installed.');
        $this->line('  You\'ve been added to the <fg=cyan;options=bold>docker</> group, but your current shell session');
        $this->line('  won\'t pick it up until you run: <fg=cyan;options=bold>newgrp docker</> (or open a new terminal).');

        return true;
    }

    protected function offerK9s(): void
    {
        if ($this->resolveK9sBin() !== null) {
            $this->renderSuccess('k9s already installed.');

            return;
        }

        $this->renderInfo('k9s is a terminal UI for browsing your Kubernetes cluster.');

        if (! confirm('Install k9s now?', default: true)) {
            $this->line('  Skipped — install it later with: <fg=cyan;options=bold>larakube k9s</>');

            return;
        }

        $this->installK9s();
    }
}
