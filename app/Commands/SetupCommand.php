<?php

namespace App\Commands;

use App\Traits\DetectsWsl;
use App\Traits\InstallsK9s;
use App\Traits\InteractsWithOs;
use App\Traits\LaraKubeOutput;
use Exception;
use Illuminate\Support\Facades\Http;

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
        $this->shHeader('LaraKube Environment Setup');

        if (! $this->isLinux()) {
            $this->shError('larakube setup only runs on Linux and WSL2.');
            $this->newLine();
            $this->line('  <fg=gray>On macOS, use OrbStack or Docker Desktop\'s built-in Kubernetes.</>');
            $this->line('  <fg=gray>On Windows, open your WSL2 terminal and run this command there.</>');

            return 1;
        }

        // Step 1 — Docker Engine
        $this->shStep('Installing Docker Engine', 'Container runtime for building & scaffolding');
        if (! $this->ensureDockerInstalled()) {
            return 1;
        }

        $this->newLine();
        $this->shFooter();

        // Step 2 — k3s cluster (delegates entirely to cluster:setup)
        $result = $this->call('cluster:setup');

        if ($result !== 0) {
            return $result;
        }

        $this->offerK3sUpdate();

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
        $this->shWarn('Before you continue — one-time action(s) needed:');
        foreach ($this->reminders as $reminder) {
            $this->line("  {$reminder}");
        }
    }

    protected function ensureDockerInstalled(): bool
    {
        $hasDocker = trim((string) shell_exec('command -v docker 2>/dev/null')) !== '';

        if ($hasDocker) {
            exec('docker info 2>/dev/null', $_, $code);

            if ($code === 0) {
                $os = trim((string) shell_exec('docker info --format \'{{.OperatingSystem}}\' 2>/dev/null'));

                if (str_contains($os, 'Docker Desktop')) {
                    $this->shWarn('Docker Desktop detected.');
                    $this->line('  LaraKube works with it, but Docker Engine installed directly in WSL2 is more reliable.');
                    $this->line('  See: <fg=cyan>https://cli.larakube.app/onboarding/operating-systems/windows</>');
                } else {
                    $this->shSuccess('Docker Engine already installed and running.');
                }

                return true;
            }

            // No docker.service unit means the docker CLI comes from Docker Desktop's
            // WSL integration — there is no local daemon to start via systemctl.
            $hasDockerService = trim((string) shell_exec('systemctl cat docker 2>/dev/null')) !== '';

            if (! $hasDockerService) {
                $this->shWarn('Docker Desktop is installed but not running.');
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

            $this->shInfo('Docker Engine found — starting the service...');
            passthru('sudo systemctl start docker 2>/dev/null', $startCode);

            if ($startCode !== 0) {
                $this->shError('Could not start Docker. Run: sudo systemctl start docker');

                return false;
            }

            $this->shSuccess('Docker Engine running.');

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
            $this->shInfo('Docker Engine package found — enabling service...');
            shell_exec('sudo systemctl enable --now docker 2>/dev/null');
            $this->shSuccess('Docker Engine running.');

            return true;
        }

        // --- Detect OS -------------------------------------------------------
        $osRelease = @file_get_contents('/etc/os-release');
        preg_match('/^ID=(\w+)/m', $osRelease ?: '', $id);
        preg_match('/^VERSION_CODENAME=(\w+)/m', $osRelease ?: '', $codename);
        $distro = strtolower($id[1] ?? 'unknown');
        $codename = $codename[1] ?? '';

        if (! in_array($distro, ['ubuntu', 'debian'], true)) {
            $this->shError('Unsupported distribution: '.$distro);
            $this->line('  <fg=gray>Please install Docker manually: https://docs.docker.com/engine/install/</>');

            return false;
        }

        $repoUrl = 'https://download.docker.com/linux/'.$distro;
        $arch = trim((string) shell_exec('dpkg --print-architecture 2>/dev/null')) ?: 'amd64';

        // Build the repository sources entry
        $sourcesEntry = "Types: deb\nURIs: {$repoUrl}\nSuites: {$codename}\nComponents: stable\nArchitectures: {$arch}\nSigned-By: /etc/apt/keyrings/docker.asc";

        // Authenticate sudo upfront
        $this->newLine();
        $this->shInfo('Sudo access is required for installation.');
        $this->line('  <fg=gray>Enter your password to continue.</>');
        $this->newLine();
        passthru('sudo -v 2>&1');
        $this->newLine();

        // --- Build step list ------------------------------------------------
        $steps = [
            [
                'label' => 'Update apt package index',
                'command' => 'sudo apt update -qq',
            ],
            [
                'label' => 'Install prerequisites (ca-certificates, curl)',
                'command' => 'sudo apt install -y -qq ca-certificates curl',
            ],
            [
                'label' => 'Create keyring directory',
                'command' => 'sudo install -m 0755 -d /etc/apt/keyrings',
            ],
            [
                'label' => "Download Docker's GPG key",
                'command' => 'sudo curl -fsSL '.$repoUrl.'/gpg -o /etc/apt/keyrings/docker.asc && sudo chmod a+r /etc/apt/keyrings/docker.asc',
            ],
            [
                'label' => 'Add Docker apt repository ('.ucfirst($distro).' '.$codename.')',
                'command' => 'echo '.escapeshellarg($sourcesEntry).' | sudo tee /etc/apt/sources.list.d/docker.sources > /dev/null',
            ],
            [
                'label' => 'Update apt with Docker repository',
                'command' => 'sudo apt update -qq',
            ],
            [
                'label' => 'Install Docker packages',
                'command' => 'sudo apt install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin',
            ],
        ];

        $ok = $this->shRunSteps('Docker Installation Steps', $steps);

        if (! $ok) {
            $this->shError('Docker Engine installation failed.');
            $this->line('  <fg=gray>Check the output above for details. You can also install manually:</>');
            $this->line('  <fg=cyan>  https://docs.docker.com/engine/install/'.$distro.'/</>');

            return false;
        }

        shell_exec('sudo systemctl enable --now docker 2>/dev/null');

        $this->shSuccess('Docker Engine installed.');
        $this->newLine();
        $user = getenv('USER') ?: get_current_user();
        $this->shWarn('Docker requires '.$user.' to be in the "docker" group to run containers without sudo.');

        if ($user && confirm('Add "'.$user.'" to the docker group now? (sudo usermod -aG docker '.$user.')', default: true)) {
            shell_exec('sudo usermod -aG docker '.escapeshellarg((string) $user).' 2>/dev/null');
            $this->line('  <fg=gray>Group added. To activate it in your current shell, run:</>');
            $this->line('  <fg=cyan>  newgrp docker</>');
            $this->newLine();
            $this->line('  <fg=gray>Or close and reopen your terminal.</>');
        } else {
            $this->line('  <fg=gray>You can do this later manually:</>');
            $this->line('  <fg=cyan>  sudo usermod -aG docker $USER</>');
            $this->line('  <fg=gray>Then run <fg=cyan>newgrp docker</> or restart your terminal.</>');
        }

        $this->reminders[] = 'Run <fg=cyan>newgrp docker</> (or open a new terminal) — your shell hasn\'t picked up the docker group yet. Skipping this will make `docker`/`larakube up --build` fail with a permission error.';

        return true;
    }

    protected function offerK9s(): void
    {
        $bin = $this->resolveK9sBin();

        if ($bin !== null) {
            $this->shSuccess('k9s already installed.');
            $this->offerK9sUpdate($bin);

            return;
        }

        $this->line('  <fg=yellow>💡 Optional:</> <fg=cyan>k9s</> is a terminal UI for browsing your cluster.');

        if (! confirm('Install k9s now?', default: true)) {
            $this->line('  <fg=gray>Skipped — install it later with: larakube k9s</>');

            return;
        }

        $this->installK9s();
    }

    protected function offerK3sUpdate(): void
    {
        $bin = trim((string) shell_exec('command -v k3s 2>/dev/null'));
        if ($bin === '') {
            return;
        }

        $versionOut = trim((string) shell_exec($bin.' --version 2>/dev/null'));
        preg_match('/k3s version (v\S+)/', $versionOut, $m);
        $current = $m[1] ?? null;

        if ($current === null) {
            return;
        }

        $latest = $this->latestGithubVersion('k3s-io/k3s');
        if ($latest === null || $latest === $current) {
            $this->shSuccess("k3s {$current} (latest)");

            return;
        }

        $this->line('  <fg=yellow>⚠</>  k3s <fg=green>'.$latest.'</> available (current: <fg=yellow>'.$current.'</>)');
        if (confirm('Upgrade k3s to '.$latest.'?', default: true)) {
            $cmd = 'curl -sfL https://get.k3s.io | INSTALL_K3S_VERSION='.escapeshellarg($latest).' sh -s - --disable=traefik --write-kubeconfig-mode=644';
            exec('sudo '.$cmd.' 2>/dev/null', $_, $code);
            if ($code === 0) {
                $this->shSuccess('k3s upgraded to '.$latest);
            } else {
                $this->shError('k3s upgrade failed.');
            }
        }
    }

    protected function offerK9sUpdate(string $bin): void
    {
        $versionOut = trim((string) shell_exec(escapeshellarg($bin).' version --short 2>/dev/null'));
        if ($versionOut === '') {
            return;
        }
        $current = 'v'.ltrim($versionOut, 'v');

        $latest = $this->latestGithubVersion('derailed/k9s');
        if ($latest === null || $latest === $current) {
            $this->shSuccess("k9s {$current} (latest)");

            return;
        }

        $this->line('  <fg=yellow>⚠</>  k9s <fg=green>'.$latest.'</> available (current: <fg=yellow>'.$current.'</>)');
        if (confirm('Upgrade k9s to '.$latest.'?', default: true)) {
            $this->installK9s($latest);
            if ($this->resolveK9sBin() !== null) {
                $this->shSuccess('k9s upgraded to '.$latest);
            } else {
                $this->shError('k9s upgrade failed.');
            }
        }
    }

    protected function latestGithubVersion(string $repo): ?string
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'LaraKube-CLI'])
                ->timeout(5)
                ->get("https://api.github.com/repos/{$repo}/releases/latest");

            if ($response->failed()) {
                return null;
            }

            return $response->json('tag_name');
        } catch (Exception) {
            return null;
        }
    }
}
