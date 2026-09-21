<?php

namespace App\Commands\Tool;

use App\Data\ResourceRef;
use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;
use App\Services\Kubectl;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\DeregistersSsoApp;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithSso;
use App\Traits\InteractsWithZitadelApi;
use App\Traits\LaraKubeOutput;
use App\Traits\RefusesUnshippedTools;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolHost;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;
use LogicException;
use RuntimeException;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * Base for every `{tool}:remove {environment}` command.
 *
 * Replaces the `{tool}:init --remove` flag that used to hide teardown inside
 * the install command. Two reasons that was wrong, beyond the mental map:
 * `--remove` silently ignored every install-shaped flag next to it
 * (`{tool}:init --remove --domain=x` looked meaningful and wasn't), and the
 * install and teardown paths shared a signature but no code, so they drifted.
 *
 * What this base owns — identical for all 24 tools, previously copy-pasted:
 *   environment → kube-context resolution, the destructive confirmation, the
 *   Plex Commons database drop, the Commons Redis index release, unregistering
 *   from the cluster tool registry, and honest exit codes.
 *
 * What a subclass owns: `tool()`, and `teardown()` for the resource list only.
 * Concrete teardowns are moved across verbatim from the old `remove*()` methods
 * because those encode real, hard-won fixes (see DeploysClusterTool's docblock)
 * that a generic "delete everything labelled X" rewrite would quietly lose.
 */
abstract class AbstractToolRemoveCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, DeregistersSsoApp, InteractsWithPlex, InteractsWithSso, InteractsWithZitadelApi, LaraKubeOutput, RefusesUnshippedTools, RequiresFlagsWhenNonInteractive, ResolvesToolHost, SyncsClusterSecrets;

    /** The instance the teardown loop is currently removing; null outside handle(). */
    protected ?string $currentInstance = null;

    /** @var list<string|null>|null */
    private ?array $resolvedTargets = null;

    private ?Kubectl $cluster = null;

    public function __construct()
    {
        if (static::class === self::class) {
            parent::__construct();

            return;
        }

        $tool = $this->tool();

        if (empty($this->signature)) {
            $this->signature = "{$tool->value}:remove ".
                '{environment=local : Environment to remove '.$tool->getLabel().' from} '.
                '{--context= : Target a specific kube-context (defaults to the environment\'s saved cloud target)} '.
                '{--domain= : The instance\'s host, to target a specific one (e.g. --domain=blog.example.com). Omit for the default instance} '.
                '{--all : Remove all registered instances of this tool} '.
                '{--purge : Also destroy persistent data — drop the Plex Commons database and release the Redis index. Irreversible.} '.
                '{--force : Skip the confirmation prompt (required for non-interactive runs)}';
        }

        $this->description ??= "Remove {$tool->getLabel()} from a cluster";

        parent::__construct();
    }

    public function handle(): int
    {
        $tool = $this->tool();

        if ($this->refuseUnshippedTool($tool)) {
            return 1;
        }

        $this->renderHeader();

        $env = (string) $this->argument('environment');

        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        // InteractsWithPlex talks to the Commons through its own kubectl; point
        // it at the same cluster or we'd drop a tenant from the WRONG Commons.
        $this->plexContext = $context;

        // contextKubectl() pins KUBECONFIG to ~/.kube/config — every tool's own
        // *Kubectl() helper did this, and a bare `kubectl` would silently follow
        // an ambient $KUBECONFIG to a different cluster than the one we resolved.
        $kubectl = Kubectl::forContext($context)->prefix();
        $this->cluster = Kubectl::forContext(($context ?? '') !== '' ? $context : null);
        $namespace = $tool->namespace();
        $isPurging = (bool) $this->option('purge');

        if ((string) $this->option('domain') !== '' && ! $tool->hasInstanceAwareRemoval()) {
            $this->laraKubeError(
                "{$tool->getLabel()} does not support multiple instances yet — ".
                '--domain would silently do nothing (or worse, a misleading partial removal) since its '.
                'teardown targets fixed resource names. Remove without --domain.',
            );

            return 1;
        }

        // Every instance serving the targeted host — normally exactly one
        // (the tool's sole registered instance when --domain is omitted). A
        // host registered under MORE than one instance is a duplicate-registration artifact (the DATA
        // incident of 2026-08-09): removal means "take down everything
        // serving this host", so all matching instances go.
        $targets = $this->resolveInstanceTargets($kubectl);

        if ($targets === []) {
            $this->laraKubeInfo("No {$tool->getLabel()} instances are registered in '{$env}', so there is nothing to remove.");
            $this->line("  <fg=gray>Deployed but not listed? Run</> <fg=yellow>larakube tool:list {$env} --refresh</><fg=gray>, then try again.</>");

            return 0;
        }

        $hosts = $this->targetHosts($kubectl, $targets);
        $warning = $this->teardownWarning($env);
        if ($hosts !== []) {
            array_splice($warning, 1, 0, ['Instance(s): '.implode(', ', $hosts)]);
        }

        if (! $this->confirmDestructive($warning)) {
            return 0;
        }

        $ok = true;

        foreach ($targets as $targetInstance) {
            $this->currentInstance = $targetInstance;

            if ($isPurging) {
                $ok = $this->dropCommonsTenants($kubectl, $targetInstance) && $ok;
            }

            // A row with no instance predates hosts being identity, so there is
            // no Secret named for it holding the Zitadel ids — nothing to
            // deregister.
            if (($targetInstance ?? '') !== '' && $tool->hasSsoWire() && ! $tool->usesForwardAuth()) {
                $this->deregisterSsoApp($tool, $targetInstance, $kubectl, $env);
            }

            $ok = $this->teardown($kubectl, $namespace) && $ok;
            $this->removeDatabaseSecretSync($kubectl, $targetInstance);

            $this->unregisterTool($kubectl, $tool, $targetInstance);
        }
        $this->currentInstance = null;

        if (! $ok) {
            $this->laraKubeError(
                "One or more {$tool->getLabel()} resources failed to remove — "
                .'check kubectl access to the cluster above and re-run.',
            );

            return 1;
        }

        if ($isPurging) {
            $this->laraKubeInfo("{$tool->getLabel()} removed from {$namespace} in '{$env}' (Commons database destroyed).");
        } else {
            $this->laraKubeInfo("{$tool->getLabel()} removed from {$namespace} in '{$env}'.");
            $this->line('  <fg=gray>Note:</> Persistent data (Plex Commons DB + S3 buckets) was preserved.');
            $this->line("  To restore, re-run <fg=blue>larakube {$tool->value}:init</>. To destroy data, re-run with <fg=yellow>--purge</>.");
        }

        return 0;
    }

    /** The tool this command tears down. */
    abstract protected function tool(): ClusterTool;

    /**
     * Delete $refs (missing ones are fine) behind a spinner, reporting
     * kubectl's error; false on failure so teardown() can say so.
     *
     * @param  list<ResourceRef>  $refs
     */
    protected function deleteResources(string $label, array $refs): bool
    {
        return $refs === [] || $this->kubectlStep($label, fn () => $this->cluster()->delete(...$refs));
    }

    /** The cluster this removal targets, the same one $kubectl points at. */
    protected function cluster(): Kubectl
    {
        return $this->cluster ?? throw new LogicException('cluster() is only available once handle() has resolved the context.');
    }

    /**
     * Which instance(s) to remove, read from the tool registry.
     * 1. --domain given → the instances registered for that host.
     * 2. Nothing registered → none (handle() reports nothing to remove).
     * 3. --all given → every registered instance.
     * 4. Interactive → a picker of the registered instances by host.
     * 5. Non-interactive → the only registered instance; several is an error.
     *
     * Cached: teardown() re-resolves through resolveInstance(), which must
     * never prompt a second time.
     *
     * @return list<string|null>
     */
    protected function resolveInstanceTargets(string $kubectl): array
    {
        return $this->resolvedTargets ??= $this->pickInstanceTargets($kubectl);
    }

    /**
     * The instance the current teardown step is targeting — the loop
     * instance when handle() runs over several (duplicate-host removal),
     * otherwise the first (derived/default) target.
     */
    protected function resolveInstance(string $kubectl): ?string
    {
        if ($this->currentInstance !== null) {
            return $this->currentInstance;
        }

        return $this->resolveInstanceTargets($kubectl)[0];
    }

    /**
     * Delete this tool's Kubernetes resources. Return false on any failed step
     * so the command exits non-zero instead of printing a false "removed".
     */
    abstract protected function teardown(string $kubectl, string $namespace): bool;

    /**
     * The red block shown before teardown. Subclasses override to name the
     * specific workloads/volumes at risk.
     *
     * @return list<string>
     */
    protected function teardownWarning(string $env): array
    {
        $tool = $this->tool();
        $isPurging = (bool) $this->option('purge');

        $lines = [
            "{$tool->getLabel()} will be REMOVED from '{$env}':",
            "Deployments, Services, Ingresses and Secrets in {$tool->namespace()}",
        ];

        $databases = $tool->commonsDatabases();
        $buckets = $this->preservesBucketsOnPurge() ? [] : $tool->commonsBuckets();

        if ($isPurging && ($databases !== [] || $buckets !== [])) {
            if ($databases !== []) {
                $lines[] = 'Plex Commons database(s) WILL BE DESTROYED: '.implode(', ', $databases);
            }
            if ($buckets !== []) {
                $lines[] = 'Plex Commons S3 bucket(s) WILL BE DESTROYED, contents included: '.implode(', ', $buckets);
            }
        } else {
            $lines[] = 'Persistent data (Plex Commons DB + S3 buckets) WILL BE PRESERVED.';
        }

        return $lines;
    }

    /**
     * Drop this tool's Commons Postgres tenant(s), release any Commons Redis
     * index, AND drop its Commons S3 bucket(s). Skipped entirely when the
     * install bundled its own storage (`--no-plex`) — detected by the
     * subclass via usesBundledStorage(), because dropping Commons resources
     * this install never leased would destroy a DIFFERENT tool's data if the
     * names ever collided.
     *
     * The bucket step used to be missing — `--purge` dropped the database but
     * silently left every tool's S3 bucket (and its contents) behind, so
     * "purge" under-delivered on its own promise for every tool that stores
     * files in the Commons (Design, Sheet, CRM, Resume, Record, Chat, Mail,
     * GitForge, Sign — plus Drive, which has NO Commons database at all, so
     * the old `$databases === []` early return skipped its bucket drop
     * unconditionally, no matter what).
     */
    /** Does this Commons tenant actually exist? Distinguishes "dropped" from "was never there". */
    protected function commonsDatabaseExists(string $kubectl, string $plexNs, string $database): bool
    {
        $client = DatabaseDriver::POSTGRESQL->commonsAdminClient();

        return trim(Process::run(
            "{$kubectl} exec deploy/postgres -n {$plexNs} -- {$client} -U postgres -tAc "
            .escapeshellarg('SELECT 1 FROM pg_database WHERE datname = '.$this->quoteSqlLiteral($database)),
        )->output()) === '1';
    }

    /** Single-quoted SQL literal, doubling any embedded quote. */
    protected function quoteSqlLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    protected function dropCommonsTenants(string $kubectl, ?string $instance = null): bool
    {
        $tool = $this->tool();
        $engine = $this->instanceEngine($kubectl, $instance);
        $databases = $tool->commonsDatabases($instance, $engine);
        $buckets = $tool->commonsBuckets($instance, $engine);

        $redisTenants = $tool->commonsRedisTenants($instance);

        if (($databases === [] && $buckets === [] && $redisTenants === []) || $this->usesBundledStorage($kubectl, $tool->namespace())) {
            return true;
        }

        if ($this->preservesBucketsOnPurge()) {
            $buckets = [];
        }

        $ok = true;
        $plexNs = $this->plexNamespace();
        $client = DatabaseDriver::POSTGRESQL->commonsAdminClient();

        foreach ($databases as $database) {
            // Reported, never used to skip: DROP DATABASE IF EXISTS succeeds on a
            // name that never existed, so a purge computing the wrong tenant
            // reports success and drops nothing. Silence there reads as "data
            // destroyed" when it means the opposite. The drop still runs either
            // way — a probe that cannot reach Postgres must not stop a teardown.
            if (! $this->commonsDatabaseExists($kubectl, $plexNs, $database)) {
                $this->laraKubeWarn("No Commons database named '{$database}' — nothing to drop.");
                $this->line('  <fg=gray>If this tool has data, its tenant is under a different name and survives this purge.</>');
            }

            $sql = $this->buildDropTenantSql($database, $database);
            $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
            $tmp = $temporaryDirectory->path().'/drop.sql';
            file_put_contents($tmp, $sql);

            $dropped = $this->removeResources(
                "Dropping database '{$database}' from Plex Commons (if exists)...",
                "{$kubectl} exec -i -n {$plexNs} deploy/postgres -- sh -c "
                .escapeshellarg($client).' < '.escapeshellarg($tmp),
            );
            $ok = $dropped && $ok;

            // Only release OpenBao's rotation-managed role once the database
            // it was rotating actually got dropped — Postgres refuses DROP
            // DATABASE while there are active connections (the ordinary case
            // for a tool being --purge'd, since it's normally still live),
            // and deleting the role unconditionally here orphaned it while
            // the tenant kept running: OpenBao stopped rotating a password
            // its still-live consumer depended on. Confirmed live 2026-08-23
            // on 4 tools (stalwart, record_sendrec, resume_reactive, and
            // sheet's role) — see plans/active/openbao-static-role-coverage.md.
            if ($dropped) {
                $this->deleteStaticRole($kubectl, $database);
            }

            $this->unregisterTenant($database);

            $temporaryDirectory->delete();
        }

        $tenantKey = ($instance === null || $instance === '') ? $tool->value : "{$tool->value}_{$instance}";
        $this->unregisterTenant($tenantKey);

        foreach ($redisTenants as $redisTenant) {
            $this->releaseCommonsRedisIndex($redisTenant);
        }

        foreach ($buckets as $bucket) {
            $ok = $this->dropCommonsBucket($kubectl, $plexNs, $bucket) && $ok;
        }

        return $ok;
    }

    /**
     * Drop one Commons S3 bucket (and its contents — irreversible). The
     * backend (SeaweedFS/MinIO/Garage) it lives on is read from the tenant
     * registry the same allocateStorageBucket() wrote it to; a pre-registry
     * install falls back to whichever S3 service the live Commons spec has
     * enabled, the same discovery order every {tool}:init uses to pick one
     * in the first place. No backend found (Commons has no S3 at all) isn't
     * a failure — there is nothing to drop.
     */
    protected function dropCommonsBucket(string $kubectl, string $plexNs, string $bucket): bool
    {
        $registry = $this->getRegistry();
        $service = $registry['tenants'][$bucket]['s3_service'] ?? null;

        if ($service === null) {
            $spec = $this->getCommonsSpec() ?? ['services' => []];
            foreach (['seaweedfs', 'minio', 'garage'] as $candidate) {
                if (in_array($candidate, $this->enabledCommonsServices($spec), true)) {
                    $service = $candidate;
                    break;
                }
            }
        }

        $driver = $service !== null ? StorageDriver::tryFrom($service) : null;
        if ($driver === null) {
            return true;
        }

        $cmd = $driver->commonsBucketDeleteCommand($bucket);
        $ok = $this->removeResources(
            "Dropping object-storage bucket '{$bucket}' from Plex Commons (if exists)...",
            "{$kubectl} exec -n {$plexNs} deploy/{$service} -- sh -c ".escapeshellarg($cmd),
        );

        $this->unregisterTenant($bucket);

        return $ok;
    }

    /**
     * The engine $instance runs, for tools with more than one, read from the
     * live cluster. null means unknown, so every engine's tenants are covered.
     */
    protected function instanceEngine(string $kubectl, ?string $instance): ?string
    {
        return null;
    }

    /**
     * True when this install bundled its own database instead of leasing a
     * Commons tenant, so there is no Commons tenant to drop. Default: never
     * bundled. Tools with `--no-plex` override this with the same probe their
     * old remove path used (looking for the bundled DB Deployment / a secret).
     */
    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return false;
    }

    /**
     * True when this tool's bucket contents must survive `--purge` even
     * though it genuinely uses the Commons (unlike usesBundledStorage(),
     * which means "there's nothing here to drop" — this means "there is,
     * but dropping it would be unsafe"). Exists for Drive: oCIS wraps each
     * file's encryption key with drive-secrets' rekey key, so deleting the
     * bucket without also handling per-file re-encryption would orphan data
     * no re-init could recover — a mistyped `drive:remove --purge` must not
     * be able to destroy files. Default: buckets purge normally.
     */
    protected function preservesBucketsOnPurge(): bool
    {
        return false;
    }

    /** Shared helper for the common "does this Deployment exist" bundled-storage probe. */
    protected function deploymentExists(string $kubectl, string $namespace, string $deployment): bool
    {
        return Kubectl::fromPrefix($kubectl)->hasDeployment($namespace, $deployment);
    }

    /**
     * Build a single `kubectl delete` command deleting every component's
     * Deployment plus its declared companion resources — the generic form
     * of what compound tools' teardown() used to hand-copy independently of
     * the Blade manifest that actually deploys them. Every resource carries
     * `--ignore-not-found`, so a component that doesn't exist for this
     * install (e.g. a --no-plex-only bundled component on a Plex-backed
     * install) is silently skipped rather than needing its own condition
     * here — same discipline the hand-written strings this replaces relied on.
     */
    protected function teardownComponentsCommand(string $kubectl, string $namespace, ?string $instance = null): string
    {
        $refs = [];
        foreach ($this->tool()->components($instance) as $component) {
            $refs[] = "deployment/{$component->deployment}";
            foreach ($component->resources as $resource) {
                $refs[] = "{$resource['kind']}/{$resource['name']}";
            }
        }

        return "{$kubectl} delete ".implode(' ', $refs)." -n {$namespace} --ignore-not-found";
    }

    /**
     * `secrets:wire` syncs the database password into the tool's DB Secret with
     * an ExternalSecret and a generator, both named `<db secret>-db`. Once the
     * tool is gone they only retry forever against a Secret (and, after a
     * purge, an OpenBao role) that no longer exists.
     */
    private function removeDatabaseSecretSync(string $kubectl, ?string $instance): void
    {
        $ref = $this->tool()->dbSecretRef($instance);
        if ($ref === null) {
            return;
        }

        Process::run(
            "{$kubectl} delete externalsecret,vaultdynamicsecret.generators.external-secrets.io {$ref['secret']}-db "
            ."-n {$ref['namespace']} --ignore-not-found",
        );
    }

    /**
     * @return list<string|null>
     */
    private function pickInstanceTargets(string $kubectl): array
    {
        $tool = $this->tool();

        $domain = (string) ($this->option('domain') ?: '');
        if ($domain !== '') {
            return $this->resolveInstanceTargetsForDomain($kubectl, $tool, $domain);
        }

        $registered = array_values(array_filter(
            $this->getRegisteredTools($kubectl),
            fn (array $e) => ($e['tool'] ?? null) === $tool->value,
        ));

        // A row without an instance is this tool's unsuffixed default (null).
        $instances = array_values(array_unique(array_map(
            fn (array $e) => (string) ($e['instance'] ?? ''),
            $registered,
        )));
        $asTarget = fn (string $inst): ?string => $inst !== '' ? $inst : null;

        if ($instances === []) {
            return [];
        }

        if ($this->hasOption('all') && $this->option('all')) {
            return array_map($asTarget, $instances);
        }

        if (! $this->cannotPrompt()) {
            $options = [];
            foreach ($registered as $entry) {
                $inst = (string) ($entry['instance'] ?? '');
                $host = (string) ($entry['host'] ?? '');
                $options[$inst] ??= $host !== '' ? $host : ($inst !== '' ? $inst : 'default instance');
            }
            if (count($options) > 1) {
                $options['__all__'] = 'All instances';
            }

            $choice = (string) select(
                label: "Which {$tool->getLabel()} instance would you like to remove?",
                options: $options,
            );

            return $choice === '__all__'
                ? array_map($asTarget, $instances)
                : [$asTarget($choice)];
        }

        if (count($instances) > 1) {
            // Guessing here could tear down the wrong instance unseen.
            throw new RuntimeException(
                "Multiple {$tool->getLabel()} instances are registered, and this command is running ".
                'non-interactively, so which one to remove cannot be guessed. '.
                'Pass --domain=<host> to target one, or --all to remove every registered instance.',
            );
        }

        return [$asTarget($instances[0])];
    }

    /**
     * The registered hosts of the targeted instances, for the confirmation.
     *
     * @param  list<string|null>  $targets
     * @return list<string>
     */
    private function targetHosts(string $kubectl, array $targets): array
    {
        $wanted = array_map(fn (?string $t): string => (string) $t, $targets);

        return array_values(array_unique(array_filter(array_map(
            fn (array $e): string => in_array((string) ($e['instance'] ?? ''), $wanted, true) ? (string) ($e['host'] ?? '') : '',
            array_filter($this->getRegisteredTools($kubectl), fn (array $e) => ($e['tool'] ?? null) === $this->tool()->value),
        ))));
    }
}
