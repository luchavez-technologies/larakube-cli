<?php

namespace App\Traits;

use App\Data\GlobalConfigData;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Local SSH conveniences for provisioning flows: mint a key pair when the
 * user has none, and keep ~/.ssh/config pointing at provisioned hosts so
 * `ssh <stack-name>` works without the user copying IPs around.
 */
trait ManagesSshKeys
{
    /**
     * Resolve SSH connection details (user, port, key path, host alias) for a given
     * host alias, IP, or kube-context (e.g. "larakube-34.27.253.31"). Checks
     * ~/.ssh/config first (matching Host or HostName), then optionally falls back
     * to registered global stacks.
     *
     * @return array{user: string, port: int, key: string, host: ?string}|null
     */
    public function resolveSshDetails(string $hostOrIp, ?string $context = null): ?array
    {
        $target = trim($hostOrIp);
        $contextIp = null;
        if (str_starts_with($target, 'larakube-')) {
            $contextIp = substr($target, 9);
            $target = $contextIp;
        } elseif ($context !== null && str_starts_with($context, 'larakube-')) {
            $contextIp = substr($context, 9);
        }

        // 1. Try to find a matching stack name from global config if available
        $stackName = null;
        $stackKey = null;
        if (class_exists(GlobalConfigData::class)) {
            try {
                $global = GlobalConfigData::load();
                foreach ($global->getStacks() as $stack) {
                    if (
                        $stack->name === $target
                        || ($stack->ip !== null && ($stack->ip === $target || $stack->ip === $contextIp))
                        || ($stack->context !== null && ($stack->context === $hostOrIp || $stack->context === $context))
                    ) {
                        $stackName = $stack->name;
                        if (! empty($stack->sshKey) && file_exists(str_replace('~', home_path(), $stack->sshKey))) {
                            $stackKey = str_replace('~', home_path(), $stack->sshKey);
                        }
                        break;
                    }
                }
            } catch (Throwable) {
                // Global config not readable or not initialized
            }
        }

        // 2. Parse ~/.ssh/config
        $sshConfigFile = home_path('.ssh/config');
        if (file_exists($sshConfigFile)) {
            $content = (string) file_get_contents($sshConfigFile);
            $blocks = preg_split('/(?=^[ \t]*Host\s+)/mi', $content) ?: [];

            foreach ($blocks as $block) {
                if (! preg_match('/^[ \t]*Host\s+(.+)$/m', $block, $hm)) {
                    continue;
                }

                $hostPatterns = preg_split('/\s+/', trim($hm[1])) ?: [];
                $hostname = preg_match('/^[ \t]*HostName\s+(.+)$/mi', $block, $m) ? trim($m[1]) : null;
                $user = preg_match('/^[ \t]*User\s+(.+)$/mi', $block, $m) ? trim($m[1]) : null;
                $port = preg_match('/^[ \t]*Port\s+(\d+)$/mi', $block, $m) ? (int) $m[1] : null;
                $key = preg_match('/^[ \t]*IdentityFile\s+(.+)$/mi', $block, $m) ? trim($m[1]) : null;

                $matches = false;

                // Match against Host patterns
                foreach ($hostPatterns as $pattern) {
                    if ($pattern === '*' || $pattern === '!*') {
                        continue;
                    }
                    if (
                        $pattern === $target
                        || ($contextIp !== null && $pattern === $contextIp)
                        || ($stackName !== null && $pattern === $stackName)
                        || fnmatch($pattern, $target)
                    ) {
                        $matches = true;
                        break;
                    }
                }

                // Match against HostName
                if (! $matches && $hostname !== null) {
                    if (
                        $hostname === $target
                        || ($contextIp !== null && $hostname === $contextIp)
                    ) {
                        $matches = true;
                    }
                }

                if ($matches && $key !== null) {
                    $key = str_replace('~', home_path(), $key);
                    if (file_exists($key)) {
                        return [
                            'user' => $user ?: 'larakube',
                            'port' => $port ?: 22,
                            'key' => $key,
                            'host' => $hostPatterns[0] ?? null,
                        ];
                    }
                }
            }
        }

        // 3. If stack had an explicit sshKey and it exists
        if ($stackKey !== null) {
            return [
                'user' => 'larakube',
                'port' => 22,
                'key' => $stackKey,
                'host' => $stackName,
            ];
        }

        return null;
    }

    /**
     * Test whether an SSH connection with key authentication succeeds (exit 0).
     */
    public function testSshConnection(string $user, string $ip, int $port, string $key, int $timeoutSeconds = 5): bool
    {
        $cmd = 'ssh -o StrictHostKeyChecking=no -o BatchMode=yes -o ConnectTimeout='
            .$timeoutSeconds.' -i '.escapeshellarg($key).' -p '.$port.' '.escapeshellarg($user.'@'.$ip).' true';

        return Process::run($cmd)->successful();
    }

    /**
     * Generate a passphrase-less ED25519 key pair at $keyPath (plus .pub),
     * creating the containing directory (0700) if needed.
     */
    protected function generateSshKey(string $keyPath): bool
    {
        $dir = dirname($keyPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $result = Process::run('ssh-keygen -t ed25519 -N "" -C larakube -f '.escapeshellarg($keyPath));

        if ($result->failed() || ! file_exists($keyPath)) {
            $this->laraKubeError("Could not generate an SSH key at {$keyPath} (is ssh-keygen installed?).");

            return false;
        }

        @chmod($keyPath, 0600);
        $this->laraKubeInfo("Generated a new ED25519 key pair at <fg=cyan>{$keyPath}</>.");

        return true;
    }

    /**
     * Add or update a Host block in ~/.ssh/config so `ssh <host>` reaches the
     * provisioned machine. Replaces an existing block with the same alias
     * (re-provisioning a stack updates its IP) and appends otherwise; the
     * rest of the file is left untouched.
     */
    protected function upsertSshConfigHost(string $host, string $hostName, string $user, string $port, string $identityFile): void
    {
        $dir = home_path('.ssh');
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $file = $dir.'/config';

        $block = "Host {$host}\n"
            ."    HostName {$hostName}\n"
            ."    User {$user}\n"
            ."    Port {$port}\n"
            ."    IdentityFile {$identityFile}\n"
            ."    IdentitiesOnly yes\n";

        $existing = file_exists($file) ? (string) file_get_contents($file) : '';

        // One "Host <alias>" line plus its indented option lines.
        $pattern = '/^Host[ \t]+'.preg_quote($host, '/').'[ \t]*\R(?:[ \t]+.*\R?)*/m';

        $updated = ($existing !== '' && preg_match($pattern, $existing))
            ? (string) preg_replace($pattern, $block, $existing, 1)
            : ($existing === '' ? $block : rtrim($existing)."\n\n".$block);

        file_put_contents($file, $updated);
        @chmod($file, 0600);

        $this->laraKubeInfo("Updated ~/.ssh/config — connect with <fg=cyan>ssh {$host}</>.");
    }
}
