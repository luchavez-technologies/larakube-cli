<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsBuckets;
use App\Contracts\HasCommonsRedisKeys;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasVpnWiring;

/**
 * The single vendor backing the PASTE category — 'Secure Paste Sharing'.
 * Only Yopass.
 *
 * Zero-knowledge, no-account secret sharing — client-side AES encrypted,
 * one-time-read by default (self-destructs on view OR expiry, whichever
 * comes first; not a togglable option like some competitors, just how
 * Yopass works). Confirmed live (2026-08-20, github.com/jhaals/yopass):
 * storage is Redis (or Memcached) only — NO SQL/Postgres backend exists at
 * all, so unlike most Commons-backed tools this one implements
 * HasCommonsRedisKeys but deliberately NOT HasCommonsDatabases. A separate,
 * optional S3-compatible "file storage" feature (disk or S3) can point at
 * Commons SeaweedFS for larger uploads, wired conditionally in
 * PasteInitCommand only when Commons offers an S3 driver — Redis is the
 * one hard requirement.
 */
final class YopassTool implements ClusterToolVendor, HasCommonsBuckets, HasCommonsRedisKeys, HasDeploymentBaseName, HasVpnWiring
{
    public function getLabel(): string
    {
        return 'Secure Paste Sharing (Yopass)';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        // The whole reason this tool exists is receiving a paste from an
        // external, unauthenticated partner (see PasteInitCommand's
        // --vpn-only warning) — --vpn-only is still offered for a secondary
        // internal-scratchpad use case, but blocks that primary one.
        $name = ($instance === null || $instance === '') ? 'paste-yopass-vpn-only' : "paste-yopass-vpn-only-{$instance}";

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    public function baseDeploymentName(): string
    {
        return 'paste-yopass';
    }

    /**
     * Matches the tenant key PasteInitCommand passes to
     * allocateCommonsRedisIndex() — released on paste:remove --purge.
     */
    public function commonsRedisKeys(): array
    {
        return ['paste_yopass'];
    }

    /**
     * Only ever actually created when Commons offers an S3-compatible
     * driver (seaweedfs/minio/garage) — declared unconditionally here so
     * paste:remove --purge's cleanup is harmless/idempotent even when no
     * bucket was ever allocated.
     */
    public function commonsBucketList(): array
    {
        return ['paste-yopass'];
    }
}
