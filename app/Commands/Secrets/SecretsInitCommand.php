<?php

namespace App\Commands\Secrets;

use App\Enums\ClusterTool;
use App\Enums\SecretsBackend;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithSecrets;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class SecretsInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithPlex, InteractsWithSecrets, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput;

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
        $kubectl = $this->secretsKubectl($context);
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

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-openbao.yaml');
        file_put_contents($tmp, $crdsManifest."\n---\n".$generatorCrdsManifest."\n---\n".$manifest."\n---\n".$esoManifest);

        // Two resources to verify per apply (openbao-backend + external-
        // secrets), so this can't use the single apply+rollout
        // applyAndVerifyRollout() helper — every step checks its real exit
        // code via an explicit ->timeout() exceeding its own kubectl
        // --timeout flag, or a rejected apply / stuck rollout prints ✔ and
        // this command claims success regardless (confirmed live on
        // Documenso, 2026-08-05).
        $applied = $this->withSpin('Applying OpenBao & External Secrets Operator manifests...', fn () => Process::timeout(70)->run("{$kubectl} apply -f {$tmp} --request-timeout=60s")->successful());
        $temporaryDirectory->delete();

        if (! $applied) {
            $this->laraKubeError('Could not apply the OpenBao/ESO manifest — see the output above.');

            return 1;
        }

        if (! $this->withSpin('Waiting for OpenBao Backend...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/openbao-backend -n {$ns} --timeout=120s")->successful())) {
            $this->laraKubeError('openbao-backend never became Ready.');

            return 1;
        }

        if (! $this->withSpin('Waiting for External Secrets Operator...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/external-secrets -n {$ns} --timeout=120s")->successful())) {
            $this->laraKubeError('external-secrets never became Ready.');

            return 1;
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
            $config = $tool->openbaoSyncConfig();
            if ($config === null) {
                continue;
            }

            if (! $this->deploymentExists($kubectl, $config['namespace'], $tool->deploymentName())) {
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
                    'keys' => $config['keys'],
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
        return trim(Process::run(
            "{$kubectl} get deployment {$deployment} -n {$ns} --no-headers --ignore-not-found",
        )->output()) !== '';
    }
}
