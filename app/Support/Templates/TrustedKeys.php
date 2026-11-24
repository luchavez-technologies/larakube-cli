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
 *
 * Why a stable binary must never trust the staging key:
 * The staging environment and its signing key are less protected than production
 * so pre-release template changes can be exercised in CI and canary builds. If a
 * stable release binary trusted the staging key, a compromise of staging would
 * allow an attacker to serve validly signed malicious templates to every stable
 * user's machine. Stable builds (where app.version is a semver tag /^v?\d+\.\d+\.\d+$/)
 * therefore trust ONLY production keys.
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
     * Injectable stable-release detection callback for testing stable and non-stable channels.
     *
     * @var (Closure(): bool)|null
     */
    public static ?Closure $stableReleaseResolver = null;

    /**
     * Override how PHAR execution is detected (pass null to restore default).
     *
     * @param  (Closure(): bool)|null  $resolver
     */
    public static function resolveRunningInPharUsing(?Closure $resolver): void
    {
        self::$runningInPharResolver = $resolver;
    }

    /**
     * Override how stable release execution is detected (pass null to restore default).
     *
     * @param  (Closure(): bool)|null  $resolver
     */
    public static function resolveStableReleaseUsing(?Closure $resolver): void
    {
        self::$stableReleaseResolver = $resolver;
    }

    public static function isRunningInPhar(): bool
    {
        if (self::$runningInPharResolver !== null) {
            return (self::$runningInPharResolver)();
        }

        return Phar::running(false) !== '';
    }

    /**
     * Determine whether this CLI build is a stable semver release (/^v?\d+\.\d+\.\d+$/).
     *
     * CI builds stable binaries with a semver tag and canary binaries with a commit SHA;
     * source checkouts report a git describe string.
     */
    public static function isStableRelease(): bool
    {
        if (self::$stableReleaseResolver !== null) {
            return (self::$stableReleaseResolver)();
        }

        $version = trim((string) config('app.version', ''));

        return preg_match('/^v?\d+\.\d+\.\d+$/', $version) === 1;
    }

    /**
     * Return all trusted signing keys as [key_id => base64_public_key].
     *
     * Always includes `templates.trusted_keys` (production); includes
     * `templates.staging_keys` ONLY when not a stable release; includes
     * `templates.dev_key` (formatted as "key_id:base64publickey") ONLY when
     * running from a source checkout (\Phar::running(false) === ''), never from
     * a packaged binary.
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

        if (! self::isStableRelease()) {
            $staging = config('templates.staging_keys', []);
            if (is_array($staging)) {
                foreach ($staging as $keyId => $publicKey) {
                    if (is_string($keyId) && $keyId !== '' && is_string($publicKey) && $publicKey !== '') {
                        $keys[$keyId] = $publicKey;
                    }
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
