<?php

namespace App\Services;

use App\Contracts\PlexProvisionable;
use App\Data\ConfigData;
use App\Enums\DatabaseDriver;

/**
 * The Plex Commons domain: tenant and Commons lifecycle. `InteractsWithPlex`
 * delegates here. The kube-context is constructor state because a run targets
 * exactly one context.
 */
final class PlexService
{
    /** The namespace that hosts the shared Commons services. */
    public const NAMESPACE = 'larakube-plex';

    public function __construct(
        /** Null means the current kube-context. */
        private readonly ?string $context = null,
    ) {}

    /**
     * The Commons service names THIS project's drivers map to and that are
     * plex-ready today — enum-driven via PlexProvisionable. Used to default
     * plex:init's selection (project-aware) and to drive plex:join's demand-driven
     * bootstrap (provision only what the joining project needs). Pure.
     *
     * @return array<int, string>
     */
    public function projectCommonsServices(ConfigData $config): array
    {
        $drivers = array_filter([
            $config->getDatabase(),
            $config->getCacheDriver(),
            $config->getScoutDriver(),
            $config->getObjectStorage(),
        ]);

        $services = [];
        foreach ($drivers as $driver) {
            if ($driver instanceof PlexProvisionable && $driver->isPlexReady()) {
                $name = $driver->commonsServiceName();
                if ($name !== null) {
                    $services[] = $name;
                }
            }
        }

        return array_values(array_unique($services));
    }

    /**
     * Turn an app name (+ optional env) into a safe SQL identifier reused for
     * the tenant's database AND login role (e.g. "app-one" → "app_one").
     * For non-production envs the env is appended so the same app can join the
     * Commons under two separate environments (e.g. "app_one_staging"). The
     * production env keeps the un-suffixed form for backwards compatibility with
     * existing single-env Plex setups. Pure.
     */
    public function plexTenantIdentifier(string $appName, string $env = 'production'): string
    {
        $id = strtolower(trim($appName));
        $id = (string) preg_replace('/[^a-z0-9]+/', '_', $id);
        $id = trim($id, '_');

        // SQL identifiers must start with a letter; prefix if not.
        if ($id === '' || ! preg_match('/^[a-z]/', $id)) {
            $id = 'app_'.$id;
        }

        // Non-production envs get an env suffix so staging/develop/etc. each
        // get their own isolated DB, Redis slot, and S3 bucket on the Commons.
        if ($env !== 'production') {
            $suffix = '_'.preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($env)));
            $id = substr($id, 0, 63 - strlen($suffix)).$suffix;
        }

        return substr($id, 0, 63); // Postgres identifier length cap.
    }

    /**
     * Turn a tenant identifier into a DNS-safe S3 bucket name (lowercase, hyphens,
     * 3–63 chars) — MinIO/S3 reject the underscores plexTenantIdentifier produces,
     * and SeaweedFS tolerates either, so this one rule serves every backend
     * (e.g. "app_five" → "app-five"). Pure.
     */
    public function plexBucketName(string $tenant): string
    {
        $name = strtolower($tenant);
        $name = (string) preg_replace('/[^a-z0-9-]+/', '-', $name);
        $name = (string) preg_replace('/-+/', '-', $name);
        $name = trim($name, '-');

        if (strlen($name) < 3) {
            $name = 'lk-'.$name; // S3 requires ≥3 chars.
        }

        return substr($name, 0, 63);
    }

    /**
     * Idempotent SQL that creates a tenant's database, login role, and grant in
     * the Commons Postgres. Piped to `psql` over stdin (so `\gexec` works). Pure.
     * $db/$role are pre-sanitized identifiers; the password is single-quote escaped.
     */
    public function buildPostgresTenantSql(string $db, string $role, string $password): string
    {
        // The per-engine tenant SQL lives on the DatabaseDriver enum now (so each
        // Commons backend owns its own provisioning); this stays as the Postgres
        // shorthand the unit tests pin.
        return (string) DatabaseDriver::POSTGRESQL->commonsTenantSql($db, $role, $password);
    }

    /**
     * Inverse of buildPostgresTenantSql: drop a tenant's database and role from
     * the Commons Postgres. Delegates to the enum (see commonsDropSql).
     */
    public function buildDropTenantSql(string $db, string $role): string
    {
        return (string) DatabaseDriver::POSTGRESQL->commonsDropSql($db, $role);
    }
}
