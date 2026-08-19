<?php

namespace App\Commands\Plex;

use App\Contracts\HasPromptableHosts;
use App\Data\GlobalConfigData;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithHosts;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithTraefik;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesLocalCa;
use App\Traits\PromotesIngressDns;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class PlexInitCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithHosts, InteractsWithPlex, InteractsWithProjectConfig, InteractsWithTraefik, LaraKubeOutput, ManagesLocalCa, PromotesIngressDns, StreamsProcessOutput, SyncsClusterSecrets;

    protected $signature = 'plex:init
        {environment? : Environment this Commons install targets — "local" (default) or a cloud env. Omit to be prompted (when run from inside a project) or pick a raw kube-context (when not).}
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
        // below runs through plexKubectl() against it. An explicit --context
        // always wins. Otherwise, {environment} resolves to a context the same
        // way every other {tool}:init does — prompting for local vs. a known
        // cloud env when a project exists in cwd, so plex:init gives the same
        // "which environment, not just which raw context" information every
        // other provisioning command already does. --services signals a
        // non-interactive run (matches the prior behavior: stay on local/the
        // current context). With no project to resolve an environment against
        // (e.g. bootstrapping Commons on a bare cluster before any project
        // exists), fall back to the original raw kube-context picker.
        if ($this->option('context')) {
            $this->plexContext = (string) $this->option('context');
        } elseif ($this->option('services') !== null) {
            $this->plexContext = $this->resolveToolContext($this->argument('environment') ?: 'local');
        } else {
            $config = $this->getProjectConfig(getcwd());

            if ($config !== null) {
                $this->plexContext = $this->resolveToolContext($this->resolvePlexEnvironment($config));
            } else {
                $target = $this->askForClusterContext();

                if (! $target) {
                    $this->laraKubeError('No Kubernetes context selected.');

                    return 1;
                }

                $this->plexContext = $target;
            }
        }

        if (! $this->plexContextReachable()) {
            $this->laraKubeError('The selected cluster is not reachable. Pick a running cluster and retry.');

            return 1;
        }

        $context = $this->plexContext ?: trim(Process::run($this->kubectl().' config current-context')->output());
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
        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        // 3. Admin credentials — generated once, reused on re-run (never rotated).
        $this->ensureCommonsSecret();

        // 4. Render + apply the Commons manifest (spec ConfigMap + services).
        $manifest = view('k8s.plex.commons', [
            'spec' => $spec,
            'specJsonIndented' => $this->indentedSpecJson($spec),
            'isLocal' => $this->targetsLocalCluster(),
        ])->render();

        $this->withSpin('Applying Commons manifests...', function () use ($manifest, $ns, $kubectl) {
            $temporaryDirectory = TemporaryDirectory::make();
            $tmp = $temporaryDirectory->path('larakube-plex-commons.yaml');
            file_put_contents($tmp, $manifest);
            $this->runStreaming("{$kubectl} apply -n {$ns} -f {$tmp}");
            $temporaryDirectory->delete();

            return true;
        });

        // 5. Tenant registry — create once, declaratively (so later `apply`s don't
        //    warn about a missing last-applied-configuration), never overwrite.
        if (trim(Process::run("{$kubectl} get configmap plex-registry -n {$ns} -o name")->output()) === '') {
            $this->saveRegistry([]);
        }

        // 6. Wait for each enabled service to roll out.
        foreach ($enabled as $service) {
            $this->withSpin("Waiting for {$service} to be ready...", fn () => $this->runStreaming(
                "{$kubectl} rollout status deploy/{$service} -n {$ns} --timeout=120s",
                130,
            ));
        }

        // 6b. Garage needs a one-time layout assignment + shared admin key
        // creation before it can accept bucket operations — unlike MinIO's
        // env-injected root creds, Garage generates its key server-side.
        // Idempotent: skipped once already done, so re-running plex:init
        // never re-assigns the layout or rotates the key.
        if (in_array('garage', $enabled, true)) {
            $this->ensureGarageBootstrap($ns, $kubectl);
        }

        // 7. Wire the OpenBao Database Secrets Engine if OpenBao is present.
        if ($this->secretsBackendAvailable($kubectl)) {
            $this->wireDatabaseEngineToOpenBao($kubectl, $enabled);
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
            if (empty($spec['services'][$service]['host'])) {
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

            // Some backends have a second, local-only management host — MinIO's
            // web console (port 9001) and SeaweedFS's master admin UI (port
            // 9333). Both give full cross-tenant visibility (MinIO via its
            // shared root creds, SeaweedFS with no auth at all — see
            // commons.blade.php), so — unlike the S3 host above — these are
            // ONLY ever auto-derived for a local dev cluster, never prompted
            // for or set on a real cluster. Runs even when 'host' was already
            // set (reconcile), so an existing Commons picks it up too.
            $managementHosts = [
                'minio' => ['field' => 'console_host', 'label' => 'console'],
                'seaweedfs' => ['field' => 'admin_host', 'label' => 'admin'],
            ];

            if ($isLocal && isset($managementHosts[$service])) {
                $field = $managementHosts[$service]['field'];
                $label = $managementHosts[$service]['label'];

                if (empty($spec['services'][$service][$field])) {
                    $spec['services'][$service][$field] = "{$service}-{$label}.{$tld}";
                }
            }
        }

        return $spec;
    }

    /**
     * One-time Garage bootstrap: assign + apply a single-node layout (required
     * before ANY bucket/key operation works), then create the one shared
     * "commons-admin" key every tenant bucket gets granted to (mirrors MinIO's
     * shared root-credential model — but Garage generates its key server-side;
     * there's no way to pre-set it the way MinIO's env-injected root user/pass
     * can be). Both steps are idempotent: layout readiness is checked via exit
     * code (a real bucket operation either works or it doesn't — far more
     * reliable than matching `garage status`'s exact wording, which varies
     * across versions), and the key is looked up by name before creating one.
     */
    protected function ensureGarageBootstrap(string $ns, string $kubectl): void
    {
        $lastExit = 0;
        $exec = function (string $cmd) use ($kubectl, $ns, &$lastExit): string {
            $result = Process::run(
                "{$kubectl} exec -n {$ns} deploy/garage -- sh -c ".escapeshellarg($cmd),
            );
            $lastExit = $result->exitCode();

            return trim($result->output().$result->errorOutput());
        };

        $exec('/garage bucket list');
        $layoutReady = $lastExit === 0;

        if (! $layoutReady) {
            $status = $exec('/garage status');
            // The node ID is the long hex string starting each data row under
            // "==== HEALTHY NODES ====".
            preg_match('/^([0-9a-f]{16,})\s/m', $status, $m);
            $nodeId = $m[1] ?? null;

            if ($nodeId === null) {
                $this->laraKubeWarn('Could not determine the Garage node ID from `garage status` — skipping layout bootstrap.');
                $this->laraKubeLine('  Re-run `larakube plex:init` once Garage has fully started, or assign the layout manually: `larakube exec --service=garage "/garage status"`.');

                return;
            }

            $applied = $this->withSpin('Assigning Garage single-node layout...', function () use ($exec, $nodeId, &$lastExit) {
                $exec("/garage layout assign {$nodeId} --capacity 1GB --zone commons --tag commons");
                $exec('/garage layout apply --version 1');

                return $lastExit === 0;
            });

            if (! $applied) {
                $this->laraKubeWarn('Garage layout assignment failed — bucket operations will fail until this is resolved manually.');

                return;
            }
        }

        $keyList = $exec('/garage key list');
        if (str_contains($keyList, 'commons-admin')) {
            return; // Already created — never rotate.
        }

        $keyOutput = '';
        $created = $this->withSpin('Creating the shared Garage admin key...', function () use ($exec, &$keyOutput, &$lastExit) {
            $keyOutput = $exec('/garage key create commons-admin');

            return $lastExit === 0;
        });

        if (! $created) {
            $this->laraKubeWarn('Could not create the Garage admin key — bucket operations will fail until this is resolved.');
            $this->laraKubeLine('  '.$keyOutput);

            return;
        }

        preg_match('/Key ID:\s*(\S+)/i', $keyOutput, $accessMatch);
        preg_match('/Secret key:\s*(\S+)/i', $keyOutput, $secretMatch);
        $access = $accessMatch[1] ?? null;
        $secret = $secretMatch[1] ?? null;

        if ($access === null || $secret === null) {
            $this->laraKubeWarn('Created the Garage admin key but could not parse its credentials from the output.');
            $this->laraKubeLine('  Run `larakube exec --service=garage "/garage key info commons-admin --show-secret"` to retrieve them, then store into the plex-admin Secret\'s S3_ACCESS_KEY/S3_SECRET_KEY.');

            return;
        }

        // Same "add if missing, never rotate" pattern ensureCommonsSecret() uses.
        // If MinIO already claimed these fields first, Garage's tenants would
        // get the wrong credentials — enabling more than one credentialed S3
        // backend in the same Commons at once isn't fully supported yet.
        $existing = trim(Process::run(
            "{$kubectl} get secret plex-admin -n {$ns} -o jsonpath=".escapeshellarg('{.data.S3_ACCESS_KEY}'),
        )->output());

        if ($existing !== '') {
            $this->laraKubeWarn('Garage\'s admin key was created, but plex-admin already has S3 credentials from another backend.');
            $this->laraKubeLine('  Only one shared credential pair is supported today — Garage tenants would get the wrong ones. Enabling more than one credentialed object-storage backend (e.g. MinIO + Garage) in the same Commons at once isn\'t fully supported yet.');

            return;
        }

        Process::run(
            "{$kubectl} patch secret plex-admin -n {$ns} --type merge -p ".
            escapeshellarg((string) json_encode(['data' => [
                'S3_ACCESS_KEY' => base64_encode($access),
                'S3_SECRET_KEY' => base64_encode($secret),
            ]])),
        );
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

        $exists = trim(Process::run(
            "{$kubectl} get secret plex-admin -n {$ns} -o name",
        )->output()) !== '';

        if (! $exists) {
            $literals = '';
            foreach ($generators as $key => $generate) {
                $literals .= '--from-literal='.$key.'='.escapeshellarg($generate()).' ';
            }
            Process::run(
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
            $present = trim(Process::run(
                "{$kubectl} get secret plex-admin -n {$ns} -o jsonpath=".escapeshellarg('{.data.'.$key.'}'),
            )->output()) !== '';

            if (! $present) {
                $patch[$key] = base64_encode($generate());
            }
        }

        if (! empty($patch)) {
            Process::run(
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
            if (! empty($cfg['console_host'])) {
                $this->line("      <fg=gray>console:</> <fg=cyan>https://{$cfg['console_host']}</>");
                $publicHosts[] = (string) $cfg['console_host'];
            }
            if (! empty($cfg['admin_host'])) {
                $this->line("      <fg=gray>admin:</> <fg=cyan>https://{$cfg['admin_host']}</>");
                $publicHosts[] = (string) $cfg['admin_host'];
            }
        }

        // A Commons is multi-tenant by design — every app that joins adds another
        // host on this same ingress IP, so promote the CNAME-anchor pattern —
        // unless it's the local cluster, where there's no real DNS to anchor:
        // just register the host(s) directly (same automated, no-prompt
        // primitive `up` uses for Mailpit/Grafana/Console).
        if ($publicHosts !== []) {
            if ($this->targetsLocalCluster()) {
                // The system cert's SAN list is otherwise static (console/traefik/
                // mailpit/grafana + companions) — without this, a browser hitting
                // a Commons host gets a certificate hostname mismatch even though
                // the Ingress/Service route correctly. Regenerating the on-disk
                // cert alone isn't enough — Traefik reads it from a Secret mounted
                // into its OWN pod, so the updated cert has to be pushed into the
                // cluster (and Traefik restarted to pick it up) the same way `up`
                // does on every local run.
                $this->ensureSystemCertExists($publicHosts);
                $this->applyTraefikCertResources('traefik');

                // If dnsmasq already wildcards this TLD to 127.0.0.1, a static
                // /etc/hosts entry is redundant AND actively harmful: it pins
                // these hosts to whatever the cluster's LoadBalancer IP was at
                // write time, which breaks everything once that IP goes stale
                // (e.g. after an OrbStack restart) — while dnsmasq-covered
                // hosts keep resolving correctly through 127.0.0.1 regardless.
                // Same guard ensureHostsAreSet() already uses for regular
                // project hosts; also clean up any stale entry a previous
                // plex:init run left behind before dnsmasq covered this TLD.
                $tld = GlobalConfigData::load()->getLocalTld();
                if (! $this->isWsl() && $this->dnsmasqCoversKube($tld)) {
                    $this->removeHostsBlock('larakube-plex');
                } else {
                    if ($this->isWsl()) {
                        $this->syncWindowsHosts($publicHosts, 'larakube-plex');
                    }
                    $this->syncHostsEntries($publicHosts, 'larakube-plex');
                }
            } else {
                $this->printIngressDnsGuidance($publicHosts, $this->traefikLoadBalancerIp($this->plexContext));
            }
        }

        $this->newLine();
        $this->line('  Save the spec for disaster recovery: <fg=yellow>larakube plex:export</>');
    }
}
