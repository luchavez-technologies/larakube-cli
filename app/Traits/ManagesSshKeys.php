<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

/**
 * Local SSH conveniences for provisioning flows: mint a key pair when the
 * user has none, and keep ~/.ssh/config pointing at provisioned hosts so
 * `ssh <stack-name>` works without the user copying IPs around.
 */
trait ManagesSshKeys
{
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
