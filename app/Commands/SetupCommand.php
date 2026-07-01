<?php

namespace App\Commands;

use App\Traits\DetectsWsl;
use App\Traits\InstallsK9s;
use App\Traits\InteractsWithOs;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\spin;

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

        // ── Step 1: System Update (only on Linux/WSL, matching install.sh) ──
        $this->renderStep('Updating System Packages', 'Ensuring latest versions via apt');
        if (! $this->runSystemUpdate()) {
            return 1;
        }
        $this->renderDivider();

        // ── Step 2: System Prerequisites ──
        $this->renderStep('Checking Prerequisites', 'curl, sudo, platform');
        if (! $this->checkPrerequisites()) {
            return 1;
        }
        $this->renderDivider();

        // ── Step 3: Docker Engine ──
        $this->renderStep('Container Runtime', 'Docker Engine for building images');
        if (! $this->ensureDockerInstalled()) {
            return 1;
        }
        $this->renderDivider();

        // ── Step 3: k3s Cluster ──
        $this->renderStep('Kubernetes Cluster', 'Lightweight k3s distribution');
        $result = $this->call('cluster:setup');

        if ($result !== 0) {
            return $result;
        }
        $this->renderDivider();

        // ── Step 4: k9s (Optional) ──
        $this->renderStep('Optional Tools', 'k9s terminal UI for cluster browsing');
        $this->offerK9s();
        $this->renderDivider();

        // ── Done ──
        $this->renderCompletion();

        return 0;
    }

    // ─────────────────────────────────────────────
    //   Aesthetics (matching install.sh theme)
    //
    //   install.sh → Symfony Console tags:
    //     C_B_CYAN  1;36  →  <fg=cyan;options=bold>
    //     C_WHITE   0;37  →  <fg=white>
    //     C_B_GREEN 1;32  →  <fg=green;options=bold>
    //     C_GREEN   0;32  →  <fg=green>
    //     C_B_YELLOW 1;33 →  <fg=yellow;options=bold>
    //     C_YELLOW  0;33  →  <fg=yellow>
    //     C_B_RED   1;31  →  <fg=red;options=bold>
    //     C_RED     0;31  →  <fg=red>
    //     C_DIM     2     →  <fg=gray>
    //     C_BOLD    1     →  <options=bold>
    //     C_B_BLUE  1;34  →  <fg=blue;options=bold>
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
    //   System Update (matching install.sh phase 1)
    // ─────────────────────────────────────────────

    protected function runSystemUpdate(): bool
    {
        if (! $this->isLinux()) {
            return true;
        }

        if (! confirm('Run system update (apt update && upgrade)?', default: true)) {
            $this->renderInfo('Skipping system update.');

            return true;
        }

        $this->line('');
        $this->renderInfo('Authenticating sudo access...');
        passthru('sudo -v', $sudoCode);
        $this->line('');

        if ($sudoCode !== 0) {
            $this->renderError('sudo authentication failed.');

            return false;
        }

        $this->renderInfo('Updating package lists...');
        $this->line('');
        passthru('sudo apt update 2>&1', $updateCode);
        $this->line('');

        if ($updateCode !== 0) {
            $this->renderWarn('Some package lists failed to update (this is often normal).');
        } else {
            $this->renderSuccess('Package lists updated.');
        }

        // Check upgradable count
        $upgradable = trim((string) shell_exec('sudo apt list --upgradable 2>/dev/null | grep -c "/" 2>/dev/null')) ?: '0';

        if ($upgradable !== '0' && $upgradable !== '') {
            $this->renderInfo("Upgrading {$upgradable} packages...");
            $this->line('');

            spin(function () {
                passthru('sudo DEBIAN_FRONTEND=noninteractive apt upgrade -y 2>/dev/null', $upgradeCode);

                return $upgradeCode;
            }, 'Upgrading packages...');

            $this->line('');
            $this->renderSuccess('System packages upgraded.');
        } else {
            $this->renderSuccess('All packages are already up to date.');
        }

        return true;
    }

    // ─────────────────────────────────────────────
    //   Prerequisite Checks
    // ─────────────────────────────────────────────

    protected function checkPrerequisites(): bool
    {
        $missing = [];

        foreach (['curl', 'sudo'] as $cmd) {
            if (trim((string) shell_exec("command -v {$cmd} 2>/dev/null")) !== '') {
                $this->renderSuccess("{$cmd} is available");
            } else {
                $this->renderError("{$cmd} is required but not installed");
                $missing[] = $cmd;
            }
        }

        if ($missing !== []) {
            $this->laraKubeNewLine();
            $this->renderError('Missing required tools: '.implode(', ', $missing));
            $this->laraKubeLine('  Install them: sudo apt install '.implode(' ', $missing));

            return false;
        }

        return true;
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
                $os = trim((string) shell_exec("docker info --format '{{.OperatingSystem}}' 2>/dev/null"));

                if (str_contains($os, 'Docker Desktop')) {
                    $this->renderWarn('Docker Desktop detected.');
                    $this->laraKubeLine('  LaraKube works with it, but Docker Engine installed directly in WSL2 is more reliable.');
                } else {
                    $this->renderSuccess('Docker Engine already installed and running.');
                }

                return true;
            }

            $hasDockerService = trim((string) shell_exec('systemctl cat docker 2>/dev/null')) !== '';

            if (! $hasDockerService) {
                $this->renderWarn('Docker Desktop is installed but not running.');
                $this->laraKubeLine('  Docker Desktop\'s daemon cannot be started from WSL2.');
                $this->laraKubeNewLine();
                $this->line('  <fg=yellow>A)</> Start Docker Desktop from Windows and re-run <fg=cyan>larakube setup</>.');
                $this->line('  <fg=yellow>B)</> Install Docker Engine natively in WSL2 (works even when Docker Desktop is off).');
                $this->laraKubeNewLine();

                if (! confirm('Install Docker Engine natively now?', default: true)) {
                    $this->laraKubeLine('  Start Docker Desktop from Windows, then re-run larakube setup.');

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
        $alreadyInstalled = trim((string) shell_exec('dpkg -l docker-ce 2>/dev/null | grep -c "^ii"')) === '1';

        if ($alreadyInstalled) {
            $this->renderInfo('Docker Engine package found — enabling service...');
            shell_exec('sudo systemctl enable --now docker 2>/dev/null');

            return $this->reportDockerReady();
        }

        // ── Download the install script ──
        $this->renderInfo('Downloading Docker install script...');
        exec('curl -fsSL https://get.docker.com -o /tmp/get-docker.sh 2>/dev/null', $_, $dlCode);

        if ($dlCode !== 0) {
            $this->renderError('Failed to download Docker install script.');

            return false;
        }

        $this->renderSuccess('Docker install script downloaded.');

        // ── Pre-authenticate sudo (matching install.sh flow) ──
        $this->line('');
        $this->renderInfo('Authenticating sudo access...');
        passthru('sudo -v', $sudoCode);
        $this->line('');

        if ($sudoCode !== 0) {
            $this->renderError('sudo authentication failed.');

            return false;
        }

        // ── Run the installer with a spinner and elapsed timer ──
        $start = time();

        $installCode = spin(function () {
            exec('sudo sh /tmp/get-docker.sh > /tmp/docker-install.log 2>&1', $_, $code);

            return $code;
        }, 'Installing Docker Engine (this may take 5–15 minutes)...');

        $elapsed = time() - $start;
        $mins = intdiv($elapsed, 60);
        $secs = $elapsed % 60;
        $duration = $mins > 0 ? "{$mins}m {$secs}s" : "{$secs}s";

        if ($installCode !== 0) {
            $this->renderError("Docker Engine installation failed ({$duration}).");
            $this->line('  <fg=gray>Last lines of the install log:</>');
            $this->line('  <fg=red>'.($this->tailFile('/tmp/docker-install.log', 5) ?: '(empty)').'</>');

            return false;
        }

        $this->renderSuccess("Docker Engine installed successfully ({$duration})");

        // Show installed version (matching install.sh)
        $version = trim((string) shell_exec('docker --version 2>/dev/null'));
        if ($version !== '') {
            $this->line("  <fg=gray>{$version}</>");
        }

        // ── Post-install: docker group & service ──
        $user = getenv('USER') ?: get_current_user();
        if ($user) {
            shell_exec('sudo usermod -aG docker '.escapeshellarg((string) $user).' 2>/dev/null');
        }

        shell_exec('sudo systemctl enable --now docker 2>/dev/null');

        $this->line('  You\'ve been added to the <fg=cyan;options=bold>docker</> group, but your current shell session');
        $this->line('  won\'t pick it up until you run: <fg=cyan;options=bold>newgrp docker</> (or open a new terminal).');

        return true;
    }

    protected function reportDockerReady(): bool
    {
        $this->renderSuccess('Docker Engine running.');
        $version = trim((string) shell_exec('docker --version 2>/dev/null'));
        if ($version !== '') {
            $this->line("  <fg=gray>{$version}</>");
        }

        return true;
    }

    protected function tailFile(string $path, int $lines = 5): string
    {
        if (! file_exists($path)) {
            return '';
        }

        return implode('', array_slice(file($path), -$lines));
    }

    // ─────────────────────────────────────────────
    //   k9s
    // ─────────────────────────────────────────────

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
