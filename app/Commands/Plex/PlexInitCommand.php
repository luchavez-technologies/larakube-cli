<?php

namespace App\Commands\Plex;

use App\Contracts\HasPromptableHosts;
use App\Data\GlobalConfigData;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithHosts;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\PromotesIngressDns;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class PlexInitCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithHosts, InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, PromotesIngressDns;

    protected $signature = 'plex:init
        {--services= : Comma-separated services to provision non-interactively, e.g. postgres,redis,meilisearch (no prompt; nothing assumed)}
        {--context= : Target a specific kube-context non-interactively (else you are prompted)}
        {--s3-host= : Public host for the object-storage S3 (creates an ingress; used for tenant AWS_URL)}
        {--from= : Rebuild the Commons from an exported spec file (see plex:export)}';

    protected $description = 'Provision the shared "Commons" (Postgres + Redis) on the current cluster';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Plex — Commons Installer');

        // Target the cluster directly (no context switching) — every Commons op
        // below runs through plexKubectl() against it. An explicit --context wins;
        // otherwise we pick interactively, unless --services makes this run
        // non-interactive (then we stay on the current context).
        if ($this->option('context')) {
            $this->plexContext = (string) $this->option('context');
        } elseif ($this->option('services') === null) {
            $target = $this->askForClusterContext();

            if (! $target) {
                $this->laraKubeError('No Kubernetes context selected.');

                return 1;
            }

            $this->plexContext = $target;
        }

        if (! $this->plexContextReachable()) {
            $this->laraKubeError('The selected cluster is not reachable. Pick a running cluster and retry.');

            return 1;
        }

        $context = $this->plexContext ?: trim((string) shell_exec('kubectl config current-context 2>/dev/null'));
        $this->line("  <fg=gray>Target context:</> <fg=cyan>{$context}</>");
        $this->newLine();

        // 1. Resolve the spec: imported file > existing cluster spec > defaults.
        $spec = $this->resolveSpec();

        if ($spec === null) {
            return 1;
        }

        // 1b. Learn a public host for every enabled service that declares one
        // (HasPromptableHosts — object storage today). Optional; blank = in-cluster.
        $spec = $this->ensurePublicHosts($spec);

        $ns = $this->plexNamespace();
        $enabled = $this->enabledCommonsServices($spec);

        // 2. Namespace (idempotent).
        $kubectl = $this->plexKubectl();
        $this->withSpin("Ensuring namespace {$ns}...", fn () => shell_exec(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        // 3. Admin credentials — generated once, reused on re-run (never rotated).
        $this->ensureCommonsSecret();

        // 4. Render + apply the Commons manifest (spec ConfigMap + services).
        $manifest = view('k8s.plex.commons', [
            'spec' => $spec,
            'specJsonIndented' => $this->indentedSpecJson($spec),
        ])->render();

        $this->withSpin('Applying Commons manifests...', function () use ($manifest, $ns, $kubectl) {
            $tmp = sys_get_temp_dir().'/larakube-plex-commons.yaml';
            file_put_contents($tmp, $manifest);
            passthru("{$kubectl} apply -n {$ns} -f {$tmp}");
            @unlink($tmp);

            return true;
        });

        // 5. Tenant registry — create once, declaratively (so later `apply`s don't
        //    warn about a missing last-applied-configuration), never overwrite.
        if (trim((string) shell_exec("{$kubectl} get configmap plex-registry -n {$ns} -o name 2>/dev/null")) === '') {
            $this->saveRegistry([]);
        }

        // 6. Wait for each enabled service to roll out.
        foreach ($enabled as $service) {
            $this->withSpin("Waiting for {$service} to be ready...", fn () => passthru(
                "{$kubectl} rollout status deploy/{$service} -n {$ns} --timeout=120s",
            ));
        }

        $this->printCommonsReady($spec);

        return 0;
    }

    /**
     * Resolve the spec to apply: an imported file, else the existing cluster
     * spec (reconcile), else fresh defaults.
     */
    protected function resolveSpec(): ?array
    {
        $from = $this->option('from');

        if ($from) {
            if (! file_exists($from)) {
                $this->laraKubeError("Spec file not found: {$from}");

                return null;
            }

            $decoded = json_decode((string) file_get_contents($from), true);

            if (! is_array($decoded)) {
                $this->laraKubeError("Could not parse spec file as JSON: {$from}");

                return null;
            }

            $this->line("  <fg=gray>Rebuilding from spec:</> {$from}");

            return $this->normalizeCommonsSpec($decoded);
        }

        // The catalog (PlexProvisionable) is the source of truth for what can be
        // offered; only plex-ready services are selectable.
        $catalog = $this->commonsServiceCatalog();
        $ready = array_keys(array_filter($catalog, fn ($i) => $i['ready']));

        // An existing Commons re-runs as RECONCILE: pre-select its current
        // services so you can ADD more. A fresh one defaults to the project's
        // plex-ready services (project-aware), else Postgres + Redis.
        $existing = $this->getCommonsSpec();
        $current = $existing !== null ? $this->enabledCommonsServices($existing) : [];
        $default = $existing !== null ? $current : $this->projectDefaultServices($ready);

        // plex:init is ADDITIVE on an existing Commons — re-running never disables
        // a running service (removal isn't wired here): union(current, picked).
        $finalize = fn (array $picked): array => $this->specFromServices(
            array_values(array_unique(array_merge($current, array_intersect($picked, $ready)))),
            $ready,
            $existing,
        );

        // Explicit --services (plex:join's demand-driven bootstrap) is the
        // non-interactive path — exactly what's listed, nothing assumed.
        if ($this->option('services') !== null) {
            return $finalize(array_filter(array_map('trim', explode(',', (string) $this->option('services')))));
        }

        // Show the WHOLE catalog (databases, cache, search, storage), marking the
        // not-yet-wired ones; only ready picks take effect.
        $options = [];
        foreach ($catalog as $service => $info) {
            $options[$service] = $info['ready'] ? $info['label'] : $info['label'].' — not yet available';
        }

        $selected = multiselect(
            label: $existing !== null
                ? 'Which services should the Commons provide? (current ones stay)'
                : 'Which shared services should the Commons provide?',
            options: $options,
            default: array_values(array_intersect($default, array_keys($options))),
            hint: 'Re-running plex:init adds services; it never removes a running one.',
        );

        if ($skipped = array_diff($selected, $ready)) {
            $this->laraKubeWarn('Not available in the Commons yet, skipping: '.implode(', ', $skipped));
        }

        return $finalize($selected);
    }

    /**
     * Default service selection: the current project's plex-ready services when
     * run inside a project (project-aware), else Postgres + Redis.
     *
     * @param  array<int, string>  $ready
     * @return array<int, string>
     */
    protected function projectDefaultServices(array $ready): array
    {
        $config = $this->getProjectConfig(getcwd());

        if ($config !== null && ($services = $this->projectCommonsServices($config))) {
            return $services;
        }

        return array_values(array_intersect(['postgres', 'redis'], $ready));
    }

    /**
     * Build a normalized spec with exactly $selected enabled. Merges onto an
     * existing spec when given (preserving each service's customised
     * image/storage), flipping only the enabled flag for every ready service.
     *
     * @param  array<int, string>  $selected
     * @param  array<int, string>  $ready
     * @param  array<string, mixed>|null  $existing
     */
    protected function specFromServices(array $selected, array $ready, ?array $existing = null): array
    {
        $services = $existing['services'] ?? [];

        foreach ($ready as $svc) {
            $services[$svc] = array_merge($services[$svc] ?? [], ['enabled' => in_array($svc, $selected, true)]);
        }

        return $this->normalizeCommonsSpec([
            'version' => $existing['version'] ?? 1,
            'services' => $services,
        ]);
    }

    /**
     * Resolve a public host for every enabled service that declares one —
     * identified by the HasPromptableHosts contract on its driver (object storage
     * today; Postgres/Redis/search stay in-cluster and are never prompted). Each
     * host-bearing service gets its OWN host (so distinct S3 backends don't
     * collide). Source: --s3-host, an already-set value (kept), an auto-derived
     * "{service}.{tld}" on the local cluster (nothing to prompt for — there's no
     * real DNS locally, and syncClusterHosts() below registers it automatically),
     * or a prompt for a real cluster. Optional — blank leaves the service
     * in-cluster only.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    protected function ensurePublicHosts(array $spec): array
    {
        $catalog = $this->commonsServiceCatalog();
        $isLocal = $this->targetsLocalCluster();
        $tld = GlobalConfigData::load()->getLocalTld();

        foreach (array_keys($spec['services'] ?? []) as $service) {
            if (! ($spec['services'][$service]['enabled'] ?? false)) {
                continue;
            }
            if (! (($catalog[$service]['driver'] ?? null) instanceof HasPromptableHosts)) {
                continue; // no client-facing host → in-cluster only
            }
            if (! empty($spec['services'][$service]['host'])) {
                continue; // already set (reconcile)
            }

            $host = (string) ($this->option('s3-host') ?? '');
            if ($host === '' && $isLocal) {
                $host = "{$service}.{$tld}";
            } elseif ($host === '' && $this->option('services') === null) {
                $host = text(
                    label: "Public host for the Commons '{$service}' (object storage)?",
                    placeholder: 's3.example.com — leave blank for in-cluster only',
                    hint: 'Sets up an ingress + tenant AWS_URL so files get public links.',
                );
            }

            if (trim($host) !== '') {
                $spec['services'][$service]['host'] = trim($host);
            }
        }

        return $spec;
    }

    /** Whether the resolved Plex context is the local k3d cluster. */
    protected function targetsLocalCluster(): bool
    {
        $context = $this->plexContext ?: trim((string) shell_exec('kubectl config current-context 2>/dev/null'));

        return $context === $this->getLaraKubeContext();
    }

    /**
     * Ensure the Commons admin Secret (Postgres password + Meili master key)
     * exists. Generated once; left untouched on re-run so the password is stable.
     */
    protected function ensureCommonsSecret(): void
    {
        $ns = $this->plexNamespace();
        $kubectl = $this->plexKubectl();

        // Generators for every admin credential. S3_ACCESS_KEY is a stable id; the
        // rest are random. (S3 creds were added later, so older Commons secrets
        // are missing them — see the additive patch below.)
        $generators = [
            'POSTGRES_PASSWORD' => fn () => bin2hex(random_bytes(16)),
            'MYSQL_ROOT_PASSWORD' => fn () => bin2hex(random_bytes(16)),
            'MEILI_MASTER_KEY' => fn () => bin2hex(random_bytes(16)),
            'S3_ACCESS_KEY' => fn () => 'larakube',
            'S3_SECRET_KEY' => fn () => bin2hex(random_bytes(16)),
        ];

        $exists = trim((string) shell_exec(
            "{$kubectl} get secret plex-admin -n {$ns} -o name 2>/dev/null",
        )) !== '';

        if (! $exists) {
            $literals = '';
            foreach ($generators as $key => $generate) {
                $literals .= '--from-literal='.$key.'='.escapeshellarg($generate()).' ';
            }
            shell_exec(
                "{$kubectl} create secret generic plex-admin -n {$ns} {$literals}".
                "--dry-run=client -o yaml | {$kubectl} apply -f -",
            );

            return;
        }

        // Existing secret: ADD any missing keys (e.g. S3 creds on a Commons that
        // predates them) but NEVER rotate the ones already there — rotating
        // POSTGRES_PASSWORD would desync from the running Postgres (its password is
        // set only on first init).
        $patch = [];
        foreach ($generators as $key => $generate) {
            $present = trim((string) shell_exec(
                "{$kubectl} get secret plex-admin -n {$ns} -o jsonpath=".escapeshellarg('{.data.'.$key.'}').' 2>/dev/null',
            )) !== '';

            if (! $present) {
                $patch[$key] = base64_encode($generate());
            }
        }

        if (! empty($patch)) {
            shell_exec(
                "{$kubectl} patch secret plex-admin -n {$ns} --type merge -p ".
                escapeshellarg((string) json_encode(['data' => $patch])),
            );
        }
    }

    /**
     * Pretty-print the spec as JSON, indented for a YAML block scalar.
     */
    protected function indentedSpecJson(array $spec): string
    {
        $json = (string) json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return preg_replace('/^/m', '    ', $json);
    }

    /**
     * Print the in-cluster hosts tenants will point at, plus next steps.
     */
    protected function printCommonsReady(array $spec): void
    {
        $ns = $this->plexNamespace();

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Commons is ready.');
        $this->line('  Tenants reach these in-cluster hosts:');

        $publicHosts = [];
        foreach ($spec['services'] ?? [] as $service => $cfg) {
            if (! ($cfg['enabled'] ?? false)) {
                continue;
            }
            $this->line("    <fg=cyan>{$service}.{$ns}.svc.cluster.local:".($cfg['port'] ?? '').'</>');
            if (! empty($cfg['host'])) {
                $this->line("      <fg=gray>public:</> <fg=cyan>https://{$cfg['host']}</>");
                $publicHosts[] = (string) $cfg['host'];
            }
        }

        // A Commons is multi-tenant by design — every app that joins adds another
        // host on this same ingress IP, so promote the CNAME-anchor pattern —
        // unless it's the local cluster, where there's no real DNS to anchor:
        // just register the host(s) directly (same automated, no-prompt
        // primitive `up` uses for Mailpit/Grafana/Console).
        if ($publicHosts !== []) {
            if ($this->targetsLocalCluster()) {
                if ($this->isWsl()) {
                    $this->syncWindowsHosts($publicHosts, 'larakube-plex');
                }
                $this->syncHostsEntries($publicHosts, 'larakube-plex');
            } else {
                $this->printIngressDnsGuidance($publicHosts, $this->traefikLoadBalancerIp($this->plexContext));
            }
        }

        $this->newLine();
        $this->line('  Save the spec for disaster recovery: <fg=yellow>larakube plex:export</>');
    }
}
