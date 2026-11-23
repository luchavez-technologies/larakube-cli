<?php

namespace App\Support;

final class SshPublicKey
{
    /**
     * A valid single-line OpenSSH public key as "type base64", or null. The comment is dropped
     * because keys are written into HCL, shell scripts and VM metadata.
     */
    public static function normalize(string $raw): ?string
    {
        $raw = trim($raw);

        if (preg_match('/\R/', $raw) === 1 || str_contains($raw, 'PRIVATE KEY')) {
            return null;
        }

        if (preg_match('/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) ([A-Za-z0-9+\/]+={0,2})(?: .*)?$/', $raw, $m) !== 1) {
            return null;
        }

        $blob = base64_decode($m[2], true);
        if ($blob === false || strlen($blob) < 4) {
            return null;
        }

        $length = unpack('N', substr($blob, 0, 4))[1];

        return substr($blob, 4, $length) === $m[1] ? "{$m[1]} {$m[2]}" : null;
    }
}
