<?php

namespace App\Services;

use App\Data\GlobalConfigData;
use App\Traits\ConfiguresWslNetworking;
use Illuminate\Support\Facades\Process;

class WslBridge
{
    use ConfiguresWslNetworking;

    public const string DEFAULT_DISTRO = 'larakube-ubuntu';

    public const string DEFAULT_USER = 'larakube';

    private static ?bool $forceNativeWindows = null;

    /**
     * @var (callable(list<string>, ?string=): int)|null Custom process dispatcher for tests
     */
    private $customDispatcher = null;

    private ?string $pendingWindowsHostsPathOverride = null;

    public function __construct(
        private ?bool $nativeWindows = null,
        private ?string $distro = null,
    ) {}

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

    public function isWslInstalled(): bool
    {
        return Process::timeout(10)->run(['wsl.exe', '--status'])->successful();
    }

    /**
     * @return list<string>
     */
    public function runningDistros(): array
    {
        $listing = Process::timeout(30)->run(['wsl.exe', '--list', '--running', '--verbose']);

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
        $target = strtolower($distro ?? $this->distroName());
        $listing = Process::timeout(30)->run(['wsl.exe', '--list', '--quiet']);

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
        return Process::timeout(30)->run(['wsl.exe', '--shutdown'])->successful();
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
        $target = $distro ?? $this->distroName();
        $result = Process::timeout(30)->run(['wsl.exe', '-d', $target, '--', 'wslinfo', '--networking-mode']);

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
     * Lifecycle management commands (setup, update, wsl:*) remain on the Windows host.
     *
     * @param  list<string>  $argv
     */
    public function shouldDispatchToWsl(array $argv): bool
    {
        if (! $this->isRunningNatively()) {
            return false;
        }

        // Find the command (first non-option argument after script name)
        $command = null;
        $count = count($argv);
        for ($i = 1; $i < $count; $i++) {
            $arg = $argv[$i];
            if (! str_starts_with($arg, '-')) {
                $command = $arg;
                break;
            }
        }

        if ($command === null) {
            // Version check on the host binary
            foreach ($argv as $arg) {
                if ($arg === '--version' || $arg === '-V') {
                    return false;
                }
            }

            // If no command is provided, forward to WSL if distro exists so full help/list is displayed
            return $this->distroExists();
        }

        // Native lifecycle commands run outside WSL
        if (in_array($command, ['setup', 'update'], true) || str_starts_with($command, 'wsl:')) {
            return false;
        }

        return true;
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

    /**
     * Dispatches command invocation to WSL2 distro.
     *
     * @param  list<string>  $argv
     */
    public function dispatchToWsl(array $argv, ?string $cwd = null): int
    {
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
        $command = [
            'wsl.exe', '-d', $this->distroName(), '--user', self::DEFAULT_USER,
            ...($linuxCwd !== null ? ['--cd', $linuxCwd] : []),
            '--', 'env', 'LARAKUBE_NATIVE_DISPATCH=1', 'larakube', ...$args,
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
     * Every TLD namespace an existing Windows NRPT rule already routes to a
     * nameserver — a rule from a previous run or set up by hand is still
     * recognised as "covered" and left alone.
     *
     * Native-Windows counterpart to InteractsWithTrust::windowsNrptCoveredTlds()
     * (CLI/app/Traits/InteractsWithTrust.php) — that one bridges out from
     * inside WSL via windowsCommand()'s interop path; running natively, this
     * calls powershell.exe directly, no bridge needed.
     *
     * @return list<string>
     */
    public function windowsNrptCoveredTlds(): array
    {
        $output = trim(Process::run(
            'powershell.exe -NoProfile -Command "Get-DnsClientNrptRule | Select-Object -ExpandProperty Namespace | ConvertTo-Json -Compress"',
        )->output());

        if ($output === '') {
            return [];
        }

        $decoded = json_decode($output, true);
        $namespaces = is_array($decoded) ? $decoded : [$decoded];

        return array_values(array_filter(array_map(
            fn (mixed $ns): string => is_string($ns) ? strtolower(ltrim($ns, '.')) : '',
            $namespaces,
        )));
    }

    /**
     * Add a Windows NRPT rule for each given TLD via a directly-elevated
     * PowerShell (the standard UAC prompt) — one hop, since we're already
     * native Windows. The WSL-side equivalent (applyWindowsNrptRule() in
     * InteractsWithTrust) needs two: bridge out to a non-elevated
     * powershell.exe via WSL interop, which then requests elevation for a
     * second, child powershell.exe — a UAC prompt spawned at the end of
     * that chain doesn't reliably attach to the interactive desktop
     * session. Confirmed live: that path silently failed with no visible
     * prompt during this session's own Windows testing.
     *
     * @param  list<string>  $tlds
     */
    public function applyNrptRule(array $tlds): bool
    {
        $tempDir = sys_get_temp_dir();
        $scriptPath = $tempDir.DIRECTORY_SEPARATOR.'larakube-nrpt-'.bin2hex(random_bytes(6)).'.ps1';

        $lines = array_map(
            fn (string $tld): string => "Add-DnsClientNrptRule -Namespace \".{$tld}\" -NameServers \"127.0.0.1\"",
            $tlds,
        );
        file_put_contents($scriptPath, implode("\n", $lines)."\n");

        $startProcess = 'Start-Process -FilePath powershell -Verb RunAs -Wait '
            ."-ArgumentList '-NoProfile','-ExecutionPolicy','Bypass','-File','{$scriptPath}'";

        $ok = Process::run(['powershell.exe', '-NoProfile', '-Command', $startProcess])->successful();

        @unlink($scriptPath);

        if (! $ok) {
            return false;
        }

        // Trust, but verify: a declined/dismissed UAC prompt still exits the
        // outer powershell.exe "successfully" — only re-querying the actual
        // rule table confirms the rule exists now.
        return array_diff(array_map('strtolower', $tlds), $this->windowsNrptCoveredTlds()) === [];
    }

    /**
     * Applies the Windows NRPT rule for the globally configured TLD before
     * dispatching into WSL — generalizes SetupCommand::handleWindowsSetup()'s
     * own native, one-hop check (which only ran for `setup`) to every
     * dispatched command. trust/up/hosts all reach the WSL-side
     * InteractsWithTrust::ensureWindowsNrptRule() too (via
     * configureDnsmasq()), which still uses the old two-hop bridge
     * confirmed live to silently drop the UAC prompt — this makes that
     * WSL-side call a no-op in practice by the time it runs. Cheap once
     * already covered: a single PowerShell query, no elevation attempted.
     */
    public function ensureNrptRuleForLocalTld(): void
    {
        $tld = strtolower(GlobalConfigData::load()->getLocalTld());

        if (in_array($tld, $this->windowsNrptCoveredTlds(), true)) {
            return;
        }

        fwrite(STDERR, "Windows DNS needs a one-time rule so your browser resolves *.{$tld} via dnsmasq.".PHP_EOL);
        fwrite(STDERR, 'Look for a Windows security prompt (it may be behind this window or on another monitor)...'.PHP_EOL);

        if ($this->applyNrptRule([$tld])) {
            fwrite(STDERR, "Windows DNS rule added: *.{$tld} -> 127.0.0.1 (dnsmasq).".PHP_EOL);
        } else {
            fwrite(STDERR, 'Could not add the Windows DNS rule automatically. From an elevated PowerShell, run:'.PHP_EOL);
            fwrite(STDERR, "  Add-DnsClientNrptRule -Namespace \".{$tld}\" -NameServers \"127.0.0.1\"".PHP_EOL);
        }
    }

    /**
     * Native-Windows counterpart to InteractsWithHosts::syncWindowsHostsFile().
     * When dispatchToWsl() marks the dispatched process with
     * LARAKUBE_NATIVE_DISPATCH=1, that WSL-side sync queues its merged
     * content into a handoff file instead of attempting its own two-hop
     * self-elevation (unreliable for the same reason applyNrptRule() exists
     * — see its docblock). This runs right after dispatchToWsl()'s real
     * process exits, reads that file via its \\wsl.localhost UNC path (a
     * plain, non-elevated read — no WSL interop bridging needed, since
     * we're already native Windows), and applies it with one direct
     * elevation hop, same shape as applyNrptRule(). No-op when nothing
     * queued a sync.
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
            "Copy-Item -LiteralPath '{$contentPath}' -Destination 'C:\\Windows\\System32\\drivers\\etc\\hosts' -Force\n",
        );

        fwrite(STDERR, 'Windows hosts file needs a one-time update -- look for a Windows security prompt (it may be behind this window or on another monitor)...'.PHP_EOL);

        $startProcess = 'Start-Process -FilePath powershell -Verb RunAs -Wait '
            ."-ArgumentList '-NoProfile','-ExecutionPolicy','Bypass','-File','{$scriptPath}'";

        Process::run(['powershell.exe', '-NoProfile', '-Command', $startProcess]);

        @unlink($contentPath);
        @unlink($scriptPath);
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
