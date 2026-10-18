<?php

namespace App\Services;

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

    /**
     * Dispatches command invocation to WSL2 distro.
     *
     * @param  list<string>  $argv
     */
    public function dispatchToWsl(array $argv, ?string $cwd = null): int
    {
        $args = array_slice($argv, 1);
        // --user larakube is required: the distro's default login user has no
        // systemd lingering entry (only /var/lib/systemd/linger/larakube does,
        // per larakube-ubuntu's own Dockerfile), so running as whichever user
        // the distro happens to default to fails the systemd user session
        // outright ("Failed to start the systemd user session for '<user>'").
        $command = ['wsl.exe', '-d', $this->distroName(), '--user', self::DEFAULT_USER, '--', 'larakube', ...$args];

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

        $process = @proc_open($commandLine, $descriptors, $pipes, $cwd, null, ['bypass_shell' => true]);

        if (getenv('LARAKUBE_DEBUG_DISPATCH') !== false) {
            fwrite(STDERR, '[dispatch debug] proc_open returned: '.(is_resource($process) ? 'resource' : var_export($process, true)).PHP_EOL);
        }

        if (is_resource($process)) {
            return proc_close($process);
        }

        return 1;
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
