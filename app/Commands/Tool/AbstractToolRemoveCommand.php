<?php

namespace App\Commands\Tool;

use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\RefusesUnshippedTools;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolHost;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;
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
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithPlex, LaraKubeOutput, RefusesUnshippedTools, RequiresFlagsWhenNonInteractive, ResolvesToolHost, SyncsClusterSecrets;

    /** The instance the teardown loop is currently removing; null outside handle(). */
    protected ?string $currentInstance = null;

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
        $kubectl = $this->contextKubectl($context);
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

        if (! $this->confirmDestructive($this->teardownWarning($env))) {
            return 0;
        }

        $ok = true;

        foreach ($targets as $targetInstance) {
            $this->currentInstance = $targetInstance;

            if ($isPurging) {
                $ok = $this->dropCommonsTenants($kubectl, $targetInstance) && $ok;
            }

            $ok = $this->teardown($kubectl, $namespace) && $ok;

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
     * Which instance(s) to remove.
     * 1. --domain given → target instances registered for that domain/host.
     * 2. --all given → target every registered instance of this tool.
     * 3. Interactive with multiple registered instances → prompt select choice.
     * 4. Single/unregistered → default to the tool's registered instance, or null.
     *
     * @return list<string>
     */
    protected function resolveInstanceTargets(string $kubectl): array
    {
        $domain = (string) ($this->option('domain') ?: '');
        if ($domain !== '') {
            return $this->resolveInstanceTargetsForDomain($kubectl, $this->tool(), $domain);
        }

        $tool = $this->tool();

        if ($this->hasOption('all') && $this->option('all')) {
            $instances = $this->getToolInstances($kubectl, $tool);

            if ($instances !== []) {
                return $instances;
            }

            // Removal is read-only targeting, not installation — never write a
            // fresh registry stub just to compute a fallback instance slug,
            // and never GUESS one via instanceSlugFromHost() either: it always
            // derives a real, non-empty slug (ADR 0012, amended 2026-08-15),
            // which would target resources that don't exist for a legacy,
            // pre-registry deployment. null is what every teardown method
            // below already treats as "this tool's
            // own unsuffixed default" — the exact same value the plain
            // no-flags branch a few lines down already falls back to.
            return [null];
        }

        $registered = array_values(array_filter(
            $this->getRegisteredTools($kubectl),
            fn (array $e) => ($e['tool'] ?? null) === $tool->value,
        ));

        if (count($registered) > 1 && ! $this->cannotPrompt()) {
            $options = [];
            foreach ($registered as $entry) {
                // A registry entry missing its own 'instance' key is a
                // malformed/legacy write — '' (this tool's default), never a
                // guessed slug, matches every other fallback in this file.
                $inst = (string) ($entry['instance'] ?? '');
                $host = (string) ($entry['host'] ?? '');
                $label = $host !== '' ? "{$inst} ({$host})" : $inst;
                $options[$inst] = $label;
            }
            $options['__all__'] = 'All instances';

            $choice = select(
                label: "Which {$tool->getLabel()} instance would you like to remove?",
                options: $options,
            );

            if ($choice === '__all__') {
                return array_values(array_filter(array_keys($options), fn ($k) => $k !== '__all__'));
            }

            return [$choice];
        }

        if (count($registered) > 1) {
            // Reaching here means cannotPrompt() was true — the branch above
            // already handles the interactive multi-instance case with a
            // select() prompt. Silently picking $registered[0] here (the old
            // behaviour) meant a non-interactive run could tear down the
            // wrong instance without the operator ever being told there was
            // a choice to make. Same "fail loud, don't guess" philosophy as
            // ResolvesToolHost::resolveNonInteractiveHost().
            throw new RuntimeException(
                "Multiple {$tool->getLabel()} instances are registered, and this command is running ".
                'non-interactively, so which one to remove cannot be guessed. '.
                'Pass --domain=<host> to target one, or --all to remove every registered instance.',
            );
        }

        if ($registered !== []) {
            $firstInst = (string) ($registered[0]['instance'] ?? '');

            return [$firstInst !== '' ? $firstInst : null];
        }

        return [null];
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
    protected function dropCommonsTenants(string $kubectl, ?string $instance = null): bool
    {
        $tool = $this->tool();
        $databases = $tool->commonsDatabases($instance);
        $buckets = $tool->commonsBuckets($instance);

        if (($databases === [] && $buckets === []) || $this->usesBundledStorage($kubectl, $tool->namespace())) {
            return true;
        }

        if ($this->preservesBucketsOnPurge()) {
            $buckets = [];
        }

        $ok = true;
        $plexNs = $this->plexNamespace();
        $client = DatabaseDriver::POSTGRESQL->commonsAdminClient();

        foreach ($databases as $database) {
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

        foreach ($tool->commonsRedisKeys() as $key) {
            $redisTenant = ($instance === null || $instance === '') ? $key : "{$key}_{$instance}";
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
        return trim(Process::run(
            "{$kubectl} get deployment {$deployment} -n {$namespace} --no-headers --ignore-not-found",
        )->output()) !== '';
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
}
