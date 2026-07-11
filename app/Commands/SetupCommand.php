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

    protected $description = 'First-time setup: install Docker Engine, k3s cluster, Traefik, and k9s';

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

        // Step 3 — Traefik ingress controller (delegates entirely to traefik:setup)
        $result = $this->call('traefik:setup');

        if ($result !== 0) {
            return $result;
        }

        $this->newLine();

        // Step 4 — k9s (terminal UI for browsing the cluster)
        $this->ensureK9sInstalled();

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

        $this->updateSystemPackages();

        $this->laraKubeInfo('Installing Docker Engine...');
        // The official installer runs its own built-in "press Ctrl+C to abort"
        // safety pause (~20s, sometimes twice — once for an existing docker CLI,
        // once for WSL2) before it continues. It even prints a raw shell trace
        // line like `+ sleep 20` while doing so, which can look like a hang —
        // it isn't; it always resumes on its own.
        $this->line('  <fg=gray>Docker\'s official installer pauses for a built-in ~20s safety check (maybe twice)</>');
        $this->line('  <fg=gray>before continuing — a line like `+ sleep 20` sitting there is expected, not a hang.</>');
        $this->newLine();
        passthru('curl -fsSL https://get.docker.com | sh', $installCode);

        if ($installCode !== 0) {
            $this->laraKubeError('Docker Engine installation failed. See output above.');

            return false;
        }

        shell_exec('sudo systemctl enable --now docker 2>/dev/null');

        $this->laraKubeInfo('✅ Docker Engine installed.');

        $user = getenv('USER') ?: get_current_user();
        if ($user) {
            $this->ensureUserInDockerGroup((string) $user);
        }

        return true;
    }

    /**
     * Refresh and upgrade system packages before installing Docker — a fresh
     * WSL2 distro's apt cache (and kernel-adjacent tooling) can be stale enough
     * to make the Docker installer misbehave. Best-effort: a failed/interrupted
     * upgrade only gets a warning, since Docker's own installer runs its own
     * `apt-get update` regardless.
     */
    protected function updateSystemPackages(): void
    {
        if (! confirm('Update system packages before installing Docker Engine? (recommended on a fresh system)', default: true)) {
            return;
        }

        $this->laraKubeInfo('Updating system packages...');
        passthru('sudo apt-get update && sudo DEBIAN_FRONTEND=noninteractive apt-get upgrade -y', $code);

        if ($code !== 0) {
            $this->laraKubeWarn('System package upgrade failed or was interrupted — continuing with Docker installation anyway.');
        }

        $this->newLine();
    }

    /**
     * Add $user to the docker group and confirm it actually took, instead of
     * firing the sudo usermod call and hoping — a silently-swallowed sudo prompt
     * (or running before the docker group existed) used to leave the user with
     * no group membership and no indication anything had gone wrong.
     */
    protected function ensureUserInDockerGroup(string $user): void
    {
        if ($this->userInDockerGroup($user)) {
            return;
        }

        // passthru (not shell_exec) so a sudo password prompt is actually visible
        // instead of being captured silently into the output buffer.
        passthru('sudo usermod -aG docker '.escapeshellarg($user), $code);

        if ($code !== 0 || ! $this->userInDockerGroup($user)) {
            $this->laraKubeWarn('Could not confirm you were added to the docker group.');
            $this->line("  Run manually: <fg=cyan>sudo usermod -aG docker {$user}</>");

            $this->reminders[] = "Run <fg=cyan>sudo usermod -aG docker {$user}</> — automatic setup could not confirm the docker group was granted.";

            return;
        }

        $this->line('  You\'ve been added to the <fg=cyan>docker</> group, but your current shell session');
        $this->line('  won\'t pick it up until you run: <fg=cyan>newgrp docker</> (or open a new terminal).');

        $this->reminders[] = 'Run <fg=cyan>newgrp docker</> (or open a new terminal) — your shell hasn\'t picked up the docker group yet. Skipping this will make `docker`/`larakube up --build` fail with a permission error.';
    }

    protected function userInDockerGroup(string $user): bool
    {
        $groups = preg_split('/\s+/', trim(Process::run('id -nG '.escapeshellarg($user))->output()));

        return in_array('docker', $groups, true);
    }

    protected function ensureK9sInstalled(): void
    {
        if ($this->resolveK9sBin() !== null) {
            $this->laraKubeInfo('k9s already installed.');

            return;
        }

        $this->installK9s();
    }
}
