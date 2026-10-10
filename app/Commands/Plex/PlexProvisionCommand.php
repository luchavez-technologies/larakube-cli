<?php

namespace App\Commands\Plex;

use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesEnvironmentContext;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

/**
 * plex:join is irreducibly project-bound (derives its tenant id from
 * .larakube.json, writes .env, triggers heal --force) — this is the Commons
 * equivalent for an app that ISN'T a recognized LaraKube project: an
 * arbitrary tenant identifier, no project required, its entire output IS the
 * credentials (never written anywhere — printed once, with a "store these
 * now" warning). See plans/active/plex-commons-desktop-page.md Decision 4b.
 *
 * Built on the exact allocation calls plex:join already makes
 * (allocateDatabase/allocateStorageBucket/allocateRedisDbIndex), just
 * without plex:join's re-join/existing-data-migration machinery, which
 * exists only because a PROJECT can already have self-hosted data to
 * preserve — there is none here. Context resolution mirrors plex:evict (the
 * other project-optional plex:* command) rather than plex:join's direct
 * `new PlexService()` style, for the same reason: {environment} is the one
 * positional every mail/sso/vpn/plex command takes (CommandShapeTest), so
 * the tenant identifier is a --tenant flag, not a second positional.
 */
class PlexProvisionCommand extends Command
{
    use EmitsJsonOutput, InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, ReadsCommandOptions, RequiresFlagsWhenNonInteractive, ResolvesEnvironmentContext;

    protected $signature = 'plex:provision
        {environment? : Environment label for this operation — "local" (default). Omit to be prompted.}
        {--tenant= : Arbitrary tenant identifier for a custom app — not derived from any LaraKube project. Omit to be prompted.}
        {--service=* : Which Commons service(s) to provision: db, redis, s3 (repeatable; default: every enabled Commons service)}
        {--context= : Target a specific kube-context (defaults to the environment\'s saved target, or the current context for local)}
        {--force : Skip the confirmation}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Provision on-demand Commons credentials for an app that is not a recognized LaraKube project';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Plex — Provision custom credentials');

        // A project is optional here (the whole point is an app that isn't
        // one) — a null config just means the environment can't contribute a
        // saved deploy target, and the current context, or --context, is it.
        $config = $this->getProjectConfig(getcwd());
        $env = $this->resolvePlexEnvironment($config);

        $context = $env === 'local'
            ? ((string) $this->option('context') ?: null)
            : ($this->option('context') ?: ($config ? $this->environmentContextOrCurrent($config, $env) : null));

        $this->plexContext = $context !== '' ? $context : null;

        if (! $this->plexContextReachable()) {
            $this->laraKubeError('The '.($this->plexContext !== null ? "context '{$this->plexContext}'" : 'current context').' is unreachable.');

            return 1;
        }

        $tenant = $this->plexTenantIdentifier($this->resolveTenantFlag($env));

        $spec = $this->getCommonsSpec();

        if ($spec === null) {
            $this->laraKubeError('No Commons on this cluster yet. Run `larakube plex:init` first.');

            return 1;
        }

        $dbDriver = $this->enabledCommonsDatabaseDriver($spec);
        $storageDriver = $this->enabledCommonsStorageDriver($spec);
        $redisEnabled = (bool) ($spec['services']['redis']['enabled'] ?? false);

        $available = array_keys(array_filter([
            'db' => $dbDriver !== null,
            'redis' => $redisEnabled,
            's3' => $storageDriver !== null,
        ]));

        $requested = (array) $this->option('service');
        $services = $requested === [] ? $available : array_values(array_intersect($requested, $available));

        if ($services === []) {
            $this->laraKubeError($requested === []
                ? 'No Commons services are enabled on this cluster.'
                : 'None of the requested services ('.implode(', ', $requested).') are enabled on this Commons.');

            return 1;
        }

        $this->line("  <fg=gray>Tenant:</> <fg=cyan>{$tenant}</>  <fg=gray>context:</> <fg=cyan>".($this->plexContext ?? 'current').'</>  <fg=gray>services:</> <fg=cyan>'.implode(', ', $services).'</>');

        if (! $this->option('force') && ! confirm("Provision Commons credentials for '{$tenant}'?", true)) {
            $this->laraKubeInfo('Aborted.');

            return 0;
        }

        $registry = $this->getRegistry();
        $existing = $registry['tenants'][$tenant] ?? [];

        $credentials = [];
        $skipped = [];

        // Database: the ONE thing that is NOT safely re-runnable — allocateDatabase()
        // unconditionally resets the role's password every call (see
        // PlexJoinCommand's own comment on this). An already-provisioned
        // database is left untouched; its password was shown once, at
        // first-provision time, and is never stored anywhere to re-show.
        if (in_array('db', $services, true) && $dbDriver !== null) {
            if (($existing['db'] ?? null) !== null) {
                $skipped[] = 'database (already provisioned — see note below)';
            } else {
                $password = bin2hex(random_bytes(16));

                if (! $this->allocateDatabase($dbDriver, $tenant, $password)) {
                    return 1;
                }

                $credentials['database'] = [
                    'driver' => $dbDriver->value,
                    'service' => $dbDriver->commonsServiceName(),
                    'database' => $tenant,
                    'username' => $tenant,
                    'password' => $password,
                ];
            }
        }

        // Redis index and the S3 bucket/credentials are naturally idempotent
        // (the index is reused from the registry if already allocated, the
        // bucket name is a deterministic function of the tenant, and the S3
        // access/secret pair is the one shared Commons admin key, the same
        // for every tenant) — safe to re-run and re-show on every call.
        $redisIndex = null;
        if (in_array('redis', $services, true) && $redisEnabled) {
            $redisIndex = $existing['redis_index'] ?? $this->allocateRedisDbIndex($this->registryUsedRedisIndexes($registry));

            if ($redisIndex === null) {
                $this->laraKubeWarn('The Commons Redis is full (16 logical DBs) — skipping Redis for this tenant.');
            } else {
                $credentials['redis'] = ['index' => $redisIndex];
            }
        }

        $bucket = $existing['s3_bucket'] ?? null;
        if (in_array('s3', $services, true) && $storageDriver !== null) {
            $s3Creds = $this->readCommonsS3Credentials();

            if ($s3Creds === null) {
                $this->laraKubeWarn('Commons S3 credentials (plex-admin) not found — skipping S3 for this tenant. Re-run `larakube plex:init`.');
            } else {
                $bucket ??= $this->plexBucketName($tenant);

                if (! $this->allocateStorageBucket($storageDriver, $bucket)) {
                    return 1;
                }

                $credentials['s3'] = [
                    'service' => $storageDriver->commonsServiceName(),
                    'bucket' => $bucket,
                    'accessKey' => $s3Creds['access'],
                    'secretKey' => $s3Creds['secret'],
                ];
            }
        }

        if ($credentials === [] && $skipped === []) {
            $this->laraKubeError('Nothing was provisioned — every requested service was unavailable.');

            return 1;
        }

        if ($credentials === []) {
            $this->laraKubeInfo("Nothing new to provision for '{$tenant}' — ".implode(', ', $skipped).'.');

            if ($this->flag('json')) {
                $this->jsonOutput(['success' => true, 'tenant' => $tenant, 'credentials' => [], 'skipped' => $skipped]);
            }

            return 0;
        }

        $registry = $this->registryAdd($registry, $tenant, [
            'db' => isset($credentials['database']) || ($existing['db'] ?? null) !== null ? $tenant : null,
            'db_service' => ($existing['db_service'] ?? null) ?? $dbDriver?->commonsServiceName(),
            'redis_index' => $redisIndex,
            's3_bucket' => $bucket,
            's3_service' => ($existing['s3_service'] ?? null) ?? $storageDriver?->commonsServiceName(),
            'kind' => 'custom',
        ]);
        $this->saveRegistry($registry);

        $this->printCredentials($tenant, $credentials, $skipped);

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'tenant' => $tenant, 'credentials' => $credentials, 'skipped' => $skipped]);
        }

        return 0;
    }

    /** --tenant, or a prompt — non-interactively a missing flag is fatal (unrecoverable to guess). */
    private function resolveTenantFlag(string $env): string
    {
        return $this->flagOrPrompt(
            'tenant',
            fn (): string => text(label: 'Tenant identifier for this custom app', required: true),
            'the tenant identifier to provision',
            "larakube plex:provision {$env} --tenant=my-app --context=…",
        );
    }

    /** First enabled relational DB engine on this Commons, or null if none is. */
    private function enabledCommonsDatabaseDriver(array $spec): ?DatabaseDriver
    {
        foreach (DatabaseDriver::cases() as $driver) {
            $service = $driver->commonsServiceName();

            if ($service !== null && ($spec['services'][$service]['enabled'] ?? false)) {
                return $driver;
            }
        }

        return null;
    }

    /** First enabled object-storage engine on this Commons, or null if none is. */
    private function enabledCommonsStorageDriver(array $spec): ?StorageDriver
    {
        foreach (StorageDriver::cases() as $driver) {
            $service = $driver->commonsServiceName();

            if ($service !== null && ($spec['services'][$service]['enabled'] ?? false)) {
                return $driver;
            }
        }

        return null;
    }

    /**
     * Print every newly-allocated credential, with a one-time "store these
     * now" warning — this command never writes them anywhere (no project to
     * write a .env into), so this output is the only place they ever appear.
     *
     * @param  array<string, array<string, mixed>>  $credentials
     * @param  list<string>  $skipped
     */
    private function printCredentials(string $tenant, array $credentials, array $skipped): void
    {
        $this->laraKubeNewLine();

        if ($credentials !== []) {
            $this->laraKubeWarn('Store these now — they are never saved anywhere and will not be shown again:');
        }

        if (isset($credentials['database'])) {
            $db = $credentials['database'];
            $this->line("  <fg=green>Database</> ({$db['service']}):");
            $this->line("    <fg=gray>name:</>     {$db['database']}");
            $this->line("    <fg=gray>username:</> {$db['username']}");
            $this->line("    <fg=gray>password:</> <fg=yellow>{$db['password']}</>");
        }

        if (isset($credentials['redis'])) {
            $this->line('  <fg=green>Redis</>:');
            $this->line("    <fg=gray>logical DB index:</> {$credentials['redis']['index']}");
        }

        if (isset($credentials['s3'])) {
            $s3 = $credentials['s3'];
            $this->line("  <fg=green>S3</> ({$s3['service']}):");
            $this->line("    <fg=gray>bucket:</>     {$s3['bucket']}");
            $this->line("    <fg=gray>access key:</> <fg=yellow>{$s3['accessKey']}</>");
            $this->line("    <fg=gray>secret key:</> <fg=yellow>{$s3['secretKey']}</>");
        }

        if ($skipped !== []) {
            $this->laraKubeNewLine();
            $this->line('  <fg=gray>Skipped (already provisioned):</> '.implode(', ', $skipped));
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ '{$tenant}' provisioned on the Commons.");
        $this->line('  <fg=gray>Evict it with</> <fg=cyan>larakube plex:evict --tenant='.$tenant.'</> <fg=gray>when it is no longer needed.</>');
    }
}
