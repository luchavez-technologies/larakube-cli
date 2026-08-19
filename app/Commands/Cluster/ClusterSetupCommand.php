<?php

namespace App\Commands\Cluster;

use App\Traits\DetectsWsl;
use App\Traits\InstallsK3s;
use App\Traits\InteractsWithKustomize;
use App\Traits\InteractsWithOs;
use App\Traits\LaraKubeOutput;
use App\Traits\PrunesKubeContext;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class ClusterSetupCommand extends Command
{
    use DetectsWsl, InstallsK3s, InteractsWithKustomize, InteractsWithOs, LaraKubeOutput, PrunesKubeContext;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cluster:setup {--volume= : A specific host directory to mount into the cluster (e.g. ~/Codes)}';

    /**
     * The console command description.
     */
    protected $description = 'Install and configure a local Kubernetes cluster (native k3s on Linux/WSL2)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Local Cluster Installer');

        // 1. k3s needs a Linux kernel — WSL2 qualifies. macOS/Windows users should
        //    use Docker Desktop's built-in Kubernetes, OrbStack, or a cloud target.
        if (! $this->isLinux()) {
            $this->laraKubeError('Native k3s needs a Linux kernel.');
            $this->newLine();
            $this->line('  <fg=gray>Your options:</>');
            $this->line('  1. Use Docker Desktop\'s built-in Kubernetes (Settings → Kubernetes → Enable).');
            $this->line('  2. Use OrbStack on macOS — it has built-in k3s-based Kubernetes.');
            $this->line('  3. Deploy to a cloud target via `larakube cloud:init`.');

            return 1;
        }

        // 2. WSL2 is Linux under the hood — k3s works natively there too.
        $where = $this->isWsl() ? 'WSL2' : 'Linux';
        $this->laraKubeInfo("Installing native k3s on {$where}...");

        if ($this->isWsl()) {
            $this->unmountDockerDesktopHost();
        }

        $result = $this->installK3s();

        // 3. Make sure a kustomize that can build our multi-doc patches is available:
        //    probes the machine's own kustomize and installs a pinned standalone only when
        //    it can't build them (k3s/WSL, or an older v5 like v5.0.4). A capable kubectl
        //    (recent macOS/OrbStack) uses its own — no download. Runs on the "already
        //    exists" path too.
        if ($result === 0) {
            $this->ensureKustomizeReady();
            $this->warnIfNewerK3sAvailable();
        }

        return $result;
    }

    protected function installK3s(): int
    {
        $this->laraKubeInfo('Installing native k3s...');
        // --write-kubeconfig-mode=644 is a k3s server flag that makes k3s always write
        // /etc/rancher/k3s/k3s.yaml with 644 permissions — survives service restarts.
        // K3S_KUBECONFIG_MODE=644 is the installer env equivalent, applied when the
        // service is (re)started; both are set so re-runs without "No change detected"
        // also get the right mode.
        passthru($this->k3sInstallCommand($this->k3sVersion(), ['--disable=traefik', '--write-kubeconfig-mode=644'], ['K3S_KUBECONFIG_MODE' => '644'], sudo: true), $installCode);

        if ($installCode !== 0) {
            $this->laraKubeError('k3s installation failed. Please review the output above.');

            return 1;
        }

        // k3s registers its Node asynchronously after the service starts. Running
        // `kubectl wait` before the Node object exists fails immediately with
        // "no matching resources found", so poll until it appears, then wait for
        // it to become Ready.
        // WSL2 first boot is slower (kernel modules, containerd init) and routinely
        // needs 90–120s; native Linux is usually done in 20–30s.
        $this->laraKubeInfo('Waiting for node to be ready...');

        $maxAttempts = $this->isWsl() ? 90 : 40; // 180s on WSL2, 80s on Linux
        $nodeAppeared = false;
        for ($i = 0; $i < $maxAttempts; $i++) {
            if (trim((string) shell_exec('sudo k3s kubectl get nodes --no-headers 2>/dev/null')) !== '') {
                $nodeAppeared = true;
                break;
            }
            Sleep::sleep(2);
        }

        if (! $nodeAppeared) {
            $this->laraKubeWarn('Timed out waiting for the k3s node to register.');
            $this->line('  k3s is still initializing — this is normal on first boot in WSL2.');
            $this->line('  Wait a moment, then re-run <fg=cyan>larakube cluster:setup</> to complete setup.');
            $this->line('  You can check live progress with: <fg=cyan>sudo journalctl -u k3s -f</>');
            // Still chmod in case the file exists — it won't be overwritten once k3s
            // fully starts (--write-kubeconfig-mode=644 handles future restarts).
            passthru('sudo chmod 644 /etc/rancher/k3s/k3s.yaml 2>/dev/null');

            return 1;
        }

        passthru('sudo k3s kubectl wait --for=condition=ready node --all --timeout=120s');

        // Belt-and-suspenders: --write-kubeconfig-mode=644 is set as a server flag so
        // k3s writes it 644 on every restart, but chmod here heals re-runs where the
        // installer skips restarting the service ("No change detected").
        passthru('sudo chmod 644 /etc/rancher/k3s/k3s.yaml 2>/dev/null');

        // Rename k3s's hardcoded "default" context/cluster/user to "k3s-larakube"
        // directly in /etc/rancher/k3s/k3s.yaml, and keep a hook installed so it
        // re-applies on every future k3s start too — see method doc for why.
        $this->installContextRenameHook();

        // Let the developer's own image-sideload path (`larakube up` building a
        // local image, then importing it into k3s's containerd) skip the sudo
        // password prompt — see method doc for why this is scoped, not blanket.
        $this->installK3sCtrSudoersRule();

        // k3s writes its kubeconfig to /etc/rancher/k3s/k3s.yaml (root-owned) and
        // never touches ~/.kube/config — so kubectl and `larakube context` can't
        // see it until we merge it in. Prune any stale k3s-larakube entry first so
        // the fresh merge starts clean (no dangling current-context from a prior run).
        $this->pruneKubeContext(['k3s-larakube']);
        $this->mergeK3sKubeconfig();

        $this->laraKubeInfo('✅ Native k3s cluster is ready!');
        $this->info('You can now use larakube up to deploy your projects.');

        return 0;
    }

    /**
     * Docker Desktop's WSL integration mounts C:\Program Files\Docker\Docker\resources
     * at /Docker/host using 9p. The Windows path contains a space that Docker Desktop
     * does NOT escape inside the mount options, so /proc/mounts ends up with a 7-token
     * line where k3s's ContainerManager parser expects 6 — crashing on startup.
     * Unmounting before the installer runs (or before k3s restarts) sidesteps the issue.
     * Docker Desktop automatically remounts /Docker/host once it detects it gone, so
     * image builds are unaffected after k3s is up.
     */
    protected function unmountDockerDesktopHost(): void
    {
        if (! file_exists('/Docker/host')) {
            return;
        }

        $mounted = str_contains(Process::run('grep -q " /Docker/host " /proc/mounts && echo yes')->output(), 'yes');

        if (! $mounted) {
            return;
        }

        $this->laraKubeInfo('Unmounting /Docker/host (Docker Desktop WSL mount) before k3s starts...');
        shell_exec('sudo umount /Docker/host 2>/dev/null');
    }

    /**
     * Rename k3s's hardcoded "default" context/cluster/user to "k3s-larakube"
     * directly inside /etc/rancher/k3s/k3s.yaml, and install a systemd
     * ExecStartPost hook on k3s.service that re-applies the same rename on
     * every future k3s start.
     *
     * k3s rewrites /etc/rancher/k3s/k3s.yaml from scratch on every service
     * start — its mtime lands within the same second as k3s.service's
     * ActiveEnterTimestamp — so a one-time rename would silently revert on the
     * next `wsl --shutdown`, reboot, or `systemctl restart k3s`. Fixing the
     * file at its source (rather than only the copy merged into
     * ~/.kube/config) means k3s's own bundled `kubectl` binary
     * (/usr/local/bin/kubectl → k3s symlink, which defaults to reading this
     * file when KUBECONFIG isn't set) also sees the right context name with
     * no KUBECONFIG pin or shell rc changes required.
     */
    protected function installContextRenameHook(): void
    {
        $script = <<<'SH'
        #!/bin/sh
        # Installed by `larakube cluster:setup`. k3s rewrites
        # /etc/rancher/k3s/k3s.yaml from scratch on every start, so this
        # re-applies the "default" -> "k3s-larakube" rename each time.
        set -e
        FILE=/etc/rancher/k3s/k3s.yaml
        for i in $(seq 1 30); do
            [ -s "$FILE" ] && break
            sleep 1
        done
        [ -s "$FILE" ] || exit 0
        sed -i -E \
            -e 's/^(\s*(- )?name: )default$/\1k3s-larakube/' \
            -e 's/^(\s*cluster: )default$/\1k3s-larakube/' \
            -e 's/^(\s*user: )default$/\1k3s-larakube/' \
            -e 's/^(current-context: )default$/\1k3s-larakube/' \
            "$FILE"
        chmod 644 "$FILE"
        SH;

        // Both files below are read by a `sudo cp` — a hardcoded /tmp path
        // would let any local user race it with a symlink before sudo reads
        // it, installing attacker-controlled content at a system-owned
        // destination. A 0700 directory (no group/other execute bit — no
        // traversal, so nothing to symlink over) plus a cryptographically
        // random name (not TemporaryDirectory's default
        // mt_rand()+microtime(), which a local attacker could feasibly
        // predict) closes that race.
        $scriptPath = '/usr/local/bin/larakube-rename-k3s-context.sh';
        $temporaryDirectory = (new TemporaryDirectory)
            ->name(bin2hex(random_bytes(16)))
            ->permission(0700)
            ->deleteWhenDestroyed()
            ->create();
        $tmpScript = $temporaryDirectory->path().'/rename-context.sh';
        file_put_contents($tmpScript, $script);
        passthru('sudo cp '.escapeshellarg($tmpScript).' '.escapeshellarg($scriptPath));
        passthru('sudo chmod 755 '.escapeshellarg($scriptPath));
        $temporaryDirectory->delete();

        $unit = "[Service]\nExecStartPost={$scriptPath}\n";
        $dropInDir = '/etc/systemd/system/k3s.service.d';
        $dropInFile = $dropInDir.'/larakube-rename-context.conf';
        $unitTemporaryDirectory = (new TemporaryDirectory)
            ->name(bin2hex(random_bytes(16)))
            ->permission(0700)
            ->deleteWhenDestroyed()
            ->create();
        $tmpUnit = $unitTemporaryDirectory->path().'/larakube-rename-context.conf';
        file_put_contents($tmpUnit, $unit);
        passthru('sudo mkdir -p '.escapeshellarg($dropInDir));
        passthru('sudo cp '.escapeshellarg($tmpUnit).' '.escapeshellarg($dropInFile));
        $unitTemporaryDirectory->delete();

        passthru('sudo systemctl daemon-reload');

        // Fix the file immediately too — don't make the user wait for the next restart.
        passthru('sudo '.escapeshellarg($scriptPath));
    }

    /**
     * Grant the current user passwordless sudo for `k3s ctr` — the command
     * InteractsWithDocker's sideloadIntoK3s() runs to import a locally-built
     * image into k3s's (root-owned) containerd store, since k3s ships no
     * unprivileged group for that socket the way Docker has the `docker`
     * group. Scoped to the k3s binary specifically, NOT a blanket
     * NOPASSWD:ALL like cloud:init grants its own "larakube" user on a
     * REMOTE droplet — this is the developer's own account on their own
     * machine, so the rule stays as narrow as the one command that needs it.
     *
     * Best-effort: any failure here just means image sideload keeps prompting
     * for a sudo password like it always has, so it never blocks setup.
     */
    protected function installK3sCtrSudoersRule(): void
    {
        $user = trim(Process::run('id -un')->output());
        if ($user === '') {
            return;
        }

        $rule = "{$user} ALL=(root) NOPASSWD: /usr/local/bin/k3s ctr *\n";
        // sudo cp reads this file — same symlink-race exposure as the hook
        // script/systemd unit above, higher stakes (a sudoers entry). 0700 +
        // cryptographically random name closes it.
        $temporaryDirectory = (new TemporaryDirectory)
            ->name(bin2hex(random_bytes(16)))
            ->permission(0700)
            ->deleteWhenDestroyed()
            ->create();
        $tmp = $temporaryDirectory->path().'/larakube-k3s-ctr';
        file_put_contents($tmp, $rule);
        chmod($tmp, 0440);

        // visudo -c -f validates syntax against a COPY before it's ever installed
        // as a real sudoers file — a malformed /etc/sudoers.d entry can break sudo
        // entirely, so this check is not optional.
        if (! Process::run('visudo -c -f '.escapeshellarg($tmp))->successful()) {
            $temporaryDirectory->delete();

            return;
        }

        $installed = Process::run('sudo cp '.escapeshellarg($tmp).' /etc/sudoers.d/larakube-k3s-ctr')->successful()
            && Process::run('sudo chmod 440 /etc/sudoers.d/larakube-k3s-ctr')->successful();
        $temporaryDirectory->delete();

        // Best-effort per the docblock above — silent on failure rather than
        // an error, since sideload just falls back to prompting for a sudo
        // password like it always has. It must NOT claim success it didn't
        // actually achieve, though.
        if ($installed) {
            $this->laraKubeInfo('Granted passwordless sudo for `k3s ctr` (image sideload) to the current user.');
        }
    }

    /**
     * Merge the k3s kubeconfig into the user's ~/.kube/config so kubectl and
     * `larakube context` can see and select it. installContextRenameHook()
     * already renames the source file's "default" entries to "k3s-larakube",
     * but the rename here stays as a harmless no-op fallback (e.g. if the
     * systemd hook couldn't be installed) — it only ever matches "default".
     */
    protected function mergeK3sKubeconfig(): void
    {
        $source = '/etc/rancher/k3s/k3s.yaml';

        $raw = shell_exec('sudo cat '.escapeshellarg($source).' 2>/dev/null');

        if (empty($raw)) {
            $this->laraKubeWarn("Could not read the k3s kubeconfig at {$source}.");
            $this->line('  👉 Merge it into ~/.kube/config manually to use it with kubectl.');

            return;
        }

        // Rename the "default" context/cluster/user. Each replacement is anchored
        // to its YAML key and line end, so base64 cert data is never touched.
        $contextName = 'k3s-larakube';
        $raw = preg_replace('/^(\s*(?:- )?name: )default$/m', '${1}'.$contextName, $raw);
        $raw = preg_replace('/^(\s*cluster: )default$/m', '${1}'.$contextName, $raw);
        $raw = preg_replace('/^(\s*user: )default$/m', '${1}'.$contextName, $raw);
        $raw = preg_replace('/^(current-context: )default$/m', '${1}'.$contextName, $raw);

        $home = $_SERVER['HOME'] ?? getenv('HOME');
        if (! $home) {
            $this->laraKubeWarn('Could not determine your home directory; skipping kubeconfig merge.');

            return;
        }

        $kubeDir = $home.'/.kube';
        $kubeConfig = $kubeDir.'/config';

        if (! is_dir($kubeDir)) {
            @mkdir($kubeDir, 0755, true);
        }

        $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
        $tmp = $temporaryDirectory->path().'/k3s-kubeconfig';
        file_put_contents($tmp, $raw);

        // List the existing config first so its other contexts survive the merge;
        // --flatten inlines the cert data into a single self-contained file.
        $kubeconfigEnv = file_exists($kubeConfig) ? $kubeConfig.':'.$tmp : $tmp;
        $merged = Process::run('KUBECONFIG='.escapeshellarg($kubeconfigEnv).' kubectl config view --flatten')->output();

        $temporaryDirectory->delete();

        if ($merged === '') {
            $this->laraKubeWarn('Failed to merge the k3s kubeconfig automatically.');

            return;
        }

        file_put_contents($kubeConfig, $merged);
        @chmod($kubeConfig, 0600);

        // Target ~/.kube/config explicitly — a bare `kubectl` would use $KUBECONFIG
        // (often k3s's own file), where this context doesn't exist.
        Process::run('KUBECONFIG='.escapeshellarg($kubeConfig).' kubectl config use-context '.escapeshellarg($contextName));

        // Verify the context actually landed — the flatten/merge can silently no-op.
        $contexts = array_filter(explode("\n", trim(Process::run(
            'KUBECONFIG='.escapeshellarg($kubeConfig).' kubectl config get-contexts -o name',
        )->output())));
        if (! in_array($contextName, $contexts, true)) {
            $this->laraKubeWarn("Merge did not produce the '{$contextName}' context in ~/.kube/config.");
            $this->laraKubeLine('  👉 Ensure /etc/rancher/k3s/k3s.yaml is readable, then re-run `larakube cluster:setup`.');

            return;
        }

        $this->laraKubeInfo("Merged k3s into ~/.kube/config as context <fg=cyan>{$contextName}</>.");

        // Older larakube versions pinned KUBECONFIG in the shell rc to work around
        // k3s's bundled kubectl reading /etc/rancher/k3s/k3s.yaml (context "default")
        // instead of ~/.kube/config. installContextRenameHook() now keeps that source
        // file itself named "k3s-larakube", so the pin is unnecessary — remove it if
        // a prior run left it behind.
        $this->unpinKubeconfigFromShell($home);
    }

    protected function unpinKubeconfigFromShell(string $home): void
    {
        $marker = '# larakube: pin kubeconfig';

        foreach (["$home/.bashrc", "$home/.zshrc"] as $rc) {
            if (! file_exists($rc)) {
                continue;
            }

            $contents = (string) file_get_contents($rc);

            if (! str_contains($contents, $marker)) {
                continue;
            }

            $cleaned = preg_replace('/\n'.preg_quote($marker, '/').'\nexport KUBECONFIG="[^"]*"\n/', '', $contents);
            file_put_contents($rc, $cleaned);
        }
    }
}
