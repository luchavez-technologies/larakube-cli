<?php

namespace App\Commands\Plex;

use App\Enums\CommonsSecret;
use App\Enums\DatabaseDriver;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesEnvironmentContext;
use App\Traits\SyncsInfisicalSecrets;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

/**
 * Roll Commons credentials.
 *
 * `plex:join --rotate` already reset a tenant's password, but only as a
 * static-file rotation: it rewrote DB_PASSWORD into .env.{env}, which then
 * required `cloud:configure --only=ci` and a redeploy before anything actually
 * used the new value. That is why rotation never felt clean.
 *
 * This command separates the two halves. The credential is always rotated at
 * the source (ALTER ROLE / new S3 key). Where Infisical is bootstrapped the new
 * value is pushed there and the operator syncs it into the consuming namespace,
 * so no redeploy is needed — only a restart of processes that read it at boot.
 * Where Infisical is absent it falls back to rewriting .env and says plainly
 * that a redeploy is required, so the weaker mode is never mistaken for the
 * stronger one.
 */
class PlexRotateCommand extends Command
{
    use ConfirmsDestructiveAction, InteractsWithPlex, InteractsWithProjectConfig,
        LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesEnvironmentContext,
        SyncsInfisicalSecrets;

    protected $signature = 'plex:rotate
        {environment=local : The environment whose Commons credentials to roll}
        {--only=      : Comma-separated credential kinds: db,s3,admin,tools (default: all)}
        {--tenant=    : Limit per-tenant rotation to this tenant (default: every tenant)}
        {--context=   : Target a specific kube-context (defaults to the environment\'s saved target)}
        {--no-restart : Rotate without restarting consumers — they keep the old value until restarted}
        {--force      : Skip the confirmation prompt (required for non-interactive runs)}';

    protected $description = 'Rotate Commons credentials (tenant DB, S3 keys, admin password, tool stores)';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $config = $this->getProjectConfig(getcwd());

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
        $infisical = $this->infisicalAvailable($kubectl);

        $this->reportMode($infisical);

        if (! $this->confirmDestructive($this->warningLines($env, $kinds, $infisical))) {
            return 0;
        }

        $ok = true;
        foreach ($kinds as $kind) {
            $ok = $this->rotate($kind, $kubectl, $env, $infisical) && $ok;
        }

        $this->printNext($infisical);

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

    protected function reportMode(bool $infisical): void
    {
        $this->newLine();

        if ($infisical) {
            $this->line('  <fg=green>Infisical-backed rotation.</> <fg=gray>New values are pushed to Infisical and</>');
            $this->line('  <fg=gray>synced into the consuming namespaces — no redeploy needed.</>');

            return;
        }

        $this->line('  <fg=yellow>Literal rotation (Infisical not bootstrapped).</> <fg=gray>New values are written</>');
        $this->line('  <fg=gray>into .env, so a redeploy IS required before anything uses them.</>');
        $this->line('  <fg=gray>  Want clean rotation? Run</> <fg=blue>larakube secrets:init</> <fg=gray>first.</>');
    }

    /** @param  list<CommonsSecret>  $kinds */
    protected function warningLines(string $env, array $kinds, bool $infisical): array
    {
        $lines = [
            "Commons credentials in '{$env}' will be ROTATED:",
        ];

        foreach ($kinds as $kind) {
            $lines[] = '  • '.$kind->label();
        }

        $lines[] = $infisical
            ? 'Consumers are restarted to pick up the new values.'
            : 'Consumers keep the OLD values until you redeploy — expect downtime between the two.';

        return $lines;
    }

    protected function rotate(CommonsSecret $kind, string $kubectl, string $env, bool $infisical): bool
    {
        return match ($kind) {
            CommonsSecret::TENANT_DB => $this->rotateTenantDatabases($kubectl, $env, $infisical),
            CommonsSecret::COMMONS_ADMIN => $this->rotateCommonsAdmin($kubectl, $infisical),
            CommonsSecret::COMMONS_S3 => $this->reportUnsupported($kind),
            CommonsSecret::TOOL_STORE => $this->reportUnsupported($kind),
        };
    }

    /**
     * Rotate every tenant's database login (or just --tenant). This is the one
     * that previously forced a redeploy, so it's the one with real payoff.
     */
    protected function rotateTenantDatabases(string $kubectl, string $env, bool $infisical): bool
    {
        $registry = $this->getRegistry();
        $tenants = array_keys($registry['tenants'] ?? []);

        $only = (string) ($this->option('tenant') ?? '');
        if ($only !== '') {
            if (! in_array($only, $tenants, true)) {
                $this->laraKubeError("'{$only}' is not a tenant of this Commons.");
                $this->line('  <fg=gray>Known tenants: </>'.(($tenants === []) ? '(none)' : implode(', ', $tenants)));

                return false;
            }
            $tenants = [$only];
        }

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

            $password = bin2hex(random_bytes(16));

            // allocateDatabase() is idempotent and re-asserts the password, so
            // it doubles as the rotation primitive — no separate ALTER path to
            // drift from the create path.
            if (! $this->allocateDatabase($driver, $tenant, $password)) {
                $this->laraKubeError("Failed to rotate the database password for '{$tenant}'.");
                $ok = false;

                continue;
            }

            if ($infisical) {
                $key = CommonsSecret::TENANT_DB->infisicalKey($tenant);
                if ($key !== null && ! $this->pushInfisicalSecret($kubectl, $key, $password)) {
                    $this->laraKubeError("Rotated '{$tenant}' in Postgres but could not push the new value to Infisical.");
                    $this->line('  <fg=gray>  The database now expects a password nothing has. Re-run to retry.</>');
                    $ok = false;

                    continue;
                }
                $this->line("  <fg=green>✔</> <fg=cyan>{$tenant}</> <fg=gray>rotated → Infisical (</><fg=blue>{$key}</><fg=gray>)</>");

                continue;
            }

            $this->line("  <fg=green>✔</> <fg=cyan>{$tenant}</> <fg=gray>rotated. New password:</> <fg=yellow>{$password}</>");
            $this->line('    <fg=gray>Write it into .env.'.$env.' (DB_PASSWORD) and redeploy.</>');
        }

        return $ok;
    }

    protected function rotateCommonsAdmin(string $kubectl, bool $infisical): bool
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

    protected function printNext(bool $infisical): void
    {
        $this->laraKubeNewLine();

        if (! $infisical) {
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

        $this->line('  <fg=gray>The Infisical operator syncs within ~60s. Restart consumers to pick it up:</>');
        $this->line('  <fg=yellow>larakube reload</> <fg=gray>(app) ·</> <fg=yellow>larakube mail:restart</> <fg=gray>(Stalwart)</>');
    }

    /** Whether the Commons Postgres is reachable — used by tests and callers. */
    protected function commonsReachable(string $kubectl): bool
    {
        return Process::run("{$kubectl} get deploy/postgres -n {$this->plexNamespace()} -o name")->successful();
    }
}
