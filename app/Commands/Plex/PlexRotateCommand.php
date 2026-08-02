<?php

namespace App\Commands\Plex;

use App\Enums\ClusterTool;
use App\Enums\CommonsSecret;
use App\Enums\DatabaseDriver;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesEnvironmentContext;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

/**
 * Roll Commons credentials — the ONLY place a tenant's credential is ever
 * reset. plex:join used to have its own --rotate flag that did this too;
 * removed 2026-08-01 — a flag on a JOIN command for "actually reset the
 * password" was exactly the kind of hidden, easy-to-miss distinct operation
 * this CLI avoids elsewhere (sso:grant/sso:revoke, cluster:grant/revoke).
 * Re-running plex:join is now always safe and never resets a credential as a
 * side effect; resetting one is this command's job, explicitly.
 *
 * Two genuinely different mechanisms exist for a tenant's DB credential,
 * depending on how it joined, and this command must pick the right one PER
 * TENANT (staticRoleExists() — see rotateOpenBaoTenant()), not just once for
 * the whole run:
 *   - OpenBao-wired tenants: rotated THROUGH OpenBao (rotateStaticRole),
 *     which then syncs into the consuming namespace via ExternalSecrets —
 *     no redeploy, just a restart. Two naming conventions live in the SAME
 *     registry for this, both checked: Application Tenants (plex:join) use
 *     "tenant-{name}"; cluster tools (secrets:wire, RecordInit, SignInit,
 *     SsoInit, …) use the bare name.
 *   - Everyone else (joined before OpenBao existed, or while it was
 *     unreachable): the legacy path — ALTER ROLE directly, then push into
 *     OpenBao's plain KV store if available, else rewrite .env and say a
 *     redeploy is required.
 * Picking the wrong one for a given tenant doesn't just fail cleanly — it
 * desyncs Postgres's actual password from whichever value OpenBao's static
 * role still has cached, breaking auth until OpenBao's own rotation_period
 * (7 days) eventually overwrites it again.
 */
class PlexRotateCommand extends Command
{
    use ConfirmsDestructiveAction, InteractsWithPlex, InteractsWithProjectConfig,
        LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesEnvironmentContext,
        SyncsClusterSecrets;

    protected $signature = 'plex:rotate
        {environment? : Environment whose Commons credentials to roll — "local" (default) or a cloud environment. Omit to be prompted.}
        {--only=      : Comma-separated credential kinds: db,s3,admin,tools (default: all)}
        {--tenant=    : Limit per-tenant rotation to this tenant (default: every tenant)}
        {--context=   : Target a specific kube-context (defaults to the environment\'s saved target)}
        {--no-restart : Rotate without restarting consumers — they keep the old value until restarted}
        {--force      : Skip the confirmation prompt (required for non-interactive runs)}';

    protected $description = 'Rotate Commons credentials (tenant DB, S3 keys, admin password, tool stores)';

    public function handle(): int
    {
        $this->renderHeader();

        $config = $this->getProjectConfig(getcwd());
        $env = $this->resolvePlexEnvironment($config);

        $context = $env === 'local'
            ? ((string) $this->option('context') ?: null)
            : ($this->option('context') ?: ($config ? $this->environmentContextOrCurrent($config, $env) : null));

        $this->plexContext = $context !== '' ? $context : null;

        if ($this->getCommonsSpec() === null) {
            $this->laraKubeError("No Plex Commons found for '{$env}'.");
            $this->line('  <fg=gray>Nothing to rotate. Set one up with</> <fg=blue>larakube plex:init</><fg=gray>.</>');

            return 1;
        }

        $kinds = $this->resolveKinds();
        if ($kinds === []) {
            return 1;
        }

        $kubectl = $this->plexKubectl();
        $backendAvailable = $this->secretsBackendAvailable($kubectl);

        $this->reportMode($backendAvailable);

        $tenants = null;
        if (in_array(CommonsSecret::TENANT_DB, $kinds, true)) {
            $tenants = $this->tenantsToRotate();
            if ($tenants === null) {
                return 1;
            }
        }

        if (! $this->confirmDestructive($this->warningLines($env, $kinds, $backendAvailable, $tenants))) {
            return 0;
        }

        $ok = true;
        foreach ($kinds as $kind) {
            $ok = $this->rotate($kind, $kubectl, $env, $backendAvailable) && $ok;
        }

        $this->printNext($backendAvailable);

        return $ok ? 0 : 1;
    }

    /** @return list<CommonsSecret> */
    protected function resolveKinds(): array
    {
        $only = trim((string) ($this->option('only') ?? ''));

        if ($only === '') {
            return CommonsSecret::cases();
        }

        $kinds = [];
        foreach (array_map('trim', explode(',', $only)) as $slug) {
            $kind = CommonsSecret::tryFrom($slug);
            if ($kind === null) {
                $this->laraKubeError("Unknown credential kind '{$slug}'.");
                $this->line('  <fg=gray>Valid kinds: </>'.implode(', ', array_keys(CommonsSecret::options())));

                return [];
            }
            $kinds[] = $kind;
        }

        return $kinds;
    }

    protected function reportMode(bool $backendAvailable): void
    {
        $this->newLine();

        if ($backendAvailable) {
            $this->line('  <fg=green>OpenBao-backed rotation.</> <fg=gray>New values are pushed to OpenBao and</>');
            $this->line('  <fg=gray>synced into the consuming namespaces — no redeploy needed.</>');

            return;
        }

        $this->line('  <fg=yellow>Literal rotation (OpenBao not bootstrapped).</> <fg=gray>New values are written</>');
        $this->line('  <fg=gray>into .env, so a redeploy IS required before anything uses them.</>');
        $this->line('  <fg=gray>  Want clean rotation? Run</> <fg=blue>larakube secrets:init</> <fg=gray>first.</>');
    }

    /**
     * @param  list<CommonsSecret>  $kinds
     * @param  list<string>|null  $tenants  Tenants TENANT_DB will touch, or
     *                                      null when that kind isn't selected.
     */
    protected function warningLines(string $env, array $kinds, bool $backendAvailable, ?array $tenants = null): array
    {
        $lines = [
            "Commons credentials in '{$env}' will be ROTATED:",
        ];

        foreach ($kinds as $kind) {
            $lines[] = '  • '.$kind->label();
        }

        // The scale of a bare `plex:rotate` (every tenant, in one run) isn't
        // obvious from "Tenant database" alone — spelling out exactly who's
        // touched, before the confirm prompt, is what was missing when this
        // rotated 11 tools at once in production 2026-08-02 with no one
        // having seen the list first.
        if ($tenants !== null && $tenants !== []) {
            $lines[] = count($tenants) === 1
                ? "  1 tenant: {$tenants[0]}"
                : '  '.count($tenants).' tenants: '.implode(', ', $tenants);
        }

        $lines[] = $backendAvailable
            ? 'Consumers are restarted to pick up the new values.'
            : 'Consumers keep the OLD values until you redeploy — expect downtime between the two.';

        return $lines;
    }

    /**
     * Resolve which tenants TENANT_DB rotation will touch, honoring
     * --tenant. Shared by the pre-confirm preview and the actual rotation
     * loop so they can never disagree about scope. Returns null for an
     * unknown --tenant (the caller should treat that as a hard error, not an
     * empty selection).
     *
     * @return list<string>|null
     */
    protected function tenantsToRotate(): ?array
    {
        $tenants = array_keys($this->getRegistry()['tenants'] ?? []);

        $only = (string) ($this->option('tenant') ?? '');
        if ($only === '') {
            return $tenants;
        }

        if (! in_array($only, $tenants, true)) {
            $this->laraKubeError("'{$only}' is not a tenant of this Commons.");
            $this->line('  <fg=gray>Known tenants: </>'.($tenants === [] ? '(none)' : implode(', ', $tenants)));

            return null;
        }

        return [$only];
    }

    protected function rotate(CommonsSecret $kind, string $kubectl, string $env, bool $backendAvailable): bool
    {
        return match ($kind) {
            CommonsSecret::TENANT_DB => $this->rotateTenantDatabases($kubectl, $env, $backendAvailable),
            CommonsSecret::COMMONS_ADMIN => $this->rotateCommonsAdmin($kubectl, $backendAvailable),
            CommonsSecret::COMMONS_S3 => $this->reportUnsupported($kind),
            CommonsSecret::TOOL_STORE => $this->reportUnsupported($kind),
        };
    }

    /**
     * Rotate every tenant's database login (or just --tenant). This is the one
     * that previously forced a redeploy, so it's the one with real payoff.
     */
    protected function rotateTenantDatabases(string $kubectl, string $env, bool $backendAvailable): bool
    {
        $registry = $this->getRegistry();
        // Re-resolves the same list handle() already validated and previewed
        // before the confirm prompt — sharing tenantsToRotate() means the two
        // can never disagree about scope.
        $tenants = $this->tenantsToRotate() ?? [];

        if ($tenants === []) {
            $this->line('  <fg=gray>No tenants to rotate.</>');

            return true;
        }

        $ok = true;

        foreach ($tenants as $tenant) {
            $allocation = $registry['tenants'][$tenant] ?? [];
            $service = $allocation['db_service'] ?? null;

            if ($service === null) {
                $this->line("  <fg=gray>{$tenant}: no Commons database — skipped.</>");

                continue;
            }

            $driver = DatabaseDriver::tryFrom($service);
            if ($driver === null) {
                $this->line("  <fg=gray>{$tenant}: unknown engine '{$service}' — skipped.</>");

                continue;
            }

            // A tenant already wired through OpenBao's static-role mechanism
            // must be rotated THROUGH OpenBao — directly ALTERing its
            // Postgres password below would desync it from OpenBao's cached
            // static-creds, breaking auth until OpenBao's own 7-day
            // rotation_period eventually catches up. Two naming conventions
            // exist for the SAME registry, and neither is optional to check:
            // Application Tenants (plex:join) use "tenant-{name}"; cluster
            // tools (secrets:wire, RecordInit, SignInit, SsoInit, …) use the
            // bare name. Checking only one and treating a miss as "not
            // wired" would silently corrupt whichever kind it didn't check —
            // confirmed live 2026-08-02 on production: 'tenant-record_sendrec'
            // 404s, but the tool's ACTUAL role is bare 'record_sendrec'.
            //
            // staticRoleExists() returns null, not just false, when this
            // genuinely can't be determined (OpenBao sealed/unreachable) —
            // treating null as "not wired" risks the same corruption for a
            // tenant that IS wired but momentarily unreachable. Refuse
            // instead of guessing.
            $roleName = 'tenant-'.$tenant;
            $wired = $backendAvailable ? $this->staticRoleExists($kubectl, $roleName) : false;

            if ($wired === false) {
                $bareWired = $backendAvailable ? $this->staticRoleExists($kubectl, $tenant) : false;
                if ($bareWired === true) {
                    $roleName = $tenant;
                }
                $wired = $bareWired;
            }

            if ($wired === null) {
                $this->laraKubeError("Could not determine whether '{$tenant}' is OpenBao-wired (OpenBao may be sealed or unreachable) — refusing to guess. Check its status and retry.");
                $ok = false;

                continue;
            }

            if ($wired) {
                $ok = $this->rotateOpenBaoTenant($kubectl, $tenant, $roleName, $allocation) && $ok;

                continue;
            }

            $password = bin2hex(random_bytes(16));

            // allocateDatabase() is idempotent and re-asserts the password, so
            // it doubles as the rotation primitive — no separate ALTER path to
            // drift from the create path.
            if (! $this->allocateDatabase($driver, $tenant, $password)) {
                $this->laraKubeError("Failed to rotate the database password for '{$tenant}'.");
                $ok = false;

                continue;
            }

            if ($backendAvailable) {
                $key = CommonsSecret::TENANT_DB->clusterSecretKey($tenant);
                if ($key !== null && ! $this->pushClusterSecret($kubectl, $key, $password)) {
                    $this->laraKubeError("Rotated '{$tenant}' in Postgres but could not push the new value to OpenBao.");
                    $this->line('  <fg=gray>  The database now expects a password nothing has. Re-run to retry.</>');
                    $ok = false;

                    continue;
                }
                $this->line("  <fg=green>✔</> <fg=cyan>{$tenant}</> <fg=gray>rotated → OpenBao (</><fg=blue>{$key}</><fg=gray>)</>");

                continue;
            }

            $this->line("  <fg=green>✔</> <fg=cyan>{$tenant}</> <fg=gray>rotated. New password:</> <fg=yellow>{$password}</>");
            $this->line('    <fg=gray>Write it into .env.'.$env.' (DB_PASSWORD) and redeploy.</>');
        }

        return $ok;
    }

    /**
     * Rotate an OpenBao-wired tenant's DB credential through the static-role
     * mechanism (the only correct path — see the caller), then force ESO to
     * pick it up immediately rather than waiting out its refreshInterval, and
     * restart the tenant's `web` deployment so it isn't left running against
     * a password OpenBao just superseded. Restarting only happens when the
     * tenant's namespace is known — either recorded on the registry
     * (plex:join, since 2026-08-01), or derivable from ClusterTool for a
     * cluster-tool tenant, whose namespace is a fixed fact (`larakube-shared`
     * for almost all of them), not something that needed recording in the
     * first place. Only a genuine Application Tenant with a registry entry
     * older than 2026-08-01 can still land in the true "unknown" case below.
     */
    protected function rotateOpenBaoTenant(string $kubectl, string $tenant, string $roleName, array $allocation): bool
    {
        if (! $this->rotateStaticRole($kubectl, $roleName)) {
            $this->laraKubeError("Failed to rotate '{$tenant}'s OpenBao static role.");

            return false;
        }

        $tool = ClusterTool::forCommonsResource($tenant);
        $namespace = $allocation['namespace'] ?? $tool?->namespace();
        if ($namespace === null) {
            $this->line("  <fg=green>✔</> <fg=cyan>{$tenant}</> <fg=gray>rotated via OpenBao. Namespace unknown (joined before it was recorded) — restart its deployment(s) once ESO syncs the new value.</>");

            return true;
        }

        $deployment = $tool?->deploymentName() ?? 'web';

        // Application Tenants' Laravel deployment always names its
        // ExternalSecret 'laravel-secrets-db'. A cluster tool wires its own
        // via its *:init command (dbSecretRef() knows the 4 that go through
        // secrets:wire's generic path) — for the rest, that name isn't
        // derivable here, and restarting blind risks the exact race
        // SecretsWireCommand guards against (confirmed live 2026-07-30: it
        // took Documenso down a second time restarting before the sync
        // landed) — give an exact command instead of guessing.
        $ref = $tool?->dbSecretRef();
        $secretName = match (true) {
            $tool === null => 'laravel-secrets-db',
            $ref !== null => "{$ref['secret']}-db",
            default => null,
        };

        if ($secretName === null) {
            $this->line("  <fg=green>✔</> <fg=cyan>{$tenant}</> <fg=gray>rotated via OpenBao. Restart it once ESO syncs (~60s):</> <fg=yellow>kubectl rollout restart deployment/{$deployment} -n {$namespace}</>");

            return true;
        }

        $refreshTimeBefore = $this->externalSecretRefreshTime($kubectl, $namespace, $secretName);
        $this->forceExternalSecretReconcile($kubectl, $namespace, $secretName);

        // A sync timeout here does NOT mean the rotation failed — the
        // credential is already correct in OpenBao and will land within
        // ESO's refreshInterval regardless. Unlike plex:join, there's no
        // legacy-path fallback to offer: falling back to a second, separate
        // ALTER ROLE here would create a THIRD password nothing agrees on.
        if (! $this->waitForExternalSecretSynced($kubectl, $namespace, $secretName, $refreshTimeBefore)) {
            $this->laraKubeWarn("'{$tenant}' rotated in OpenBao, but the sync into '{$namespace}' didn't confirm in time — it will still land within ESO's refresh interval.");

            return true;
        }

        if (trim(Process::run("{$kubectl} get deployment {$deployment} -n {$namespace} --no-headers --ignore-not-found")->output()) !== '') {
            $this->restartSecretConsumers($kubectl, $namespace, $deployment);
            $this->line("  <fg=green>✔</> <fg=cyan>{$tenant}</> <fg=gray>rotated via OpenBao and restarted in </><fg=cyan>{$namespace}</><fg=gray>.</>");

            return true;
        }

        $this->line("  <fg=green>✔</> <fg=cyan>{$tenant}</> <fg=gray>rotated via OpenBao and synced to </><fg=cyan>{$namespace}</><fg=gray>.</>");

        return true;
    }

    protected function rotateCommonsAdmin(string $kubectl, bool $backendAvailable): bool
    {
        // The superuser password is held by the Commons Postgres itself and by
        // the plex-admin Secret. Rotating it without updating both in the same
        // breath locks every tool out, so this is deliberately not automated
        // until the Secret-update path is proven — say so rather than pretend.
        $this->line('  <fg=gray>Commons admin password: not rotated automatically yet.</>');
        $this->line('  <fg=gray>  It is held by both the Postgres pod and the plex-admin Secret; rotating</>');
        $this->line('  <fg=gray>  one without the other locks out every tenant, so this needs the</>');
        $this->line('  <fg=gray>  Secret-update path proven on a live Commons first.</>');

        return true;
    }

    protected function reportUnsupported(CommonsSecret $kind): bool
    {
        $this->line("  <fg=gray>{$kind->label()}: not rotatable yet — tracked, not silently skipped.</>");

        return true;
    }

    protected function printNext(bool $backendAvailable): void
    {
        $this->laraKubeNewLine();

        if (! $backendAvailable) {
            $this->laraKubeWarn('Rotation complete — but the new values are NOT live yet.');
            $this->line('  1. Update .env.{env} with the passwords printed above');
            $this->line('  2. <fg=yellow>larakube cloud:configure {env} --only=ci</>');
            $this->line('  3. Redeploy');

            return;
        }

        $this->laraKubeInfo('✅ Rotation complete.');

        if ($this->option('no-restart')) {
            $this->line('  <fg=gray>--no-restart was set: consumers keep the old value until they restart.</>');

            return;
        }

        $this->line('  <fg=gray>The OpenBao operator syncs within ~60s. Restart consumers to pick it up:</>');
        $this->line('  <fg=yellow>larakube reload</> <fg=gray>(app) ·</> <fg=yellow>larakube mail:restart</> <fg=gray>(Stalwart)</>');
    }

    /** Whether the Commons Postgres is reachable — used by tests and callers. */
    protected function commonsReachable(string $kubectl): bool
    {
        return Process::run("{$kubectl} get deploy/postgres -n {$this->plexNamespace()} -o name")->successful();
    }
}
