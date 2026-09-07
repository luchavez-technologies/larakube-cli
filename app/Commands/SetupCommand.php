<?php

namespace App\Commands;

use App\Traits\CollectsReminders;
use App\Traits\ConfiguresWslNetworking;
use App\Traits\DetectsWsl;
use App\Traits\InstallsK9s;
use App\Traits\InstallsPodman;
use App\Traits\InteractsWithOs;
use App\Traits\InteractsWithTrust;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesContainerRuntime;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class SetupCommand extends Command
{
    use CollectsReminders, ConfiguresWslNetworking, DetectsWsl, InstallsK9s, InstallsPodman, InteractsWithOs, InteractsWithTrust, LaraKubeOutput, ResolvesContainerRuntime, StreamsProcessOutput;

    protected $signature = 'setup
        {--runtime= : Container runtime to install without prompting (podman or docker)}';

    protected $description = 'First-time setup: container runtime (Podman/Docker), k3s cluster, Traefik, dnsmasq, and k9s';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Environment Setup');

        if (! $this->isLinux() && ! $this->isDarwin()) {
            $this->laraKubeError('larakube setup only runs on Linux, WSL2, and macOS.');
            $this->newLine();
            $this->line('  <fg=gray>On Windows outside WSL2, open a WSL2 terminal and run this command there.</>');

            return 1;
        }

        if ($this->isDarwin()) {
            // macOS doesn't get a Docker/k3s install of its own — OrbStack or
            // Docker Desktop already provides both, and reinstalling either
            // here would just conflict with whichever the user has running.
            $this->laraKubeInfo('macOS detected — Docker and the Kubernetes cluster come from OrbStack or Docker Desktop, not larakube.');
            $this->line('  <fg=gray>Make sure one of them is running (with Kubernetes enabled) before continuing.</>');
            $this->newLine();
        } else {
            // Step 1 — container runtime (Linux/WSL2 only). Rootless Podman is
            // preferred here — daemonless, no privileged socket, nothing to
            // `systemctl start` — with Docker Engine as the alternative.
            if (! $this->ensureContainerRuntimeInstalled()) {
                return 1;
            }

            $this->newLine();

            // Step 2 — k3s cluster (Linux/WSL2 only; delegates entirely to cluster:setup)
            $result = $this->call('cluster:setup');

            if ($result !== 0) {
                return $result;
            }

            $this->newLine();
        }

        // Step 3 — Traefik ingress controller (delegates entirely to traefik:setup)
        $result = $this->call('traefik:setup');

        if ($result !== 0) {
            return $result;
        }

        $this->newLine();

        // Step 4 — dnsmasq (wildcard *.{tld} DNS) — required on macOS/Linux so
        // every host resolves with no per-project /etc/hosts entries. WSL2's
        // dnsmasq is inside the VM (invisible to the Windows browser) so this
        // is a no-op there — WSL2's real DNS problem is the Windows-side hosts
        // file instead, which `larakube up`/`larakube hosts` handles.
        $this->setupDnsmasq();

        $this->newLine();

        // Step 5 — k9s (terminal UI for browsing the cluster)
        $this->ensureK9sInstalled();

        // Step 6 — WSL2 mirrored networking (WSL only; self-guards otherwise).
        // Gives the Windows browser a stable 127.0.0.1 to the cluster so the
        // hosts entry never staleifies on the next reboot — the recurring pain
        // the one-time hosts sync can't fix on its own.
        $this->newLine();
        $this->ensureMirroredNetworking();

        $this->renderReminders();

        return 0;
    }

    /** Validate the --runtime flag, or null when unset/unrecognised. Pure. */
    public function normalizeRuntimeFlag(?string $flag): ?string
    {
        if ($flag === null) {
            return null;
        }

        $flag = strtolower(trim($flag));

        return in_array($flag, ['podman', 'docker'], true) ? $flag : null;
    }

    /**
     * Ensure a functional container runtime exists on this Linux/WSL2 host,
     * preferring rootless Podman. Returns false (already reported) when nothing
     * usable could be installed.
     */
    protected function ensureContainerRuntimeInstalled(): bool
    {
        // Already functional? Prefer Podman, accept an existing Docker.
        if ($this->podmanIsFunctional()) {
            $this->laraKubeInfo('Rootless Podman already installed and functional.');

            return true;
        }

        if ($this->dockerIsFunctional()) {
            // Hand off to the Docker path, which keeps its Docker-Desktop-in-WSL
            // guidance and service-start handling.
            return $this->ensureDockerInstalled();
        }

        return $this->chooseRuntimeToInstall() === 'docker'
            ? $this->ensureDockerInstalled()
            : $this->installRootlessPodman();
    }

    /**
     * Which runtime to install when none is functional yet. `--runtime` decides
     * headlessly; otherwise prompt, defaulting to Podman.
     */
    protected function chooseRuntimeToInstall(): string
    {
        if (($flag = $this->normalizeRuntimeFlag($this->option('runtime'))) !== null) {
            return $flag;
        }

        $this->laraKubeInfo('No functional container runtime found yet.');
        $this->newLine();
        $this->line('  <fg=yellow>Rootless Podman</> — daemonless, no root socket, nothing to start (recommended on WSL/Linux).');
        $this->line('  <fg=yellow>Docker Engine</> — the classic daemon; needs a running service.');
        $this->newLine();

        return select(
            label: 'Which container runtime should larakube install?',
            options: ['podman' => 'Rootless Podman (recommended)', 'docker' => 'Docker Engine'],
            default: 'podman',
        );
    }

    /**
     * A fresh WSL2 distro's apt cache can be stale enough to make a package
     * install misbehave, so refresh it before installing Podman — the
     * InstallsPodman hook `setup` opts into (the `up` menu skips it).
     */
    protected function beforePodmanInstall(): void
    {
        $this->updateSystemPackages();
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
     * Refresh and upgrade system packages before installing the container
     * runtime (Podman or Docker) — a fresh WSL2 distro's apt cache (and
     * kernel-adjacent tooling) can be stale enough to make an installer
     * misbehave. Best-effort: a failed/interrupted upgrade only warns, since the
     * subsequent apt install / vendor installer refreshes indexes regardless.
     */
    protected function updateSystemPackages(): void
    {
        if (! confirm('Update system packages before installing the container runtime? (recommended on a fresh system)', default: true)) {
            return;
        }

        $this->laraKubeInfo('Updating system packages...');
        passthru('sudo apt-get update && sudo DEBIAN_FRONTEND=noninteractive apt-get upgrade -y', $code);

        if ($code !== 0) {
            $this->laraKubeWarn('System package upgrade failed or was interrupted — continuing with the install anyway.');
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
