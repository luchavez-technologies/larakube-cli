<?php

namespace App\Commands;

use App\Traits\DetectsWsl;
use App\Traits\InstallsK9s;
use App\Traits\InteractsWithOs;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class SetupCommand extends Command
{
    use DetectsWsl, InstallsK9s, InteractsWithOs, LaraKubeOutput;

    protected $signature = 'setup';

    protected $description = 'First-time setup: install Docker Engine, k3s cluster, and optional tools';

    /**
     * Critical one-time follow-up actions (e.g. "run newgrp docker") collected as
     * setup runs. Printed inline where they happen AND again as a final summary —
     * cluster:setup and k9s installation can both print a lot of scrolling output
     * afterward, so a note shown only once near the top of a long run is easy to
     * miss and easy to forget by the time setup finishes.
     *
     * @var string[]
     */
    protected array $reminders = [];

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Environment Setup');

        if (! $this->isLinux()) {
            $this->laraKubeError('larakube setup only runs on Linux and WSL2.');
            $this->newLine();
            $this->line('  <fg=gray>On macOS, use OrbStack or Docker Desktop\'s built-in Kubernetes.</>');
            $this->line('  <fg=gray>On Windows, open your WSL2 terminal and run this command there.</>');

            return 1;
        }

        // Step 1 — Docker Engine
        if (! $this->ensureDockerInstalled()) {
            return 1;
        }

        $this->newLine();

        // Step 2 — k3s cluster (delegates entirely to cluster:setup)
        $result = $this->call('cluster:setup');

        if ($result !== 0) {
            return $result;
        }

        $this->newLine();

        // Step 3 — k9s (optional terminal UI for browsing the cluster)
        $this->offerK9s();

        $this->renderReminders();

        return 0;
    }

    /**
     * Re-print every reminder collected during this run as the very last thing on
     * screen, so a critical one-time step (like refreshing the docker group)
     * doesn't get lost above cluster:setup's or k9s's own output. No-op when
     * nothing was collected (e.g. Docker was already installed and running).
     */
    protected function renderReminders(): void
    {
        if ($this->reminders === []) {
            return;
        }

        $this->newLine();
        $this->laraKubeWarn('Before you continue — one-time action(s) needed:');
        foreach ($this->reminders as $reminder) {
            $this->line("  {$reminder}");
        }
    }

    protected function ensureDockerInstalled(): bool
    {
        $hasDocker = trim(Process::run('command -v docker')->output()) !== '';

        if ($hasDocker) {
            $dockerInfoOk = Process::run('docker info')->successful();

            if ($dockerInfoOk) {
                $os = trim(Process::run('docker info --format \'{{.OperatingSystem}}\'')->output());

                if (str_contains($os, 'Docker Desktop')) {
                    $this->laraKubeWarn('Docker Desktop detected.');
                    $this->line('  LaraKube works with it, but Docker Engine installed directly in WSL2 is more reliable.');
                    $this->line('  See: <fg=cyan>https://cli.larakube.app/onboarding/operating-systems/windows</>');
                } else {
                    $this->laraKubeInfo('Docker Engine already installed and running.');
                }

                return true;
            }

            // No docker.service unit means the docker CLI comes from Docker Desktop's
            // WSL integration — there is no local daemon to start via systemctl.
            $hasDockerService = trim(Process::run('systemctl cat docker')->output()) !== '';

            if (! $hasDockerService) {
                $this->laraKubeWarn('Docker Desktop is installed but not running.');
                $this->line('  Docker Desktop\'s daemon cannot be started from WSL2.');
                $this->newLine();
                $this->line('  You have two options:');
                $this->line('  <fg=yellow>A)</> Start Docker Desktop from Windows and re-run <fg=cyan>larakube setup</>.');
                $this->line('  <fg=yellow>B)</> Install Docker Engine natively in WSL2 (works even when Docker Desktop is off).');
                $this->newLine();

                if (! confirm('Install Docker Engine natively now?', default: true)) {
                    $this->line('  <fg=gray>Start Docker Desktop from Windows, then re-run larakube setup.</>');

                    return false;
                }

                return $this->installDockerEngine();
            }

            $this->laraKubeInfo('Docker Engine found — starting the service...');
            passthru('sudo systemctl start docker 2>/dev/null', $startCode);

            if ($startCode !== 0) {
                $this->laraKubeError('Could not start Docker. Run: sudo systemctl start docker');

                return false;
            }

            $this->laraKubeInfo('✅ Docker Engine running.');

            return true;
        }

        return $this->installDockerEngine();
    }

    protected function installDockerEngine(): bool
    {
        // If the docker-ce package is already installed (e.g. a prior run that left
        // the service stopped), skip the installer and just enable the service.
        $alreadyInstalled = trim(Process::run('dpkg -l docker-ce | grep -c "^ii"')->output()) === '1';

        if ($alreadyInstalled) {
            $this->laraKubeInfo('Docker Engine package found — enabling service...');
            shell_exec('sudo systemctl enable --now docker 2>/dev/null');
            $this->laraKubeInfo('✅ Docker Engine running.');

            return true;
        }

        $this->laraKubeInfo('Installing Docker Engine...');
        // The get.docker.com script warns about an existing docker CLI (Docker Desktop)
        // and about running inside WSL — both warnings are expected here and safe to
        // ignore. Each warning pauses for 20s before continuing automatically.
        $this->line('  <fg=gray>The installer may warn about Docker Desktop or WSL — this is expected. It will continue automatically.</>');
        $this->newLine();
        passthru('curl -fsSL https://get.docker.com | sh', $installCode);

        if ($installCode !== 0) {
            $this->laraKubeError('Docker Engine installation failed. See output above.');

            return false;
        }

        $user = getenv('USER') ?: get_current_user();
        if ($user) {
            shell_exec('sudo usermod -aG docker '.escapeshellarg((string) $user).' 2>/dev/null');
        }

        shell_exec('sudo systemctl enable --now docker 2>/dev/null');

        $this->laraKubeInfo('✅ Docker Engine installed.');
        $this->line('  You\'ve been added to the <fg=cyan>docker</> group, but your current shell session');
        $this->line('  won\'t pick it up until you run: <fg=cyan>newgrp docker</> (or open a new terminal).');

        $this->reminders[] = 'Run <fg=cyan>newgrp docker</> (or open a new terminal) — your shell hasn\'t picked up the docker group yet. Skipping this will make `docker`/`larakube up --build` fail with a permission error.';

        return true;
    }

    protected function offerK9s(): void
    {
        if ($this->resolveK9sBin() !== null) {
            $this->laraKubeInfo('k9s already installed.');

            return;
        }

        $this->line('  <fg=yellow>💡 Optional:</> <fg=cyan>k9s</> is a terminal UI for browsing your cluster.');

        if (! confirm('Install k9s now?', default: true)) {
            $this->line('  <fg=gray>Skipped — install it later with: larakube k9s</>');

            return;
        }

        $this->installK9s();
    }
}
