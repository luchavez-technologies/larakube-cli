<?php

namespace App\Commands\Secrets;

use App\Enums\ClusterTool;
use App\Enums\SecretsBackend;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithSecrets;
use App\Traits\InteractsWithVolumeSizing;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\RunsKubectlSteps;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class SecretsInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithPlex, InteractsWithSecrets, InteractsWithVolumeSizing, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment, ResolvesToolHost, RunsKubectlSteps, StreamsProcessOutput;

    protected $signature = 'secrets:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted. A non-local env prompts for + persists the secrets manager host.}
        {--context=        : Target a specific kube-context (defaults to current context)}
        {--domain=         : Base domain OR full host for secrets manager (example.com → secrets.example.com; secrets.example.com used as-is)}
        {--vpn-only        : Restrict access via NetBird VPN IP whitelisting}
        {--force           : Skip the confirmation prompt}';

    protected $description = 'Deploy OpenBao secrets manager & External Secrets Operator into larakube-secrets';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deploySecrets();
    }

    protected function deploySecrets(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = $this->secretsNamespace();

        $host = $this->resolveSecretsHost($env, $kubectl);

        // Ensure namespace exists
        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::SECRETS, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        $manifest = view('k8s.secrets.openbao', [
            'volumeSize' => $this->volumeSizeResolver($kubectl, $ns),
            'namespace' => $ns,
            'image' => SecretsBackend::OPENBAO->getDockerImage(),
            'port' => SecretsBackend::OPENBAO->getDefaultPort(),
            'host' => $host,
            // Was local-only ("cloud/production stay manual-unseal by
            // design — a security boundary"), reconsidered 2026-08-15: the
            // unseal key already lives in-cluster as a plain Secret
            // (openbao-bootstrap) regardless of this flag — anyone who can
            // read Secrets in this namespace can already unseal manually, so
            // withholding auto-unseal in production defends against a
            // restart, not against a real compromise. What it actually cost:
            // a node hiccup resealed OpenBao in production, and every tool
            // whose ExternalSecret/VaultDynamicSecret depends on it (ESO's
            // Kubernetes-auth login, static-role rotation reads, KV pushes)
            // failed silently until a human noticed and ran
            // `secrets:unseal` by hand — Forgejo and Vaultwarden both went
            // down from stale, superseded DB passwords as a direct result.
            // See docs/decisions/0016-openbao-auto-unseal-everywhere.md.
            'autoUnseal' => true,
        ])->render();

        $crdsManifest = view('k8s.secrets.eso-crds')->render();
        $generatorCrdsManifest = view('k8s.secrets.eso-crds-generators')->render();

        $esoManifest = view('k8s.secrets.eso', [
            'namespace' => $ns,
        ])->render();

        // A CRD apply is rejected when status.storedVersions names a version
        // the new schema no longer lists. ESO's bundle keeps v1beta1 (unserved)
        // but dropped v1alpha1, so an install old enough to still have
        // v1alpha1 stored has to be pruned first. Cheap insurance before
        // applying a newer CRD bundle over ~20 tools' live ExternalSecrets.
        $this->pruneStaleStoredCrdVersions($kubectl);

        $bundle = $crdsManifest."\n---\n".$generatorCrdsManifest."\n---\n".$manifest."\n---\n".$esoManifest;

        // Four resources to verify (openbao-backend + ESO's three), so this
        // can't use the single apply+rollout applyAndVerifyRollout() helper;
        // every step below checks its own exit code instead, or a rejected
        // apply / stuck rollout prints ✔ and this command claims success.
        // Server-side apply: the CRD bundle is far past the ~262KB
        // last-applied-configuration annotation limit client-side apply
        // enforces, and --force-conflicts (which apply() passes with it)
        // takes field ownership from installs first applied client-side.
        // kubectlStep(), not a bare spinner: a rejected apply names the exact
        // object and reason — an immutable field on an upgraded bundle, say —
        // and a spinner that swallows it leaves only "failed".
        if (! $this->kubectlStep(
            'Applying OpenBao & External Secrets Operator manifests...',
            fn () => Kubectl::fromPrefix($kubectl)->apply($bundle, serverSide: true),
        )) {
            return 1;
        }

        if (! $this->withSpin('Waiting for OpenBao Backend...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/openbao-backend -n {$ns} --timeout=120s")->successful())) {
            $this->laraKubeError('openbao-backend never became Ready.');

            return 1;
        }

        // ESO ships a webhook + cert-controller alongside the main
        // reconciler (see eso.blade.php's header comment for why the webhook
        // isn't optional: its ValidatingWebhookConfiguration defaults to
        // failurePolicy: Fail, which would reject every ExternalSecret/
        // SecretStore apply across every tool if it's deployed without
        // something actually serving it).
        foreach (['external-secrets' => 'External Secrets Operator', 'external-secrets-cert-controller' => 'ESO cert controller', 'external-secrets-webhook' => 'ESO admission webhook'] as $deployment => $label) {
            if (! $this->withSpin("Waiting for {$label}...", fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/{$deployment} -n {$ns} --timeout=120s")->successful())) {
                $this->laraKubeError("{$deployment} never became Ready.");

                return 1;
            }
        }

        if (! $this->wireEsoToOpenBao($kubectl, $ns)) {
            return 1;
        }

        $this->registerDeployedTool(ClusterTool::SECRETS, $kubectl, $host);
        $this->laraKubeInfo("✅ OpenBao stack & External Secrets Operator are live in {$ns}.");
        $this->newLine();
        $this->line("  <fg=gray>OpenBao:</>  <fg=blue>https://{$host}</>");
        $this->newLine();

        return 0;
    }

    /** Resolve the OpenBao ingress host for this install */
    protected function resolveSecretsHost(string $env, ?string $kubectl = null): string
    {
        return $this->resolveToolHost(SharedClusterService::SECRETS, ClusterTool::SECRETS, $env, $kubectl);
    }

    /** Decide which environment this install targets */
    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::SECRETS);
    }

    /**
     * Guard against ESO's CRD schema rejecting a live cluster's own history.
     * A CRD's status.storedVersions lists every API version ANY currently-
     * stored object still uses under the hood, independent of what the CRD
     * currently serves — applying a new CRD version list that drops one of
     * those stored versions is refused by the API server outright, protecting
     * against silently making existing objects unreadable. We've only ever
     * written v1beta1/v1 (never the older v1alpha1 the upstream CRD schema
     * also lists as a historical entry), so this should always find nothing
     * — but it costs one read-only check per CRD to confirm that rather than
     * assume it, before applying a bundle whose schema no longer offers
     * v1alpha1 at all.
     */
    protected function pruneStaleStoredCrdVersions(string $kubectl): void
    {
        $crds = [
            'secretstores.external-secrets.io',
            'clustersecretstores.external-secrets.io',
            'externalsecrets.external-secrets.io',
            'clusterexternalsecrets.external-secrets.io',
            'pushsecrets.external-secrets.io',
            'clusterpushsecrets.external-secrets.io',
        ];

        foreach ($crds as $crd) {
            $stored = trim(Process::run(
                "{$kubectl} get customresourcedefinition {$crd} -o jsonpath='{.status.storedVersions}' --ignore-not-found",
            )->output());

            // Empty means either the CRD doesn't exist yet (fresh install —
            // nothing to prune) or the jsonpath found no field; either way
            // there's nothing this guard needs to do.
            if ($stored === '' || ! str_contains($stored, 'v1alpha1')) {
                continue;
            }

            $versions = array_values(array_filter(
                json_decode($stored, true) ?? [],
                fn (string $v) => $v !== 'v1alpha1',
            ));

            if ($versions === []) {
                continue;
            }

            $patch = json_encode(['status' => ['storedVersions' => $versions]]);
            Process::run("{$kubectl} patch customresourcedefinition {$crd} --subresource=status --type=merge -p ".escapeshellarg($patch));
        }
    }

    /**
     * Bootstrap OpenBao (init + unseal, via the shared ensureOpenBaoReady()
     * in InteractsWithSecrets — see that method's docblock for why this call
     * exists here now: a fresh cluster with no prior export file used to have
     * no working path to initialization at all), then create the
     * ClusterSecretStore that wires ESO to OpenBao, then create ExternalSecrets
     * for every installed tool that has secrets in OpenBao. Idempotent:
     * re-running secrets:init applies the same resources.
     */
    protected function wireEsoToOpenBao(string $kubectl, string $ns): bool
    {
        $token = $this->ensureOpenBaoReady($kubectl, $ns);
        if ($token === null) {
            $this->laraKubeError('Could not initialize/unseal OpenBao — check kubectl access to the cluster above and re-run.');

            return false;
        }

        // A genuinely fresh OpenBao (unlike Vault's dev mode) has no secret/
        // KV mount at all — every pushClusterSecret()/KV-fallback write
        // across the whole CLI assumes it exists. Fatal, not a warning: with
        // no KV backend, secrets:init would appear to succeed while quietly
        // breaking every tool that falls back to it.
        if (! $this->ensureKvSecretsEngineMounted($kubectl, $ns, $token)) {
            $this->laraKubeError('Could not mount the secret/ KV engine on OpenBao — check kubectl access to the cluster above and re-run.');

            return false;
        }

        // Non-fatal on failure: the root token remains the fallback either
        // way, same as before this existed. A userpass hiccup shouldn't
        // block the rest of the OpenBao/ESO deployment.
        $userpassAdmin = null;
        $this->withSpin('Ensuring a baseline OpenBao admin login (userpass, independent of SSO)...', function () use ($kubectl, $ns, $token, &$userpassAdmin): void {
            $userpassAdmin = $this->ensureOpenBaoUserpassAdmin($kubectl, $ns, $token);
        });

        if ($userpassAdmin === null) {
            $this->laraKubeWarn('Could not set up the baseline OpenBao admin login — the root token in openbao-bootstrap still works.');
        } elseif ($userpassAdmin[2]) {
            [$adminUsername, $adminPassword] = $userpassAdmin;
            $this->newLine();
            $this->line('  <fg=yellow>⚠ OpenBao admin login created — save this now, it will not be shown again:</>');
            $this->line("    <fg=gray>Username:</> <fg=blue>{$adminUsername}</>");
            $this->line("    <fg=gray>Password:</> <fg=blue>{$adminPassword}</>");
            $this->line('  <fg=gray>Also stored in the openbao-bootstrap Secret (admin-username / admin-password) if you lose this.</>');
            $this->newLine();
        }

        $this->withSpin('Wiring External Secrets Operator to OpenBao...', function () use ($kubectl, $ns, $token): void {
            $clusterStore = view('k8s.secrets.cluster-store', [
                'namespace' => $ns,
                'token' => base64_encode($token),
                'hostAPI' => 'http://openbao-backend.'.$ns.'.svc.cluster.local:8200',
            ])->render();

            $temporaryDirectory = TemporaryDirectory::make();
            $tmp = $temporaryDirectory->path('larakube-eso-cluster-store.yaml');
            file_put_contents($tmp, $clusterStore);
            Process::run("{$kubectl} apply -f ".escapeshellarg($tmp));
            $temporaryDirectory->delete();

            $reloader = view('k8s.secrets.reloader', [
                'namespace' => $ns,
            ])->render();

            $reloaderTemporaryDirectory = TemporaryDirectory::make();
            $tmpReloader = $reloaderTemporaryDirectory->path('larakube-reloader.yaml');
            file_put_contents($tmpReloader, $reloader);
            Process::run("{$kubectl} apply -f ".escapeshellarg($tmpReloader));
            $reloaderTemporaryDirectory->delete();
        });

        foreach (ClusterTool::cases() as $tool) {
            // Instance-aware, per ADR 0021. This loop asked for the UNSUFFIXED
            // deployment and secret, so once a tool adopted
            // {category}-{component}-{instance} the deploymentExists() check
            // below could never match and its KV sync silently stopped being
            // created — while the guard further down looked for the wrong
            // ExternalSecret name too, so the race it exists to prevent was
            // unguarded. Confirmed live 2026-08-29: notes/design/git have only
            // their dynamic `-db` ExternalSecrets, and the static ones that do
            // exist (monitor-secrets, chat-secrets) predate the renames.
            $toolHost = $this->getToolHost($kubectl, $tool);
            $instance = $toolHost !== null ? $tool->instanceSlugFromHost($toolHost) : null;

            $config = $tool->openbaoSyncConfig($instance);
            if ($config === null) {
                continue;
            }

            if (! $this->deploymentExists($kubectl, $config['namespace'], $tool->deploymentName($instance))) {
                continue;
            }

            // A tool that has graduated to dynamic-creds rotation (secrets:wire)
            // already has a "{secret}-db" ExternalSecret targeting this same
            // k8s Secret. Recreating the static KV-mirrored one here would race
            // it on every reconcile — each overwrites the other's value, and
            // since the KV one refreshes more often it usually wins, silently
            // reintroducing a stale password. Confirmed live 2026-08-17 (git/
            // Forgejo, sustained 28P01 auth failures). Once dynamic rotation
            // exists, it's authoritative — skip the static sync entirely.
            $dynamicallyWired = trim(Process::run(
                "{$kubectl} get externalsecret {$config['secret']}-db -n {$config['namespace']} --ignore-not-found -o name",
            )->output());
            if ($dynamicallyWired !== '') {
                continue;
            }

            $this->withSpin("Syncing OpenBao secrets to {$config['secret']} in {$config['namespace']}...", function () use ($kubectl, $config): void {
                $es = view('k8s.secrets.tool-es', [
                    'namespace' => $config['namespace'],
                    'secretName' => $config['secret'],
                    'keys' => $config['keys'] ?? [],
                    'keyMap' => $config['keyMap'] ?? null,
                ])->render();

                $temporaryDirectory = TemporaryDirectory::make();
                $tmp = $temporaryDirectory->path('larakube-es-'.$config['secret'].'.yaml');
                file_put_contents($tmp, $es);
                Process::run("{$kubectl} apply -f ".escapeshellarg($tmp));
                $temporaryDirectory->delete();
            });
        }

        return true;
    }

    /** Check if a deployment exists in a namespace. */
    protected function deploymentExists(string $kubectl, string $ns, string $deployment): bool
    {
        return Kubectl::fromPrefix($kubectl)->hasDeployment($ns, $deployment);
    }
}
