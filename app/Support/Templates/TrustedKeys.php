<?php

namespace App\Support\Templates;

use Closure;
use Phar;

/**
 * Resolves the Ed25519 public keys trusted to verify signed app template releases.
 *
 * Why a packaged release must never trust a development key:
 * Blade templates compile directly to executable PHP on the user's machine. If a
 * packaged binary honoured LARAKUBE_TEMPLATE_DEV_KEY from the environment or local
 * config, any process or attacker able to set an environment variable could point
 * the CLI at a self-signed template payload and achieve arbitrary code execution.
 * The dev key is therefore restricted strictly to source checkouts
 * (\Phar::running(false) === '') and ignored inside a compiled PHAR binary.
 */
class TrustedKeys
{
    /**
     * Injectable PHAR-detection callback for testing both source and packaged branches.
     *
     * @var (Closure(): bool)|null
     */
    public static ?Closure $runningInPharResolver = null;

    /**
     * Override how PHAR execution is detected (pass null to restore default).
     *
     * @param  (Closure(): bool)|null  $resolver
     */
    public static function resolveRunningInPharUsing(?Closure $resolver): void
    {
        self::$runningInPharResolver = $resolver;
    }

    public static function isRunningInPhar(): bool
    {
        if (self::$runningInPharResolver !== null) {
            return (self::$runningInPharResolver)();
        }

        return Phar::running(false) !== '';
    }

    /**
     * Return all trusted signing keys as [key_id => base64_public_key].
     *
     * Always includes `templates.trusted_keys`; includes `templates.dev_key`
     * (formatted as "key_id:base64publickey") ONLY when running from a source
     * checkout (\Phar::running(false) === ''), never from a packaged binary.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $configured = config('templates.trusted_keys', []);
        $keys = [];

        if (is_array($configured)) {
            foreach ($configured as $keyId => $publicKey) {
                if (is_string($keyId) && $keyId !== '' && is_string($publicKey) && $publicKey !== '') {
                    $keys[$keyId] = $publicKey;
                }
            }
        }

        if (! self::isRunningInPhar()) {
            $devKey = config('templates.dev_key');
            if (is_string($devKey) && str_contains($devKey, ':')) {
                [$keyId, $publicKey] = explode(':', $devKey, 2);
                $keyId = trim($keyId);
                $publicKey = trim($publicKey);

                if ($keyId !== '' && $publicKey !== '') {
                    $keys[$keyId] = $publicKey;
                }
            }
        }

        return $keys;
    }

    /**
     * Return all trusted signing keys minus any revoked key IDs.
     *
     * @param  list<string>  $revokedKeyIds
     * @return array<string, string>
     */
    public static function withoutRevoked(array $revokedKeyIds): array
    {
        $keys = self::all();

        foreach ($revokedKeyIds as $revokedKeyId) {
            unset($keys[$revokedKeyId]);
        }

        return $keys;
    }
}
