<?php

namespace App\Enums;

/**
 * The credential kinds `plex:rotate` can roll.
 *
 * Each case owns everything that differs between them — the secrets backend key, the
 * env vars it feeds, and whether it is per-tenant or cluster-wide — so the
 * rotate command stays a loop over cases instead of a switch. This is the same
 * "maximize the enum" shape as ClusterTool: adding a rotatable credential means
 * adding a case, not editing the command.
 */
enum CommonsSecret: string
{
    public function label(): string
    {
        return match ($this) {
            self::TENANT_DB => 'Per-tenant database password',
            self::COMMONS_S3 => 'Commons S3 access + secret keys',
            self::COMMONS_ADMIN => 'Commons Postgres superuser password',
            self::TOOL_STORE => 'Cluster tool store passwords',
        };
    }

    /**
     * True when this credential exists once per tenant (so rotating needs a
     * tenant to act on); false when it is cluster-wide.
     */
    public function isPerTenant(): bool
    {
        return $this === self::TENANT_DB;
    }

    /**
     * The env var names this credential feeds in a tenant's configuration.
     * These are the keys that become secretKeyRef-backed when the secrets backend is
     * available, and literal .env values when it isn't.
     *
     * @return list<string>
     */
    public function envKeys(): array
    {
        return match ($this) {
            self::TENANT_DB => ['DB_PASSWORD'],
            self::COMMONS_S3 => ['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY'],
            self::COMMONS_ADMIN => [],   // operator-facing only; never in an app's env
            self::TOOL_STORE => [],      // each tool declares its own (e.g. STALWART_STORE_PASSWORD)
        };
    }

    /**
     * The secrets backend secret key this credential is stored under. Per-tenant
     * secrets are namespaced by tenant so two apps on one Commons never
     * collide. Returns null for kinds whose key is owned by the consumer
     * (TOOL_STORE — the tool names its own).
     */
    public function clusterSecretKey(?string $tenant = null): ?string
    {
        return match ($this) {
            // Delegated to the tool, not synthesised with a PLEX_ prefix. The
            // key must survive a tool moving off the Commons (--no-plex), so it
            // names the tool rather than where its database happens to live —
            // and the tool's own :init pushes under the SAME key, which is what
            // makes a rotation land somewhere the tool actually reads.
            self::TENANT_DB => $tenant !== null
                ? (ClusterTool::forCommonsTenant($tenant)?->clusterSecretDbKey($tenant) ?? ClusterTool::tenantKey($tenant))
                : null,
            self::COMMONS_S3 => 'PLEX_COMMONS_S3_SECRET_KEY',
            self::COMMONS_ADMIN => 'PLEX_COMMONS_ADMIN_PASSWORD',
            self::TOOL_STORE => null,
        };
    }

    /**
     * Rotating this credential requires restarting the consumers, because the
     * value is read once at process start. False where the consumer re-reads
     * it (or where nothing long-lived holds it).
     */
    public function requiresConsumerRestart(): bool
    {
        return match ($this) {
            self::TENANT_DB, self::COMMONS_S3, self::TOOL_STORE => true,
            self::COMMONS_ADMIN => false,
        };
    }

    /** @return array<string, string> slug => label, for --only= validation. */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
    /** The per-tenant Postgres/MySQL login that plex:join allocates. */
    case TENANT_DB = 'db';

    /** The shared Commons S3 access/secret pair copied into every tenant. */
    case COMMONS_S3 = 's3';

    /** The Commons Postgres superuser (plex-admin) password. */
    case COMMONS_ADMIN = 'admin';

    /** Cluster-tool store passwords, e.g. STALWART_STORE_PASSWORD. */
    case TOOL_STORE = 'tools';
}
