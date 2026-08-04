<?php

namespace App\Commands\Sso;

use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithSecrets;
use App\Traits\InteractsWithSso;
use App\Traits\InteractsWithZitadelApi;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class SsoInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithMail, InteractsWithPlex, InteractsWithSecrets, InteractsWithSso, InteractsWithZitadelApi, LaraKubeOutput, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, SyncsClusterSecrets, VerifiesKubernetesRollout;

    protected $signature = 'sso:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=      : Base domain OR full host for Zitadel (example.com → prefix.example.com)}
        {--admin-email= : Console admin login email (default: your operator email, or admin@<host>)}
        {--no-plex      : Bypass Plex Commons and bundle a dedicated Postgres}
        {--vpn-only     : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy Zitadel — a self-hosted OIDC/SAML identity provider — into its own larakube-sso namespace';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deploySso();
    }

    protected function deploySso(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->ssoKubectl($context);
        $host = $this->resolveToolHost(SharedClusterService::SSO, ClusterTool::SSO, $env, $kubectl);

        $ns = $this->ssoNamespace();
        $noPlex = (bool) $this->option('no-plex');
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::SSO, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        if (! $noPlex) {
            if (! $this->ensureCommons(['postgres'])) {
                return 1;
            }
        }

        $dbPassword = $this->readSsoSecret($kubectl, $ns, 'db-password') ?? Str::random(24);
        $masterkey = $this->readSsoSecret($kubectl, $ns, 'masterkey') ?? Str::random(32);

        // Zitadel's default password-complexity policy requires upper + lower +
        // number + SYMBOL. Str::random is alphanumeric only, so the first-instance
        // admin bootstrap fails setup with PasswordComplexityPolicy.HasSymbol
        // (verified live). Generate a compliant one — and regenerate a stored
        // non-compliant password (e.g. from a pre-fix deploy whose setup never
        // completed) rather than reusing it and crashing again.
        $adminPassword = $this->readSsoSecret($kubectl, $ns, 'admin-password');
        if ($adminPassword === null || ! $this->isComplexEnoughForZitadel($adminPassword)) {
            $adminPassword = $this->generateZitadelAdminPassword();
        }

        // Stable across re-runs once set; resolved (prompt/--admin-email/default)
        // only on first install. NB the first-instance admin is only actually
        // (re)created on a FRESH instance, so changing this on an existing
        // instance needs --remove + re-init to take effect.
        $adminEmail = $this->readSsoSecret($kubectl, $ns, 'admin-email') ?? $this->resolveAdminEmail($host);

        if (! $noPlex) {
            if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, 'zitadel', $dbPassword)) {
                return 1;
            }

            // Zitadel's init unconditionally runs CREATE DATABASE (its "verify
            // database" step) — verified live against the droplet. Without
            // CREATEDB on the tenant role it CrashLoopBackOffs; with it, the
            // create returns "already exists" on the pre-provisioned DB, which
            // Zitadel's restart-safe init tolerates. Must run every deploy: a
            // role recreation (e.g. after --remove) drops the attribute.
            if (! $this->grantPostgresCreateDb('zitadel')) {
                $this->laraKubeError('Could not grant CREATEDB to the zitadel role in the Commons — Zitadel will crash on boot without it.');

                return 1;
            }
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $dbPassword, $masterkey, $adminPassword, $adminEmail) {
            Process::run(
                "{$kubectl} create secret generic sso-secrets -n {$ns} "
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                .'--from-literal=masterkey='.escapeshellarg($masterkey).' '
                .'--from-literal=admin-password='.escapeshellarg($adminPassword).' '
                .'--from-literal=admin-email='.escapeshellarg($adminEmail).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );

            if ($this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace())) {
                $this->pushClusterSecret($kubectl, 'ZITADEL_ADMIN_EMAIL', $adminEmail, 'production');
                $this->pushClusterSecret($kubectl, 'ZITADEL_ADMIN_PASSWORD', $adminPassword, 'production');
                if ($this->databaseEngineMounted($kubectl)) {
                    $this->registerStaticRole($kubectl, 'zitadel', 'plex-postgres', 'zitadel');

                    // registerStaticRole() rotates the password as a side
                    // effect the instant a role is FIRST created — the
                    // literal $dbPassword the Secret above already has is
                    // stale from that moment on. This exact gap is why
                    // Zitadel came up healthy and then desynced again a
                    // restart later, confirmed live 2026-08-02.
                    $realPassword = $this->readStaticRolePassword($kubectl, 'zitadel');
                    if ($realPassword !== null) {
                        Process::run(
                            "{$kubectl} patch secret sso-secrets -n {$ns} --type=json "
                            .'-p=\'[{"op":"replace","path":"/data/db-password","value":"'.base64_encode($realPassword).'"}]\'',
                        );
                    }
                } else {
                    $this->pushClusterSecret($kubectl, 'ZITADEL_DB_PASSWORD', $dbPassword, 'production');
                }
                // NOT syncClusterSecretToNamespace() here — confirmed live
                // 2026-08-02: it extracts OpenBao KV path "production" as one
                // object, but every value here is written at the deeper
                // "production/{KEY}" path, so the extract is always empty. As
                // an Owner-mode ExternalSecret with a 1m refresh, it silently
                // wiped this exact Secret (masterkey included) on every
                // reconcile — took Zitadel down. The `create secret` above is
                // the real, working sync; this was a redundant second one.
            }
        });

        $manifest = view('k8s.sso.zitadel', [
            'host' => $host,
            'adminEmail' => $adminEmail,
            'plexNamespace' => $this->plexNamespace(),
            'noPlex' => $noPlex,
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-sso.yaml';
        file_put_contents($tmp, $manifest);

        // First boot runs Zitadel's own DB init + schema setup before it starts
        // serving traffic — give it generous headroom, mirroring FreeScout's
        // "first boot runs migrations" wait.
        $rolledOut = $this->withSpin(
            'Applying Zitadel manifests (first boot runs schema setup)...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, 'sso-zitadel', 300),
        );
        @unlink($tmp);

        if (! $rolledOut) {
            return 1;
        }

        $machinePatCaptured = $this->captureMachinePat($kubectl, $ns);

        $this->registerDeployedTool(ClusterTool::SSO, $kubectl, $host);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Zitadel is live.');
        $this->newLine();
        $this->line("  <fg=gray>Console URL:</>   <fg=blue>https://{$host}/ui/console</>");
        $this->line("  <fg=gray>Admin login:</>   <fg=blue>{$adminEmail}</> / <fg=blue>{$adminPassword}</>");
        $this->newLine();

        if (! $machinePatCaptured) {
            $this->line('  <fg=yellow>⚠ Automation token not captured yet.</> `larakube sso:wire` and');
            $this->line('  `larakube mail:create --sso` use it to talk to Zitadel\'s API. Re-run');
            $this->line("  <fg=blue>larakube sso:init {$env}</> once the pod is fully ready; if it keeps");
            $this->line('  missing, you can still wire tools by hand in the Zitadel console.');
            $this->newLine();
        }

        $this->line('  <fg=yellow>Wiring a tool to SSO</> (Gitea, Grafana, NetBird, Vaultwarden, GlitchTip):');
        $this->line('     <fg=blue>larakube sso:wire <tool></>');
        $this->newLine();
        $this->line('  <fg=yellow>Wiring Zitadel outbound email to Stalwart</>:');
        $this->line('     <fg=blue>larakube mail:wire --tool=sso</>');
        $this->newLine();

        return 0;
    }

    /**
     * The console admin's login email. --admin-email wins; otherwise prompt
     * (default: the operator's global email, else a synthetic admin@<host>).
     * Real address by default so Zitadel's password-reset/verification mail can
     * actually reach a human — and it never assumes a mail server is present.
     */
    protected function resolveAdminEmail(string $host): string
    {
        $explicit = trim((string) ($this->option('admin-email') ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $default = GlobalConfigData::load()->getEmail() ?: 'admin@'.$host;

        if ($this->option('no-interaction')) {
            return $default;
        }

        return text(
            label: 'Admin email for the Zitadel console login',
            default: $default,
            required: true,
            validate: fn (string $v) => str_contains($v, '@') ? null : 'Enter a valid email address.',
        );
    }

    /** Does the password satisfy Zitadel's default complexity policy (upper, lower, number, symbol, len >= 8)? */
    protected function isComplexEnoughForZitadel(string $password): bool
    {
        return strlen($password) >= 8
            && preg_match('/[A-Z]/', $password)
            && preg_match('/[a-z]/', $password)
            && preg_match('/[0-9]/', $password)
            && preg_match('/[^A-Za-z0-9]/', $password);
    }

    /**
     * Generate an admin password that always satisfies Zitadel's default
     * complexity policy — one guaranteed character from each required class
     * (upper/lower/number/symbol) plus random filler, shuffled. Str::password()
     * guarantees a symbol but not both letter cases; this is deterministic.
     */
    protected function generateZitadelAdminPassword(): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789', '!@#%^&*+-'];
        $chars = '';
        foreach ($sets as $set) {
            $chars .= $set[random_int(0, strlen($set) - 1)];
        }
        $chars .= Str::random(16);

        return str_shuffle($chars);
    }

    /**
     * Read back the machine-user PAT Zitadel wrote to ZITADEL_FIRSTINSTANCE_PATPATH
     * at first-instance setup (on the shared /machinekey emptyDir) via the
     * pat-reader sidecar, and cache it in sso-secrets for InteractsWithZitadelApi.
     * A miss here is NON-FATAL — the deploy already succeeded; only the CLI's own
     * API automation (sso:wire, mail:create --sso) depends on it, not Zitadel.
     */
    protected function captureMachinePat(string $kubectl, string $ns): bool
    {
        if ($this->readSsoSecret($kubectl, $ns, 'machine-pat') !== null) {
            return true; // already captured on a previous run
        }

        $pod = trim(Process::run("{$kubectl} get pod -l app=sso-zitadel -n {$ns} -o name --no-headers 2>/dev/null | head -1")->output());
        if ($pod === '') {
            return false;
        }

        // Read via the pat-reader sidecar — the Zitadel container itself is
        // distroless (no cat). The PAT lands on the shared /machinekey emptyDir.
        $pat = trim(Process::run("{$kubectl} exec -n {$ns} {$pod} -c pat-reader -- cat /machinekey/pat.txt")->output());
        if ($pat === '') {
            return false;
        }

        Process::run(
            "{$kubectl} patch secret sso-secrets -n {$ns} --type=json "
            .'-p=\'[{"op":"add","path":"/data/machine-pat","value":"'.base64_encode($pat).'"}]\'',
        );

        if ($this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace())) {
            $this->pushClusterSecret($kubectl, 'ZITADEL_MACHINE_PAT', $pat, 'production');
            // NOT syncClusterSecretToNamespace() here — same bug as the other
            // call site in this file (see deploySso()): it always syncs
            // empty and, as an Owner-mode ExternalSecret with a 1m refresh,
            // wipes sso-secrets on its next reconcile. The kubectl patch
            // above already wrote machine-pat directly; nothing else needs
            // to sync it into the namespace.
        }

        return true;
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::SSO);
    }
}
