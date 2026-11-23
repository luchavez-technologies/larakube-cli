<?php

namespace App\Services;

use App\Data\ConfigData;
use App\Support\Os;
use App\Traits\ConfiguresWslNetworking;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

class WslBridge
{
    use ConfiguresWslNetworking;

    public const string DEFAULT_DISTRO = 'larakube-ubuntu';

    public const string DEFAULT_USER = 'larakube';

    /** How long a person gets to find and answer the UAC prompt, which can open behind other windows. */
    private const int WINDOWS_HOSTS_PROMPT_SECONDS = 300;

    /**
     * Commands that ALWAYS need the local k3s cluster or the distro's Linux
     * filesystem (projects live in /home/larakube/projects) when invoked on
     * Windows.
     *
     * Every other CLI command (cloud provisioning, Cluster Tools, secrets,
     * DNS verification, backups, kubeconfig contexts, access management,
     * dev boxes, remote workspaces, setup/update, version, wsl:*) runs
     * natively on the Windows host. WslBridgeTest asserts every entry here is
     * a registered command and that no local-only namespace has a command
     * missing from this list.
     *
     * @var list<string>
     */
    private const WSL_LOCAL_COMMANDS = [
        // Local k3s cluster & the Local CA
        'cluster:setup', 'cluster:start', 'cluster:stop', 'cluster:destroy',
        'trust', 'trust:check', 'trust:prune', 'trust:remove', 'trust:reset',
        // Local container execution & dev workflows
        'art', 'exec', 'php', 'node', 'composer', 'npm', 'yarn', 'pnpm', 'bun',
        'shell', 'test', 'logs', 'tunnel',
        // Local project scaffolding & local image builds
        'new', 'docs:new',
        'adonisjs:new', 'astro:new', 'axum:new', 'django:new', 'dotnet:new', 'fastapi:new',
        'gin:new', 'nestjs:new', 'nextjs:new', 'next:new', 'springboot:new', 'statamic:new', 'vite:new', 'wordpress:new', 'wp:new',
        'clone', 'init', 'purge', 'build', 'static:build', 'smoke',
        // Local companions, features & shares
        'companion:add', 'companion:remove', 'companion:start', 'companion:stop', 'companion:update', 'companion:list',
        'feature:add', 'feature:remove',
        'share:show', 'share:domains', 'share:remove',
    ];

    /**
     * Project commands that used to stay on the host for a non-local
     * environment. A project lives in the distro, so they now always run
     * there, for every environment: `up production` builds an image just like
     * `up` does, and the builder, the project and gh are all in the distro.
     * The distro sees the host's servers and clusters through the shared
     * config.json and kubeconfig (see linkSharedHostPaths()).
     *
     * @var list<string>
     */
    private const WSL_PROJECT_COMMANDS = [
        'up', 'down', 'heal', 'start', 'stop', 'reload',
        'about', 'doctor', 'services:show',
        'dotenv', 'dotenv:audit', 'dotenv:push', 'dotenv:pull', 'env:remove',
        'cloud:deploy', 'cloud:configure', 'pipeline:test',
    ];

    /**
     * Commands that always run on the Windows host, even from inside a
     * project folder: they provision with OpenTofu (host-only), manage
     * servers and the cluster rather than a project, or manage this host.
     *
     * @var list<string>
     */
    private const HOST_COMMANDS = [
        'cloud:create', 'cloud:destroy', 'cloud:forget', 'cloud:nuke', 'cloud:scale', 'cloud:repair', 'cloud:harden',
        'cloud:init', 'cloud:init:doks', 'cloud:init:eks', 'cloud:init:gke', 'cloud:migrate',
        'traefik:setup', 'devbox:connect', 'k9s',
        'setup', 'setup:status', 'setup:reinstall', 'update', 'uninstall',
    ];

    /** @var list<string> command prefixes that always run on the host */
    private const HOST_COMMAND_PREFIXES = ['cloud:provision', 'tls:', 'wsl:'];

    private static ?bool $forceNativeWindows = null;

    private static ?bool $forceWslFeatureEnabled = null;

    private ?bool $wslFeatureEnabled = null;

    /**
     * @var (callable(list<string>, ?string=): int)|null Custom process dispatcher for tests
     */
    private $customDispatcher = null;

    private ?string $pendingWindowsHostsPathOverride = null;

    private ?string $windowsHostsPathOverride = null;

    private ?string $cachedLinuxBinaryPathOverride = null;

    private ?string $wslBinaryUncPathOverride = null;

    public function __construct(
        private ?bool $nativeWindows = null,
        private ?string $distro = null,
    ) {}

    public static function setForceWslFeatureEnabled(?bool $force): void
    {
        self::$forceWslFeatureEnabled = $force;
    }

    public static function setForceNativeWindows(?bool $force): void
    {
        self::$forceNativeWindows = $force;
    }

    public static function isNativeWindows(): bool
    {
        return self::$forceNativeWindows ?? (PHP_OS_FAMILY === 'Windows');
    }

    public function isRunningNatively(): bool
    {
        return $this->nativeWindows ?? self::isNativeWindows();
    }

    public function distroName(): string
    {
        return $this->distro ?? (string) (getenv('LARAKUBE_WSL_DISTRO') ?: self::DEFAULT_DISTRO);
    }

    /**
     * Handles wsl.exe UTF-16LE encoding (NUL after every character).
     */
    public function text(string $output): string
    {
        if (str_contains($output, "\0")) {
            $output = (string) mb_convert_encoding($output, 'UTF-8', 'UTF-16LE');
        }

        return str_replace("\u{FEFF}", '', $output);
    }

    /**
     * Checks whether WSL is enabled/installed on Windows without running
     * `wsl.exe` — calling `wsl.exe` on a machine with WSL uninstalled can
     * trigger the interactive Store/install prompt and hang until timeout.
     */
    public function isWslFeatureEnabled(): bool
    {
        if (self::$forceWslFeatureEnabled !== null) {
            return self::$forceWslFeatureEnabled;
        }

        if (! Os::isWindows() && ! self::isNativeWindows()) {
            return true;
        }

        if ($this->wslFeatureEnabled !== null) {
            return $this->wslFeatureEnabled;
        }

        $storeWsl = trim((string) (getenv('ProgramFiles') ?: 'C:\\Program Files'), '\\/').'\\WSL\\wsl.exe';
        if (is_file($storeWsl)) {
            return $this->wslFeatureEnabled = true;
        }

        $reg = Process::timeout(5)->input('')->run([
            'reg.exe', 'query', 'HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Lxss', '/s', '/v', 'DistributionName',
        ]);
        if ($reg->successful() && str_contains($reg->output(), 'DistributionName')) {
            return $this->wslFeatureEnabled = true;
        }

        $feature = Process::timeout(10)->input('')->run([
            'powershell.exe',
            '-NoProfile',
            '-NonInteractive',
            '-Command',
            '(Get-WindowsOptionalFeature -Online -FeatureName Microsoft-Windows-Subsystem-Linux).State',
        ]);

        return $this->wslFeatureEnabled = $feature->successful()
            && strtolower(trim($feature->output())) === 'enabled';
    }

    public function isWslInstalled(): bool
    {
        if (! $this->isWslFeatureEnabled()) {
            return false;
        }

        return Process::timeout(10)->input('')->run(['wsl.exe', '--status'])->successful();
    }

    /**
     * @return list<string>
     */
    public function runningDistros(): array
    {
        if (! $this->isWslFeatureEnabled()) {
            return [];
        }

        $listing = Process::timeout(30)->input('')->run(['wsl.exe', '--list', '--running', '--verbose']);

        if (! $listing->successful()) {
            return [];
        }

        $names = [];
        foreach (preg_split('/\R/', $this->text($listing->output())) ?: [] as $line) {
            if (preg_match('/^\s*\*?\s*(\S+)\s+(Running)\s+([12])\s*$/i', $line, $match) === 1) {
                $names[] = $match[1];
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    public function otherRunningDistros(?string $distro = null): array
    {
        $target = $distro ?? $this->distroName();

        return array_values(array_filter($this->runningDistros(), fn (string $name): bool => $name !== $target));
    }

    public function distroIsRunning(?string $distro = null): bool
    {
        $target = $distro ?? $this->distroName();

        return in_array($target, $this->runningDistros(), true);
    }

    public function distroExists(?string $distro = null): bool
    {
        if (! $this->isWslFeatureEnabled()) {
            return false;
        }

        $target = strtolower($distro ?? $this->distroName());
        $listing = Process::timeout(30)->input('')->run(['wsl.exe', '--list', '--quiet']);

        if (! $listing->successful()) {
            return false;
        }

        $lines = preg_split('/\R/', $this->text($listing->output())) ?: [];
        foreach ($lines as $line) {
            $name = trim($line);
            if ($name !== '' && strtolower($name) === $target) {
                return true;
            }
        }

        return false;
    }

    public function shutdown(): bool
    {
        if (! $this->isWslFeatureEnabled()) {
            return false;
        }

        return Process::timeout(30)->input('')->run(['wsl.exe', '--shutdown'])->successful();
    }

    public function restartForNetworkingChange(?string $distro = null): bool
    {
        if ($this->otherRunningDistros($distro) !== []) {
            return false;
        }

        return $this->shutdown();
    }

    public function userProfilePath(): ?string
    {
        $profile = (string) (getenv('USERPROFILE') ?: (getenv('HOMEDRIVE') && getenv('HOMEPATH') ? getenv('HOMEDRIVE').getenv('HOMEPATH') : ''));

        return $profile !== '' ? $profile : null;
    }

    public function wslConfigPath(): ?string
    {
        $profile = $this->userProfilePath();

        return $profile !== null ? rtrim($profile, '\\/').DIRECTORY_SEPARATOR.'.wslconfig' : null;
    }

    public function mirroredNetworkingRequested(): bool
    {
        $path = $this->wslConfigPath();
        if ($path === null || ! is_file($path)) {
            return false;
        }

        $ini = (string) @file_get_contents($path);

        return preg_match('/^[ \t]*networkingMode[ \t]*=[ \t]*mirrored\b/mi', $ini) === 1;
    }

    public function mirroredNetworkingActive(?string $distro = null): bool
    {
        if (! $this->isWslFeatureEnabled()) {
            return false;
        }

        $target = $distro ?? $this->distroName();
        $result = Process::timeout(30)->input('')->run(['wsl.exe', '-d', $target, '--', 'wslinfo', '--networking-mode']);

        return strtolower(trim($this->text($result->output()))) === 'mirrored';
    }

    public function mirroredRestartPending(?string $distro = null): bool
    {
        return $this->mirroredNetworkingRequested()
            && ! $this->mirroredNetworkingActive($distro);
    }

    public function ensureMirroredNetworking(): bool
    {
        $path = $this->wslConfigPath();
        if ($path === null) {
            return false;
        }

        $current = is_file($path) ? (string) @file_get_contents($path) : '';
        $newContent = $this->withMirroredNetworking($current);

        return @file_put_contents($path, $newContent) !== false;
    }

    /**
     * Determines whether the given CLI invocation should be forwarded into WSL.
     * Only explicit local-development commands (and local-environment runs of
     * project lifecycle commands) dispatch into WSL; all other commands run
     * natively on the Windows host.
     *
     * @param  list<string>  $argv
     */
    public function shouldDispatchToWsl(array $argv, ?string $cwd = null): bool
    {
        if (! $this->isRunningNatively()) {
            return false;
        }

        if (getenv('LARAKUBE_EXECUTION_CONTEXT') === 'host' || getenv('LARAKUBE_NO_WSL') === '1') {
            return false;
        }

        if (in_array('--version', $argv, true) || in_array('-V', $argv, true)) {
            return false;
        }

        $command = $this->extractCommandName($argv);

        if ($command === null) {
            return ($cwd !== null && $this->isWslUncPath($cwd)) || $this->distroExists();
        }

        if ($command === 'version' || $this->isHostCommand($command)) {
            return false;
        }

        if (in_array($command, self::WSL_LOCAL_COMMANDS, true) || in_array($command, self::WSL_PROJECT_COMMANDS, true)) {
            return true;
        }

        // Projects never run outside the distro: anything invoked from inside
        // a project folder runs there, whatever environment it targets.
        return $this->isProjectDirectory($cwd);
    }

    /**
     * Allows tests to intercept process execution.
     *
     * @param  (callable(list<string>, ?string=): int)|null  $dispatcher
     */
    public function setCustomDispatcher(?callable $dispatcher): void
    {
        $this->customDispatcher = $dispatcher;
    }

    /** Test seam: overrides the handoff file applyPendingWindowsHostsSync() reads from. */
    public function setPendingWindowsHostsPathForTesting(?string $path): void
    {
        $this->pendingWindowsHostsPathOverride = $path;
    }

    /** Test seam: overrides the Windows hosts file checked after the elevated sync. */
    public function setWindowsHostsPathForTesting(?string $path): void
    {
        $this->windowsHostsPathOverride = $path;
    }

    /** Test seam: overrides the host-side cached Linux binary path. */
    public function setCachedLinuxBinaryPathForTesting(?string $path): void
    {
        $this->cachedLinuxBinaryPathOverride = $path;
    }

    /** Test seam: overrides the UNC path to /usr/local/bin/larakube inside WSL. */
    public function setWslBinaryUncPathForTesting(?string $path): void
    {
        $this->wslBinaryUncPathOverride = $path;
    }

    /**
     * Host-side path where the matching Linux ELF binary is cached alongside
     * `larakube.exe` (`%USERPROFILE%\.larakube\bin\larakube-linux-x64`), safe
     * from `wsl --unregister` / LaraKube Linux Reset.
     */
    public function cachedLinuxBinaryPath(): ?string
    {
        if ($this->cachedLinuxBinaryPathOverride !== null) {
            return $this->cachedLinuxBinaryPathOverride;
        }

        $profile = $this->userProfilePath();

        return $profile !== null
            ? rtrim($profile, '\\/').DIRECTORY_SEPARATOR.'.larakube'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'larakube-linux-x64'
            : null;
    }

    /**
     * UNC path to `/usr/local/bin/larakube` inside the target WSL distro.
     */
    public function wslBinaryUncPath(): string
    {
        return $this->wslBinaryUncPathOverride
            ?? '\\\\wsl.localhost\\'.$this->distroName().'\\usr\\local\\bin\\larakube';
    }

    /**
     * Self-healing pre-dispatch sync: if the Windows host has a cached
     * `larakube-linux-x64` binary and `/usr/local/bin/larakube` inside WSL is
     * missing (e.g. after a "LaraKube Linux Reset") or out of sync with the
     * host binary, copy it into WSL as root before dispatching.
     */
    public function ensureWslBinarySynced(): void
    {
        $linuxCache = $this->cachedLinuxBinaryPath();
        if ($linuxCache === null || ! is_file($linuxCache)) {
            return;
        }

        $wslBinUnc = $this->wslBinaryUncPath();
        if (
            is_file($wslBinUnc)
            && @filesize($wslBinUnc) === @filesize($linuxCache)
            && @filemtime($wslBinUnc) === @filemtime($linuxCache)
        ) {
            return;
        }

        $this->syncLinuxBinaryToWsl($linuxCache);
    }

    /**
     * Install the host-cached Linux binary into `/usr/local/bin/larakube`
     * inside the target WSL distro as root, preserving timestamps (`-p`) so
     * subsequent `ensureWslBinarySynced()` checks hit the fast path.
     */
    public function syncLinuxBinaryToWsl(?string $linuxBinaryPath = null): bool
    {
        $source = $linuxBinaryPath ?? $this->cachedLinuxBinaryPath();
        if ($source === null || ! is_file($source)) {
            return false;
        }

        $wslSource = $this->translateCwdToWsl($source) ?? (str_starts_with($source, '/') ? $source : null);
        if ($wslSource === null) {
            return false;
        }

        return Process::timeout(30)->run([
            'wsl.exe', '-d', $this->distroName(), '--user', 'root',
            '--', 'install', '-p', '-m', '0755', $wslSource, '/usr/local/bin/larakube',
        ])->successful();
    }

    /**
     * Dispatches command invocation to WSL2 distro.
     *
     * @param  list<string>  $argv
     */
    public function dispatchToWsl(array $argv, ?string $cwd = null): int
    {
        $this->ensureWslBinarySynced();

        $args = array_slice($argv, 1);

        // Without this, project-aware commands (`up`, `trust`, ...) always land
        // in the distro's default home directory, not wherever the invoking
        // Windows terminal actually was — confirmed live: dispatching `up`
        // from a native PowerShell window with no --cd at all silently fails
        // isLaraKubeProject() even with a real project sitting one level down
        // (project files live under ~/projects/<name>, never directly at ~ —
        // see ToolLocator::defaultProjectsPath() on the Desktop side). Falls
        // back to today's behavior (no --cd, lands at home) when the cwd isn't
        // a translatable WSL/Windows path — e.g. a plain Windows path with no
        // drive letter, which shouldn't happen but isn't worth guessing at.
        $linuxCwd = $this->translateCwdToWsl($cwd ?? (getcwd() ?: null));

        // --user larakube is required: the distro's default login user has no
        // systemd lingering entry (only /var/lib/systemd/linger/larakube does,
        // per larakube-ubuntu's own Dockerfile), so running as whichever user
        // the distro happens to default to fails the systemd user session
        // outright ("Failed to start the systemd user session for '<user>'").
        //
        // `env LARAKUBE_NATIVE_DISPATCH=1` tells the dispatched process it's
        // being driven by a native-Windows host that's still waiting on it —
        // see InteractsWithHosts::syncWindowsHostsFile() and
        // applyPendingWindowsHostsSync() below for what that unlocks.
        //
        // LARAKUBE_HOST_KUBE_DIR / LARAKUBE_HOST_CONFIG hand the dispatched
        // process the Windows kubeconfig folder and global config file, so it
        // keeps its own ~/.kube and ~/.larakube/config.json linked to them —
        // see linkSharedHostPaths().
        $shared = $this->sharedHostPathsForWsl();

        $command = [
            'wsl.exe', '-d', $this->distroName(), '--user', self::DEFAULT_USER,
            ...($linuxCwd !== null ? ['--cd', $linuxCwd] : []),
            '--', 'env', 'LARAKUBE_NATIVE_DISPATCH=1',
            ...array_map(fn (string $name, string $path): string => "{$name}={$path}", array_keys($shared), $shared),
            'larakube', ...$args,
        ];

        if ($this->customDispatcher !== null) {
            return ($this->customDispatcher)($command, $cwd);
        }

        $descriptors = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];

        // Confirmed live on real Windows hardware, with this exact argv: wsl.exe,
        // the larakube user, and the distro are all healthy -- PowerShell typing
        // it directly and .NET's Start-Process (bare 'wsl.exe' AND the full
        // System32 path) both ran it correctly. Only proc_open() with the array
        // form failed, every time, despite the array content being verified
        // identical. Per PHP's own docs, an array command on Windows is already
        // shell-free, with PHP handling the argument escaping internally — that
        // internal escaping is what broke, not anything bypass_shell controls.
        // Building the command line ourselves removes PHP's own escaping from
        // the equation. None of this command's own values need quoting (no
        // spaces/quotes in any of them), but windowsQuoteArgument() stays
        // correct if a future argument ever does.
        $commandLine = implode(' ', array_map($this->windowsQuoteArgument(...), $command));

        if (getenv('LARAKUBE_DEBUG_DISPATCH') !== false) {
            fwrite(STDERR, '[dispatch debug] PHP_VERSION = '.PHP_VERSION.PHP_EOL);
            fwrite(STDERR, '[dispatch debug] command line = '.$commandLine.PHP_EOL);
        }

        // $cwd is already translated into --cd $linuxCwd for wsl.exe above;
        // never hand a \\wsl.localhost UNC path to CreateProcessW's
        // lpCurrentDirectory, which fails if the 9P share is offline after a
        // shutdown.
        $procCwd = ($cwd !== null && ! str_starts_with($cwd, '\\\\') && is_dir($cwd))
            ? $cwd
            : null;

        $process = @proc_open($commandLine, $descriptors, $pipes, $procCwd, null, ['bypass_shell' => true]);

        if (getenv('LARAKUBE_DEBUG_DISPATCH') !== false) {
            fwrite(STDERR, '[dispatch debug] proc_open returned: '.(is_resource($process) ? 'resource' : var_export($process, true)).PHP_EOL);
        }

        $status = is_resource($process) ? proc_close($process) : 1;

        $this->applyPendingWindowsHostsSync();

        return $status;
    }

    /**
     * Native-Windows counterpart to InteractsWithHosts::syncWindowsHostsFile().
     * When dispatchToWsl() marks the dispatched process with
     * LARAKUBE_NATIVE_DISPATCH=1, that WSL-side sync queues its merged
     * content into a handoff file instead of attempting its own two-hop
     * self-elevation (unreliable — a UAC prompt spawned at the end of a
     * WSL-interop-then-elevate chain doesn't reliably attach to the
     * interactive desktop session; confirmed live during this session's own
     * Windows testing). This runs right after dispatchToWsl()'s real process
     * exits, reads that file via its \\wsl.localhost UNC path (a plain,
     * non-elevated read — no WSL interop bridging needed, since we're
     * already native Windows), and applies it with one direct elevation
     * hop. No-op when nothing queued a sync.
     */
    public function applyPendingWindowsHostsSync(): void
    {
        $uncPath = $this->pendingWindowsHostsUncPath();

        if (! is_file($uncPath)) {
            return;
        }

        $content = (string) @file_get_contents($uncPath);
        @unlink($uncPath);

        if (trim($content) === '') {
            return;
        }

        $tempDir = sys_get_temp_dir();
        $contentPath = $tempDir.DIRECTORY_SEPARATOR.'larakube-win-hosts-'.bin2hex(random_bytes(6));
        $scriptPath = $tempDir.DIRECTORY_SEPARATOR.'larakube-win-hosts-'.bin2hex(random_bytes(6)).'.ps1';

        file_put_contents($contentPath, $content);
        file_put_contents(
            $scriptPath,
            "Copy-Item -LiteralPath '{$contentPath}' -Destination 'C:\\Windows\\System32\\drivers\\etc\\hosts' -Force\n"
            ."Get-DnsClientNrptRule -ErrorAction SilentlyContinue | Where-Object { \$_.NameServers -contains '127.0.0.1' } | Remove-DnsClientNrptRule -Force -ErrorAction SilentlyContinue\n"
            ."Clear-DnsClientCache -ErrorAction SilentlyContinue\n",
        );

        fwrite(STDERR, 'Windows hosts file needs a one-time update -- look for a Windows security prompt (it may be behind this window or on another monitor)...'.PHP_EOL);

        $startProcess = 'Start-Process -FilePath powershell -Verb RunAs -Wait '
            ."-ArgumentList '-NoProfile','-ExecutionPolicy','Bypass','-File','{$scriptPath}'";

        // The app is already up by now, so nothing here may fail the command:
        // a prompt left unanswered or declined ends in the warning below.
        try {
            Process::timeout(self::WINDOWS_HOSTS_PROMPT_SECONDS)->run(['powershell.exe', '-NoProfile', '-Command', $startProcess]);
        } catch (ProcessTimedOutException) {
            fwrite(STDERR, 'The Windows security prompt was not answered in time.'.PHP_EOL);
        } finally {
            @unlink($contentPath);
            @unlink($scriptPath);
        }

        $missing = $this->linesMissingFromWindowsHosts($content);

        if ($missing !== []) {
            fwrite(STDERR, 'The Windows hosts file was not updated, so these addresses may not open in your Windows browser. Add these lines to C:\\Windows\\System32\\drivers\\etc\\hosts (open Notepad as Administrator):'.PHP_EOL);
            fwrite(STDERR, implode(PHP_EOL, $missing).PHP_EOL);
        }
    }

    /**
     * Inside the distro, keep $distroPath a symlink to the Windows host's
     * $hostPath (a `/mnt/<drive>/...` path), so both sides read and write ONE
     * copy: `~/.kube` (kubectl.exe and the distro's kubectl share one
     * kubeconfig; the local k3s context at `https://127.0.0.1:6443` works from
     * both sides through WSL2 localhost forwarding) and
     * `~/.larakube/config.json` (servers, accounts, tokens, passphrases).
     *
     * A real file or directory already at $distroPath (kubectl makes ~/.kube
     * for its cache on any run) is moved aside to `<name>.distro`, never
     * deleted. Idempotent: a correct link is left untouched. Does nothing when
     * the host side is missing, so there is never a dangling link.
     */
    public static function linkToHost(string $hostPath, string $distroPath): bool
    {
        if (! file_exists($hostPath)) {
            return false;
        }

        if (is_link($distroPath)) {
            if (readlink($distroPath) === $hostPath) {
                return true;
            }

            @unlink($distroPath);
        } elseif (file_exists($distroPath)) {
            $aside = $distroPath.'.distro';
            $suffix = 1;

            while (file_exists($aside)) {
                $aside = $distroPath.'.distro.'.$suffix++;
            }

            if (! @rename($distroPath, $aside)) {
                return false;
            }
        }

        if (! is_dir(dirname($distroPath))) {
            @mkdir(dirname($distroPath), 0700, true);
        }

        return @symlink($hostPath, $distroPath);
    }

    /**
     * Link everything the dispatching Windows host shares with the distro,
     * from the environment dispatchToWsl() set.
     */
    public static function linkSharedHostPaths(string $home): void
    {
        $home = rtrim($home, '/');

        if ($home === '') {
            return;
        }

        $shared = [
            'LARAKUBE_HOST_KUBE_DIR' => $home.'/.kube',
            'LARAKUBE_HOST_CONFIG' => $home.'/.larakube/config.json',
            // One cloud login for both sides: `aws eks get-token` and
            // gke-gcloud-auth-plugin (EKS/GKE kubeconfigs) and ECR/GAR
            // registry logins run in the distro for project commands.
            'LARAKUBE_HOST_AWS_DIR' => $home.'/.aws',
            'LARAKUBE_HOST_GCLOUD_DIR' => $home.'/.config/gcloud',
        ];

        foreach ($shared as $variable => $distroPath) {
            $hostPath = getenv($variable);

            if (is_string($hostPath) && $hostPath !== '') {
                self::linkToHost($hostPath, $distroPath);
            }
        }
    }

    /**
     * The lines of $wanted the real Windows hosts file does not contain.
     * Comments are left out: they change nothing about what resolves.
     *
     * @return list<string>
     */
    private function linesMissingFromWindowsHosts(string $wanted): array
    {
        $lines = fn (string $text): array => array_values(array_filter(
            array_map(trim(...), preg_split('/\r\n|\r|\n/', $text) ?: []),
            fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
        ));

        $current = (string) @file_get_contents($this->windowsHostsPathOverride ?? 'C:\\Windows\\System32\\drivers\\etc\\hosts');

        return array_values(array_diff($lines($wanted), $lines($current)));
    }

    private function isHostCommand(string $command): bool
    {
        if (in_array($command, self::HOST_COMMANDS, true)) {
            return true;
        }

        foreach (self::HOST_COMMAND_PREFIXES as $prefix) {
            if (str_starts_with($command, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** The same test InteractsWithProjectConfig::isLaraKubeProject() applies: `.larakube.json` right in the folder. */
    private function isProjectDirectory(?string $cwd): bool
    {
        return $cwd !== null && $cwd !== '' && is_file(rtrim($cwd, '\\/').'/'.ConfigData::CONFIG_FILE);
    }

    /**
     * Extracts the command name (first non-option argument after the binary).
     *
     * @param  list<string>  $argv
     */
    private function extractCommandName(array $argv): ?string
    {
        $count = count($argv);
        for ($i = 1; $i < $count; $i++) {
            $arg = $argv[$i];
            if (! str_starts_with($arg, '-')) {
                return $arg;
            }
        }

        return null;
    }

    /**
     * Returns true when a working directory points at a WSL UNC share.
     */
    private function isWslUncPath(string $cwd): bool
    {
        return str_starts_with($cwd, '\\\\wsl.localhost\\') || str_starts_with($cwd, '\\\\wsl$\\');
    }

    /**
     * Where applyPendingWindowsHostsSync() looks for a handoff file written
     * by InteractsWithHosts::queuePendingWindowsHostsSync() inside WSL —
     * always under the pinned --user larakube's home (see dispatchToWsl()),
     * so this never needs to guess which user's home the distro defaults to.
     */
    private function pendingWindowsHostsUncPath(): string
    {
        return $this->pendingWindowsHostsPathOverride
            ?? '\\\\wsl.localhost\\'.$this->distroName().'\\home\\'.self::DEFAULT_USER.'\\.larakube\\pending-windows-hosts';
    }

    /**
     * Translates a native-Windows working directory into the equivalent path
     * inside WSL, so dispatchToWsl() can pass --cd and land the dispatched
     * command where the invoking terminal actually was. Mirrors Desktop's
     * own WslDistro::toLinux() (desktop/app/Services/Runtime/WslDistro.php) —
     * not reused directly since this is a different repo, but intentionally
     * the same two cases: a \\wsl.localhost\<distro>\... (or legacy \\wsl$\)
     * UNC path drops its prefix, and a Windows drive path becomes
     * /mnt/<drive>/.... Returns null for anything else (already a Linux
     * path, a UNC path into a DIFFERENT distro, or unrecognized) rather than
     * guessing — callers fall back to no --cd at all in that case.
     */
    /**
     * The Windows `%USERPROFILE%\.kube` folder, `.larakube\config.json`,
     * `.aws` and `%APPDATA%\gcloud` as `/mnt/<drive>/...` paths, created on the host first (an empty `{}`
     * config) so the distro always has something real to link to. Empty when
     * the profile is unknown or not on a drive letter.
     *
     * @return array<string, string>
     */
    private function sharedHostPathsForWsl(): array
    {
        $profile = $this->userProfilePath();

        if ($profile === null) {
            return [];
        }

        $profile = rtrim($profile, '\\/');
        $kubeDir = $profile.'\\.kube';
        $configDir = $profile.'\\.larakube';
        $configFile = $configDir.'\\config.json';
        $awsDir = $profile.'\\.aws';
        $appData = rtrim((string) (getenv('APPDATA') ?: $profile.'\\AppData\\Roaming'), '\\/');
        $gcloudDir = $appData.'\\gcloud';

        if (PHP_OS_FAMILY === 'Windows') {
            foreach ([$kubeDir, $configDir, $awsDir, $gcloudDir] as $dir) {
                if (! is_dir($dir)) {
                    @mkdir($dir, 0700, true);
                }
            }

            if (! is_file($configFile)) {
                @file_put_contents($configFile, '{}');
            }
        }

        return array_filter([
            'LARAKUBE_HOST_KUBE_DIR' => $this->translateCwdToWsl($kubeDir),
            'LARAKUBE_HOST_CONFIG' => $this->translateCwdToWsl($configFile),
            'LARAKUBE_HOST_AWS_DIR' => $this->translateCwdToWsl($awsDir),
            'LARAKUBE_HOST_GCLOUD_DIR' => $this->translateCwdToWsl($gcloudDir),
        ], fn (?string $path): bool => $path !== null);
    }

    private function translateCwdToWsl(?string $cwd): ?string
    {
        if ($cwd === null || $cwd === '') {
            return null;
        }

        if (preg_match('#^\\\\\\\\(?:wsl\.localhost|wsl\$)\\\\([^\\\\]+)(.*)$#i', $cwd, $unc) === 1) {
            if (strcasecmp($unc[1], $this->distroName()) !== 0) {
                return null;
            }

            return str_replace('\\', '/', $unc[2]) ?: '/';
        }

        if (preg_match('#^([A-Za-z]):[\\\\/]?(.*)$#', $cwd, $drive) === 1) {
            $rest = trim(str_replace('\\', '/', $drive[2]), '/');

            return '/mnt/'.strtolower($drive[1]).($rest !== '' ? '/'.$rest : '');
        }

        return null;
    }

    /**
     * Quotes a single argument for a Windows command line, using the same
     * backslash/quote-doubling rules the MSVCRT argv parser (and therefore
     * wsl.exe) expects. Only wraps in quotes when actually needed, so a
     * plain token like 'larakube-ubuntu' passes through unchanged.
     */
    private function windowsQuoteArgument(string $arg): string
    {
        if ($arg !== '' && preg_match('/[\s"]/', $arg) !== 1) {
            return $arg;
        }

        $escaped = (string) preg_replace('/(\\\\*)"/', '$1$1\\"', $arg);
        $escaped = (string) preg_replace('/(\\\\+)$/', '$1$1', $escaped);

        return '"'.$escaped.'"';
    }
}
