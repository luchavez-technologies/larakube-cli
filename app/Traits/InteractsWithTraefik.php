<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Data\ResourceRef;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Spatie\TemporaryDirectory\TemporaryDirectory;

trait InteractsWithTraefik
{
    use InteractsWithToolRegistry;
    use LaraKubeOutput, ManagesLocalCa, VerifiesKubernetesRollout;

    /**
     * Ensure Traefik and its dependencies are installed and configured.
     * Returns whether Traefik itself actually came up — the apply+wait used
     * to be fire-and-forget (wrapped in a spinner closure that always
     * returned true regardless of the real kubectl result), so this could
     * report success even when the Deployment never became Ready. The
     * Mailpit/dashboard bring-up below still runs even on failure — they're
     * independent of whether Traefik's own rollout succeeded.
     */
    protected function setupTraefik(bool $force = false): bool
    {
        $this->laraKubeInfo('Synchronizing Traefik Ingress Controller...');

        $this->withSpin('Creating Traefik infrastructure (SSL & Config)...', function () {
            $this->createTraefikInfrastructure();

            return true;
        });

        $temporaryDirectory = TemporaryDirectory::make();
        $tmpInstall = $temporaryDirectory->path('traefik-install.yaml');
        file_put_contents($tmpInstall, view('k8s.traefik-install')->render());
        $ok = $this->applyAndVerifyRollout('kubectl', $tmpInstall, 'traefik', 'traefik', 120, '--validate=false');
        $temporaryDirectory->delete();

        // Bring up the shared services Traefik fronts (Mailpit + the dashboard
        // ingress) so a standalone `traefik:setup` lands them too. The same
        // registry is reconciled on every `up` via reconcileSharedCluster().
        // Both are local-only services, so their host derives from the dev TLD.
        $localTld = GlobalConfigData::load()->getLocalTld();

        // Wildcard *.{tld} DNS inside the cluster. Deliberately not wrapped in
        // withSpin(): its failure paths explain themselves as they go, and a
        // spinner would overwrite them.
        $this->laraKubeLine("  Pointing *.{$localTld} at Traefik inside the cluster...");

        if ($this->applyLocalWildcardDns($localTld)) {
            $this->laraKubeInfo("In-cluster DNS: *.{$localTld} now resolves to Traefik.");
        }

        $this->withSpin('Starting shared Mailpit (catch-all SMTP)...', function () use ($localTld) {
            $this->applySharedService(SharedClusterService::MAILPIT, SharedClusterService::MAILPIT->hostFor($localTld));

            return true;
        });

        $this->withSpin('Publishing Traefik dashboard ingress...', function () use ($localTld) {
            $this->applySharedService(SharedClusterService::TRAEFIK_DASHBOARD, SharedClusterService::TRAEFIK_DASHBOARD->hostFor($localTld));

            return true;
        });

        return $ok;
    }

    /**
     * Point *.{tld} at Traefik from INSIDE the cluster.
     *
     * A pod inherits the host's resolver for the local TLD, where *.{tld} is
     * 127.0.0.1 — and in a pod that is its own loopback, not the ingress. So
     * any workload that has to reach its OWN public host breaks locally while
     * working in the cloud, where public DNS already points that host at the
     * node: oCIS's built-in IdP verifies every access token by fetching
     * https://drive.{tld}/.well-known/openid-configuration, hit "connection
     * refused" on 127.0.0.1:443, and sent every SUCCESSFUL login straight to
     * /access-denied.
     *
     * k3s and OrbStack both import /etc/coredns/custom/*.server from their
     * Corefile, so a coredns-custom ConfigMap is the supported hook here —
     * never a patch of CoreDNS's own config. Best-effort throughout: a cluster
     * without that import (Docker Desktop) is told so, never failed.
     */
    protected function applyLocalWildcardDns(string $tld): bool
    {
        $local = Kubectl::current();
        $corefile = $local->raw(['get', 'configmap', 'coredns', '-n', 'kube-system', '-o', 'jsonpath={.data.Corefile}'])->output;

        if (! str_contains($corefile, 'import /etc/coredns/custom/')) {
            $this->laraKubeWarn("CoreDNS on this cluster imports no custom config — skipping in-cluster *.{$tld} DNS.");
            $this->laraKubeLine("  <fg=gray>A tool that calls its own https://…{$tld} host from inside a pod will fail here.</>");

            return false;
        }

        $ip = trim($local->raw(['get', 'service', 'traefik', '-n', 'traefik', '-o', 'jsonpath={.spec.clusterIP}'])->output);

        if ($ip === '' || $ip === 'None') {
            $this->laraKubeWarn("Could not read Traefik's ClusterIP — skipping in-cluster *.{$tld} DNS.");

            return false;
        }

        // Answering with the queried name keeps the reply's name matching the
        // question, so no answer-name rewrite is needed; AAAA returns NODATA so
        // clients fall straight through to the A record instead of stalling.
        $server = <<<COREDNS
        {$tld}:53 {
            errors
            template IN A {
                answer "{{ .Name }} 60 IN A {$ip}"
            }
            template IN AAAA {
                rcode NOERROR
            }
            cache 30
        }
        COREDNS;

        $applied = $local->putConfigMap('kube-system', 'coredns-custom', ["{$tld}.server" => $server])->ok;

        if (! $applied) {
            $this->laraKubeWarn("Could not apply the in-cluster *.{$tld} DNS override.");

            return false;
        }

        // CoreDNS re-reads an imported file only on its own reload interval, so
        // roll it now — otherwise setup reports success while the override sits
        // inert for minutes.
        $local->rolloutRestart('kube-system', 'coredns');
        $local->rolloutStatus('kube-system', 'coredns', 60);

        return true;
    }

    /**
     * Create the ConfigMap and Secret required for Traefik local SSL.
     * Called once when Traefik is first installed.
     */
    /**
     * Local hosts recorded in the cluster tools registry. Best-effort: an
     * unreachable cluster or an absent registry just means no extra SANs,
     * never a failed Traefik setup.
     *
     * @return list<string>
     */
    protected function localHostsNeedingCerts(): array
    {
        $tld = GlobalConfigData::load()->getLocalTld();

        // Registry hosts FIRST: these are the real installs, including any
        // second instance created with `--domain=`, which the fixed
        // SharedClusterService prefixes below can never know about.
        $hosts = [];

        foreach ($this->getRegisteredTools('kubectl') as $row) {
            $host = $row['host'] ?? null;

            if (is_string($host) && $host !== '') {
                $hosts[] = $host;
            }
        }

        // Plus each service's conventional host, so a cluster whose registry
        // is incomplete still gets the coverage it had before.
        foreach (SharedClusterService::cases() as $service) {
            $hosts[] = $service->host($tld);
        }

        return array_values(array_unique(array_filter(
            $hosts,
            fn (string $host) => $this->isLocalCertHost($host),
        )));
    }

    /** Local hosts only — a real domain gets its cert from Let's Encrypt. */
    protected function isLocalCertHost(string $host): bool
    {
        foreach (GlobalConfigData::ALLOWED_TLDS as $tld) {
            if (str_ends_with($host, '.'.$tld)) {
                return true;
            }
        }

        return false;
    }

    protected function createTraefikInfrastructure(): void
    {
        $namespace = 'traefik';
        Kubectl::current()->apply((string) json_encode(['apiVersion' => 'v1', 'kind' => 'Namespace', 'metadata' => ['name' => $namespace]]));

        // Include every host the tools registry knows about, not just each
        // service's DEFAULT prefix — otherwise a second instance created with
        // `--domain=` is missing from the SAN list forever. This is the
        // project-free sweep: `traefik:setup` reaches it, and a cluster with
        // tools but no projects is a perfectly normal cluster.
        // The system cert remains ONLY as Traefik's default for unmatched SNI —
        // prod has an equivalent fallback. Every real host gets its own
        // certificate below, so a host with no issuance fails locally exactly
        // as it would in production instead of being silently covered.
        $this->ensureSystemCertExists();

        foreach ($this->localHostsNeedingCerts() as $host) {
            $this->ensureHostCertExists($host);
        }

        $this->applyTraefikCertResources($namespace);
    }

    /**
     * Ensure this app's cert is in the Traefik cert pool.
     * Called on every `larakube up` so new apps join automatically. Pass the
     * project's getLocalTld() so a project-pinned TLD override gets a
     * matching cert; omit it to fall back to the developer's global TLD.
     *
     * Also re-validates the system/default cert (console, traefik, mailpit,
     * companions) against the current global TLD — without this, changing the
     * TLD via `config:tld` left the default cert frozen on the old TLD, so
     * shared hosts like mailpit.{tld} served a mismatched cert (no valid HTTPS)
     * until Traefik was reinstalled. ensureSystemCertExists() is a no-op when
     * the cert already covers the current TLD.
     */
    protected function refreshTraefikCerts(string $appName, ?string $tld = null, array $additionalHosts = []): void
    {
        $this->ensureSystemCertExists();
        $this->ensureAppCertExists($appName, $tld, $additionalHosts);
        $this->applyTraefikCertResources('traefik');
    }

    /**
     * Reconcile every cluster-wide, TLD-carrying shared artifact on a local `up`.
     *
     * Certs first (so every shared host below is served valid HTTPS by the
     * default cert), then each registered SharedClusterService. The set of
     * shared services is the single registry in the SharedClusterService enum —
     * add a new cluster-wide global (Uptime Kuma, a status page, …) as a case
     * there and it is reconciled here automatically, with no new method or call
     * site. Every step is internally guarded + idempotent, so this is safe to
     * run unconditionally on each local up — `up` is the single propagation
     * point for a `config:tld` change.
     */
    protected function reconcileSharedCluster(ConfigData $config): void
    {
        $appName = $config->getName();
        $tld = $config->getLocalTld();
        $additionalHosts = $config->getEnvironment('local')?->additionalWebHosts ?? [];

        $this->withSpin('Syncing local TLS certificates...', function () use ($appName, $tld, $additionalHosts) {
            $this->refreshTraefikCerts($appName, $tld, $additionalHosts);

            return true;
        });

        // This is the LOCAL up path, so we reconcile only the services that target
        // the local environment (Mailpit, the Console, the Traefik dashboard, and
        // the local Grafana ingress). Cloud-targeting reconciles (prod Grafana)
        // are driven by their own installers (monitor:init --context).
        //
        // Each host is resolved through getSharedServiceHost(): a name-less GLOBAL
        // host on the developer's global TLD by default, but a .larakube.json
        // hosts[serviceKey] entry can override it — the same map the cloud paths
        // read, so host resolution is data-driven, not Grafana-special-cased.
        // Resolve the set first, so the quiet path below can report one count
        // rather than one line per service.
        $present = [];

        foreach (SharedClusterService::cases() as $service) {
            if (! $service->targetsEnvironment('local')) {
                continue;
            }

            // Check presence BEFORE showing anything — applySharedService() already
            // no-ops for an install-gated service that isn't present, but silently:
            // the spinner+label below would still show "Refreshing GlitchTip
            // ingress... ✔" even when GlitchTip was never installed, which reads as
            // `up` reaching out and touching a dozen unrelated services on every
            // single project. Skipping the spinner entirely for anything not
            // actually installed keeps the output honest about what really happened.
            if (! $this->isSharedServicePresent($service)) {
                continue;
            }

            $present[] = $service;
        }

        // These are CLUSTER-wide ingresses, not this project's. For a Laravel app
        // most of them are at least plausibly related; for a static site none are
        // — naming Mailpit and Stalwart while bringing up a landing page reads as
        // `up` touching a dozen unrelated services. They are still reconciled
        // (skipping would leave them stale for anyone who only runs static
        // projects), just summarised into a single line.
        if ($config->framework?->isStaticSpa()) {
            if ($present !== []) {
                $count = count($present);

                $this->withSpin(
                    'Reconciling '.$count.' shared cluster '.Str::plural('ingress', $count).'...',
                    function () use ($present, $config) {
                        foreach ($present as $service) {
                            $this->applySharedService($service, $config->getSharedServiceHost($service, 'local'));
                        }

                        return true;
                    },
                );
            }

            return;
        }

        foreach ($present as $service) {
            $this->withSpin($service->reconcileLabel(), function () use ($service, $config) {
                $this->applySharedService($service, $config->getSharedServiceHost($service, 'local'));

                return true;
            });
        }
    }

    /**
     * Whether $service is actually installed — always-on services (no probe)
     * are always "present"; install-gated ones are only present when their
     * probe resource exists. See applySharedService()'s own (still-kept)
     * internal check for why this can't just be inlined there: this needs to
     * run BEFORE the reconcile spinner is shown, not just before the apply.
     */
    protected function isSharedServicePresent(SharedClusterService $service): bool
    {
        $probe = $service->presenceProbe();

        return $probe === null || trim(Process::run(Kubectl::current()->prefix()." get {$probe} --no-headers")->output()) !== '';
    }

    /**
     * Render a shared service's manifest at the given host and kubectl-apply it.
     *
     * The caller resolves $host (via SharedClusterService::hostFor()) from the
     * target environment's domain — the dev TLD locally, the env's real domain on
     * a cloud cluster — so this method stays environment-agnostic.
     *
     * Install-gated services (those with a presenceProbe) are skipped when their
     * probe finds nothing — `up` re-points an existing install but never auto-
     * installs one, so a declined service stays declined. Always-on services
     * (no probe) get their namespace auto-created first. Idempotent: an unchanged
     * manifest is a no-op; a `config:tld` change re-points the Ingress host.
     */
    protected function applySharedService(SharedClusterService $service, string $host): void
    {
        $probe = $service->presenceProbe();
        if ($probe !== null && trim(Process::run(Kubectl::current()->prefix()." get {$probe} --no-headers")->output()) === '') {
            return;
        }

        if ($service->namespace() !== null) {
            Kubectl::current()->apply((string) json_encode(['apiVersion' => 'v1', 'kind' => 'Namespace', 'metadata' => ['name' => $service->namespace()]]));
        }

        $payload = array_merge([
            'host' => $host,
            'isLocal' => true,
        ], method_exists($service, 'templatePayload') ? $service->templatePayload() : []);

        Kubectl::current()->apply(view($service->template(), $payload)->render());

        $this->syncSharedServiceDeploymentEnv($service, $host);
    }

    /**
     * Re-sync any host-carrying Deployment env that a service's Ingress-only
     * reconcile doesn't touch (the Console's APP_URL/ASSET_URL). Without this a
     * config:tld change re-points the ingress but leaves the Deployment serving
     * on the old host until `console --update`. Idempotent: `kubectl set env`
     * only rolls the Deployment when a value actually changes, and the whole
     * thing is skipped unless the Deployment already exists.
     */
    protected function syncSharedServiceDeploymentEnv(SharedClusterService $service, string $host): void
    {
        $sync = $service->deploymentEnvSync($host);
        if ($sync === null) {
            return;
        }

        $local = Kubectl::current();
        if (! $local->exists(new ResourceRef('Deployment', $sync['deployment'], $sync['namespace']))) {
            return;
        }

        $pairs = array_map(fn (string $key, string $value) => "{$key}={$value}", array_keys($sync['env']), array_values($sync['env']));
        $local->raw(['set', 'env', 'deployment', $sync['deployment'], '-n', $sync['namespace'], ...$pairs]);
    }

    /**
     * Rebuild traefik-config ConfigMap and traefik-certificates Secret from all
     * locally-generated certs, then restart Traefik to pick up changes.
     */
    protected function applyTraefikCertResources(string $namespace): void
    {
        // 1. ConfigMap — dynamic YAML listing all cert pairs
        $temporaryDirectory = TemporaryDirectory::make();
        $tmpCertsYml = $temporaryDirectory->path('traefik-certs.yml');
        file_put_contents($tmpCertsYml, $this->buildTraefikCertsYml());
        // Server-side apply avoids storing base64 cert blobs in the
        // last-applied-configuration annotation (256 KB limit overflows with multiple certs).
        $local = Kubectl::current();
        $local->putConfigMap($namespace, 'traefik-config', ['traefik-certs.yml' => (string) file_get_contents($tmpCertsYml)], serverSide: true);
        $temporaryDirectory->delete();

        // 2. Secret — all cert files from ~/.larakube/certificates/
        $files = [
            'system-dev.pem' => $this->getSystemCertPath(),
            'system-dev-key.pem' => $this->getSystemKeyPath(),
        ];
        foreach ($this->getAllLocalAppCerts() as $appName => $paths) {
            $files["{$appName}-dev.pem"] = $paths['crt'];
            $files["{$appName}-dev-key.pem"] = $paths['key'];
        }

        $local->putSecret($namespace, 'traefik-certificates', array_map(fn (string $path) => (string) @file_get_contents($path), $files), serverSide: true);

        // 3. Restart Traefik to pick up changes (only if it exists)
        if ($local->exists(new ResourceRef('Deployment', 'traefik', $namespace))) {
            $local->rolloutRestart($namespace, 'traefik');
        }
    }

    /**
     * Restart Traefik to clear stale conntrack / endpoint caches.
     *
     * Call this after restarting a long-lived Deployment (e.g. Stalwart) whose
     * pod IP may be reused — kube-proxy conntrack entries in the Traefik pod
     * can go stale and cause "Connection reset by peer" → 502 Bad Gateway until
     * Traefik restarts and re-discovers the backend.
     *
     * Accepts a context-aware $kubectl prefix (e.g. "kubectl --context=foo")
     * so it works on remote clusters, not just the current context.
     */
    protected function restartTraefikIngress(string $kubectl): void
    {
        $cluster = Kubectl::fromPrefix($kubectl);
        if ($cluster->exists(new ResourceRef('Deployment', 'traefik', 'traefik'))) {
            $cluster->rolloutRestart('traefik', 'traefik');
        }
    }

    /**
     * Check if any Ingress Controller is currently active in the cluster.
     *
     * Tries increasingly broad detection strategies:
     *  1. Label-based: standard ingress-controller labels (catches Helm k3s Traefik,
     *     nginx-ingress, and any other conformant install).
     *  2. Name-based: a LoadBalancer named "traefik" in any namespace (catches
     *     hand-rolled installs or older templates with no labels).
     *  3. Namespace-wide: any LoadBalancer in kube-system (last-resort catch-all).
     */
    protected function isTraefikInstalled(): bool
    {
        // 1. Label-based: standard ingress-controller labels.
        // Note: kubectl does not support combining -l (label) and --field-selector
        // in the same call, so we use -l alone and trust the label accuracy.
        $probes = [
            ['-A', '-l', 'app.kubernetes.io/name=traefik,app.kubernetes.io/component=ingress-controller'],
            // nginx-ingress variants
            ['-A', '-l', 'app=ingress-nginx,app.kubernetes.io/name=ingress-nginx'],
            // A LoadBalancer named "traefik" anywhere (hand-rolled installs)
            ['-A', '--field-selector', 'metadata.name=traefik,spec.type=LoadBalancer'],
            // Last resort: any LoadBalancer in kube-system
            ['-n', 'kube-system', '--field-selector', 'spec.type=LoadBalancer'],
        ];

        $output = '';
        foreach ($probes as $selector) {
            $output = trim(Kubectl::current()->raw(['get', 'svc', ...$selector, '-o', 'name'])->output);
            if ($output !== '') {
                break;
            }
        }

        return $output !== '';
    }

    /**
     * Completely remove Traefik and its cluster-scoped resources.
     */
    protected function destroyTraefik(): void
    {
        $this->laraKubeInfo('Destroying Traefik Ingress Controller...');

        $this->withSpin('Removing Traefik namespace and internal resources...', function () {
            Kubectl::current()->raw(['delete', 'namespace', 'traefik', '--wait=true']);

            return true;
        });

        $this->withSpin('Cleaning up cluster-scoped RBAC permissions...', function () {
            Kubectl::current()->raw(['delete', 'clusterrole', 'traefik-ingress-controller']);
            Kubectl::current()->raw(['delete', 'clusterrolebinding', 'traefik-ingress-controller']);

            return true;
        });
    }
}
