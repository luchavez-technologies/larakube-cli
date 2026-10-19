<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class SshKeyInspector
{
    /**
     * Inspect an SSH private key for passphrase protection and agent registration.
     *
     * @return array{encrypted: bool, in_agent: bool}
     */
    public function inspect(string $path): array
    {
        $resolved = $this->resolvePath($path);
        if (! file_exists($resolved) || is_dir($resolved)) {
            return ['encrypted' => false, 'in_agent' => false];
        }

        $encrypted = $this->isEncrypted($resolved);
        $inAgent = $encrypted ? $this->isInAgent($resolved) : true;

        return [
            'encrypted' => $encrypted,
            'in_agent' => $inAgent,
        ];
    }

    /**
     * Check if the private key file is passphrase-protected.
     */
    public function isEncrypted(string $path): bool
    {
        $resolved = $this->resolvePath($path);
        $content = @file_get_contents($resolved);
        if ($content === false) {
            return false;
        }

        // 1. OpenSSH format (modern keys)
        if (str_contains($content, '-----BEGIN OPENSSH PRIVATE KEY-----')) {
            $clean = preg_replace('/-----[^-]+-----|\s+/', '', $content);
            if ($clean !== null) {
                $bin = base64_decode($clean, true);
                if ($bin !== false && str_starts_with($bin, "openssh-key-v1\0")) {
                    $offset = 15;
                    if (strlen($bin) >= $offset + 4) {
                        $unpacked = unpack('N', substr($bin, $offset, 4));
                        $cipherLen = $unpacked[1] ?? 0;
                        if (strlen($bin) >= $offset + 4 + $cipherLen) {
                            $cipher = substr($bin, $offset + 4, $cipherLen);

                            return $cipher !== 'none';
                        }
                    }
                }
            }
        }

        // 2. Traditional PEM format
        if (str_contains($content, 'Proc-Type: 4,ENCRYPTED')) {
            return true;
        }

        // 3. PKCS#8 format
        if (str_contains($content, '-----BEGIN ENCRYPTED PRIVATE KEY-----')) {
            return true;
        }

        // Subprocess fallback probe with empty passphrase
        $result = Process::run(['ssh-keygen', '-y', '-P', '', '-f', $resolved]);

        return ! $result->successful();
    }

    /**
     * Check if this key identity is currently loaded in the running ssh-agent.
     */
    public function isInAgent(string $path): bool
    {
        $resolved = $this->resolvePath($path);
        if (! getenv('SSH_AUTH_SOCK')) {
            return false;
        }

        // Get key fingerprint
        $fpResult = Process::run(['ssh-keygen', '-l', '-f', $resolved]);
        if (! $fpResult->successful()) {
            return false;
        }

        $parts = preg_split('/\s+/', trim($fpResult->output()));
        $fingerprint = $parts[1] ?? '';
        if ($fingerprint === '') {
            return false;
        }

        // Check if fingerprint is in ssh-add -l output
        $agentResult = Process::run(['ssh-add', '-l']);
        if (! $agentResult->successful()) {
            return false;
        }

        return str_contains($agentResult->output(), $fingerprint);
    }

    /**
     * Verify whether a passphrase correctly decrypts the private key.
     */
    public function verifyPassphrase(string $path, string $passphrase): bool
    {
        $resolved = $this->resolvePath($path);
        $result = Process::run(['ssh-keygen', '-y', '-P', $passphrase, '-f', $resolved]);

        return $result->successful();
    }

    /**
     * Copy the key into a temporary target path and unlock it in-place using ssh-keygen.
     */
    public function unlockToTemp(string $sourcePath, string $passphrase, string $tempKeyPath): bool
    {
        $resolvedSource = $this->resolvePath($sourcePath);
        if (! file_exists($resolvedSource)) {
            return false;
        }

        if (! @copy($resolvedSource, $tempKeyPath)) {
            return false;
        }

        @chmod($tempKeyPath, 0600);

        $result = Process::run(['ssh-keygen', '-p', '-P', $passphrase, '-N', '', '-f', $tempKeyPath]);

        return $result->successful();
    }

    /**
     * Derive the public key string from a private key.
     */
    public function derivePublicKey(string $path, string $passphrase = ''): ?string
    {
        $resolved = $this->resolvePath($path);
        $result = Process::run(['ssh-keygen', '-y', '-P', $passphrase, '-f', $resolved]);

        return $result->successful() ? trim($result->output()) : null;
    }

    /**
     * Resolve '~' to user's home directory.
     */
    public function resolvePath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');

            return $home !== '' ? $home.substr($path, 1) : $path;
        }

        return $path;
    }
}
