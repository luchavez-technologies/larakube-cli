<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Spatie\TemporaryDirectory\TemporaryDirectory;

trait InteractsWithTraefik
{
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
     * Create the ConfigMap and Secret required for Traefik local SSL.
     * Called once when Traefik is first installed.
     */
    protected function createTraefikInfrastructure(): void
    {
        $namespace = 'traefik';
        Process::run("kubectl create namespace {$namespace} --dry-run=client -o yaml | kubectl apply -f -");

        $this->ensureSystemCertExists();
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

        return $probe === null || trim(Process::run("kubectl get {$probe} --no-headers")->output()) !== '';
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
        if ($probe !== null && trim(Process::run("kubectl get {$probe} --no-headers")->output()) === '') {
            return;
        }

        if ($service->namespace() !== null) {
            Process::run('kubectl create namespace '.escapeshellarg($service->namespace()).' --dry-run=client -o yaml | kubectl apply -f -');
        }

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path("larakube-shared-{$service->value}.yaml");
        $payload = array_merge([
            'host' => $host,
            'isLocal' => true,
        ], method_exists($service, 'templatePayload') ? $service->templatePayload() : []);

        file_put_contents($tmp, view($service->template(), $payload)->render());
        Process::run("kubectl apply -f {$tmp}");
        $temporaryDirectory->delete();

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

        $deployment = escapeshellarg($sync['deployment']);
        $namespace = escapeshellarg($sync['namespace']);

        $exists = trim(Process::run("kubectl get deployment {$deployment} -n {$namespace} --no-headers")->output());
        if ($exists === '') {
            return;
        }

        $pairs = '';
        foreach ($sync['env'] as $key => $value) {
            $pairs .= ' '.escapeshellarg("{$key}={$value}");
        }

        Process::run("kubectl set env deployment {$deployment} -n {$namespace}{$pairs}");
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
        Process::run("kubectl create configmap traefik-config -n {$namespace} --from-file=traefik-certs.yml={$tmpCertsYml} --dry-run=client -o yaml | kubectl apply --server-side --field-manager=larakube --force-conflicts -f -");
        $temporaryDirectory->delete();

        // 2. Secret — all cert files from ~/.larakube/certificates/
        $fromFiles = ' --from-file=system-dev.pem='.escapeshellarg($this->getSystemCertPath())
            .' --from-file=system-dev-key.pem='.escapeshellarg($this->getSystemKeyPath());

        foreach ($this->getAllLocalAppCerts() as $appName => $paths) {
            $fromFiles .= ' --from-file='.escapeshellarg("{$appName}-dev.pem={$paths['crt']}");
            $fromFiles .= ' --from-file='.escapeshellarg("{$appName}-dev-key.pem={$paths['key']}");
        }

        Process::run("kubectl create secret generic traefik-certificates -n {$namespace}{$fromFiles} --dry-run=client -o yaml | kubectl apply --server-side --field-manager=larakube --force-conflicts -f -");

        // 3. Restart Traefik to pick up changes (only if it exists)
        $exists = Process::run("kubectl get deployment traefik -n {$namespace}")->output();
        if ($exists !== '') {
            Process::run("kubectl rollout restart deployment traefik -n {$namespace}");
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
        $exists = Process::run("{$kubectl} get deployment traefik -n traefik --no-headers --ignore-not-found")->output();
        if (trim($exists) !== '') {
            Process::run("{$kubectl} rollout restart deployment/traefik -n traefik");
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
        $output = trim(Process::run(
            'kubectl get svc -A -l app.kubernetes.io/name=traefik,app.kubernetes.io/component=ingress-controller -o name',
        )->output());

        // 1b. Label-based: nginx-ingress variants
        if ($output === '') {
            $output = trim(Process::run(
                'kubectl get svc -A -l app=ingress-nginx,app.kubernetes.io/name=ingress-nginx -o name',
            )->output());
        }

        // 2. Name-based: a LoadBalancer named "traefik" anywhere (hand-rolled installs)
        if ($output === '') {
            $output = trim(Process::run(
                'kubectl get svc -A --field-selector metadata.name=traefik,spec.type=LoadBalancer -o name',
            )->output());
        }

        // 3. Last resort: any LoadBalancer in kube-system
        if ($output === '') {
            $output = trim(Process::run(
                'kubectl get svc -n kube-system --field-selector spec.type=LoadBalancer -o name',
            )->output());
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
            Process::timeout(120)->run('kubectl delete namespace traefik --wait=true');

            return true;
        });

        $this->withSpin('Cleaning up cluster-scoped RBAC permissions...', function () {
            Process::run('kubectl delete clusterrole traefik-ingress-controller');
            Process::run('kubectl delete clusterrolebinding traefik-ingress-controller');

            return true;
        });
    }
}
