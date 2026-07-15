<?php

namespace App\Commands\Vpn;

use App\Data\ConfigData;
use App\Enums\SharedClusterService;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithVpn;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class VpnInitCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithProjectConfig, InteractsWithVpn, LaraKubeOutput, StreamsProcessOutput;

    protected $signature = 'vpn:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted. A non-local env prompts for + persists the NetBird VPN host.}
        {--context=  : Target a specific kube-context (defaults to current context)}
        {--env=      : Legacy alias for the environment argument}
        {--domain=   : Raw override for the NetBird VPN cluster domain (e.g. example.com → vpn.example.com); skips the prompt}
        {--remove    : Tear down the NetBird VPN stack from larakube-vpn}';

    protected $description = 'Deploy the cluster-wide NetBird VPN stack into larakube-vpn';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->option('remove')
            ? $this->removeVpn()
            : $this->deployVpn();
    }

    protected function deployVpn(): int
    {
        $ns = $this->vpnNamespace();
        $config = $this->getProjectConfig();
        $env = $this->resolveEnvironment($config);
        $kubectl = $this->vpnKubectl($this->resolveVpnContext($env, $config));

        $host = $this->resolveVpnHost($env, $config);

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        // Must exist BEFORE the Deployments below apply — management mounts
        // management.json and relay reads NB_AUTH_SECRET from it, so without
        // this first, both would sit in CreateContainerConfigError and the
        // rollout waits below would time out.
        $this->ensureVpnConfig($kubectl, $ns, $host);

        $manifest = view('k8s.vpn.shared', [
            'host' => $host,
            'isLocal' => $env === 'local',
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-vpn.yaml';
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying NetBird VPN manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        $this->withSpin('Waiting for NetBird Management...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/netbird-management -n {$ns} --timeout=120s",
            130,
        ));
        $this->withSpin('Waiting for NetBird Signal...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/netbird-signal -n {$ns} --timeout=120s",
            130,
        ));
        $this->withSpin('Waiting for NetBird Relay...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/netbird-relay -n {$ns} --timeout=120s",
            130,
        ));

        // The client Deployment authenticates with NB_SETUP_KEY, so it can only
        // be applied AFTER bootstrapVpnAuth() mints one — applying it earlier
        // would leave it permanently unable to log in (no key to reference yet).
        $this->waitForTls($host);
        $this->bootstrapVpnAuth($kubectl, $ns, $host);

        $clientManifest = view('k8s.vpn.client')->render();
        $clientTmp = sys_get_temp_dir().'/larakube-vpn-client.yaml';
        file_put_contents($clientTmp, $clientManifest);

        $this->withSpin('Deploying NetBird Client...', fn () => $this->runStreaming("{$kubectl} apply -f {$clientTmp}"));
        @unlink($clientTmp);

        $this->withSpin('Waiting for NetBird Client...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/netbird-client -n {$ns} --timeout=120s",
            130,
        ));

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ NetBird VPN stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>NetBird Admin URL:</>            <fg=blue>https://{$host}</>");
        $this->newLine();

        return 0;
    }

    protected function removeVpn(): int
    {
        $ns = $this->vpnNamespace();
        $config = $this->getProjectConfig();
        $env = $this->resolveEnvironment($config);
        $kubectl = $this->vpnKubectl($this->resolveVpnContext($env, $config));

        $this->withSpin('Removing NetBird VPN namespace...', fn () => Process::run(
            "{$kubectl} delete namespace {$ns} --ignore-not-found",
        ));

        $this->laraKubeInfo('NetBird VPN removed from larakube-vpn.');

        return 0;
    }

    /**
     * The relay's shared auth secret + management.json (Signal/Relay wired to
     * this install's EXTERNAL host, since peers dial them directly over the
     * public Ingress, not the in-cluster DNS names the other env vars in
     * shared.blade.php use) — both hold a real secret, so this is a k8s
     * Secret, not a ConfigMap. Idempotent: skipped once it exists, since
     * rotating it would just orphan already-joined peers.
     */
    protected function ensureVpnConfig(string $kubectl, string $ns, string $host): void
    {
        if (Process::run("{$kubectl} get secret netbird-relay-secret -n {$ns}")->successful()) {
            return;
        }

        $this->withSpin('Preparing NetBird relay config...', function () use ($kubectl, $ns, $host) {
            $relaySecret = bin2hex(random_bytes(16));
            $this->registerSecret($relaySecret);

            // management.json is mounted from a Secret via subPath, which k8s
            // always mounts read-only — so this key must be baked in up front.
            // Without it, netbird-management tries to generate + write one back
            // to the file on first boot and crashloops on "read-only file system".
            // Also doubles as EmbeddedIdP's EncryptionKey (below) — without that
            // block (undocumented in NetBird's own automated-setup guide; found
            // by trial and error), POST /api/setup fails with "embedded IDP is
            // not enabled".
            $dataStoreEncryptionKey = base64_encode(random_bytes(32));
            $this->registerSecret($dataStoreEncryptionKey);

            $managementConfig = view('k8s.vpn.management-config', [
                'host' => $host,
                'relaySecret' => $relaySecret,
                'dataStoreEncryptionKey' => $dataStoreEncryptionKey,
            ])->render();

            $tmp = sys_get_temp_dir().'/larakube-vpn-management.json';
            file_put_contents($tmp, $managementConfig);

            Process::run(
                "{$kubectl} create secret generic netbird-relay-secret -n {$ns} "
                .'--from-literal=relay-secret='.escapeshellarg($relaySecret).' '
                .'--from-file=management.json='.escapeshellarg($tmp).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
            @unlink($tmp);
        });
    }

    /**
     * Bootstrap NetBird auth with zero browser/dashboard interaction: create the
     * first owner user + a Personal Access Token via NB_SETUP_PAT_ENABLED's
     * POST /api/setup, then mint one reusable setup key from it. Both are stored
     * as a k8s Secret (same create|apply pattern as ConfigData::backupToCluster())
     * so any teammate with kubectl access to this cluster can fetch the setup key
     * for `vpn:join` / `cloud:harden` — no separate secret-sharing channel needed.
     * Idempotent: skipped entirely once the Secret exists, since re-POSTing
     * /api/setup against an already-bootstrapped instance would just fail.
     */
    protected function bootstrapVpnAuth(string $kubectl, string $ns, string $host): void
    {
        if (Process::run("{$kubectl} get secret netbird-admin -n {$ns}")->successful()) {
            return;
        }

        $this->withSpin('Bootstrapping NetBird auth (no dashboard login needed)...', function () use ($kubectl, $ns, $host) {
            $password = bin2hex(random_bytes(16));
            $email = $this->getEmail() ?: "admin@{$host}";

            // Retry the /api/setup POST — the TLS wait above confirms the cert
            // is valid, but the management pod may still need a moment to
            // accept connections through the Ingress.
            $setup = null;
            $maxAttempts = (int) 6;
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                try {
                    $setup = Http::timeout(15)->post("https://{$host}/api/setup", [
                        'email' => $email,
                        'name' => 'larakube',
                        'password' => $password,
                        'create_pat' => true,
                        'pat_expire_in' => 365,
                    ]);
                    break;
                } catch (\Illuminate\Http\Client\ConnectionException $e) {
                    if ($attempt === $maxAttempts || \App\State::$isTesting) {
                        $this->laraKubeWarn('Could not reach NetBird management after multiple attempts — run `larakube vpn:init` again once the endpoint is reachable.');

                        return;
                    }
                    sleep(5);
                }
            }

            $pat = $setup?->json('personal_access_token');
            if (! $setup || $setup->failed() || ! $pat) {
                $this->laraKubeWarn('Could not bootstrap NetBird auth automatically — log into the dashboard once to finish setup.');

                return;
            }
            $this->registerSecret($pat);

            // 1 year — matches the PAT's own 365-day cap above, so both need
            // renewing around the same time (a known follow-up, not handled here).
            $setupKey = Http::timeout(15)
                ->withHeaders(['Authorization' => "Token {$pat}"])
                ->post("https://{$host}/api/setup-keys", [
                    'name' => 'larakube',
                    'type' => 'reusable',
                    'expires_in' => 31536000,
                    'usage_limit' => 0,
                ]);

            $key = $setupKey->json('key');
            if ($setupKey->failed() || ! $key) {
                $this->laraKubeWarn('NetBird owner created, but minting a setup key failed — create one manually in the dashboard for `vpn:join`.');

                return;
            }
            $this->registerSecret($key);

            Process::run(
                "{$kubectl} create secret generic netbird-admin -n {$ns} "
                .'--from-literal=pat='.escapeshellarg($pat).' '
                .'--from-literal=setup-key='.escapeshellarg($key).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
        });
    }

    /**
     * Wait for TLS to become valid on the VPN host — Traefik's ACME resolver
     * needs a few seconds after the Ingress is created to complete the Let's
     * Encrypt challenge. Without this gate, bootstrapVpnAuth() would fire an
     * HTTPS call against a self-signed/missing cert and crash with cURL error 60.
     */
    protected function waitForTls(string $host): void
    {
        if (\App\State::$isTesting) {
            return;
        }

        $this->withSpin('Waiting for TLS certificate (Let\'s Encrypt)...', function () use ($host) {
            $maxWait = 90;
            $start = time();

            while (time() - $start < $maxWait) {
                // Check if we can connect and verify TLS. curl exits with 0 on success.
                $result = Process::run('curl -sSf -o /dev/null '.escapeshellarg("https://{$host}"));
                if ($result->successful()) {
                    return;
                }

                sleep(5);
            }

            $this->laraKubeWarn("TLS not ready after {$maxWait}s — proceeding anyway (auth bootstrap may fail; re-run `vpn:init` if it does).");
        });
    }

    /**
     * Resolve the NetBird VPN ingress host for this install.
     */
    protected function resolveVpnHost(string $env, ?ConfigData $config): string
    {
        $service = SharedClusterService::VPN;

        $domain = (string) ($this->option('domain') ?? '');
        if ($domain !== '') {
            return $service->hostFor($domain);
        }

        if ($env === 'local') {
            return (string) $this->resolveVpnHostReadOnly('local', null);
        }

        return $this->promptForCloudVpnHost($service, $env, $config);
    }

    /**
     * Decide which environment this install targets.
     */
    protected function resolveEnvironment(?ConfigData $config): string
    {
        $explicit = (string) ($this->argument('environment') ?: $this->option('env') ?: '');
        if ($explicit !== '') {
            return $explicit;
        }

        if ($this->option('no-interaction') || $this->option('domain')) {
            return 'local';
        }

        $envs = $config ? array_merge(['local'], $config->getCloudEnvironments()) : ['local'];

        return select(
            label: 'Which environment is this NetBird VPN install for?',
            options: array_combine($envs, $envs),
            default: 'local',
            hint: 'Local uses your dev TLD; a cloud env asks for + persists the NetBird VPN host.',
        );
    }

    /**
     * Prompt for (and persist) a non-local NetBird VPN host.
     */
    protected function promptForCloudVpnHost(SharedClusterService $service, string $env, ?ConfigData $config): string
    {
        $existing = $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
        if ($existing) {
            return $existing;
        }

        $webHost = $config?->getEnvironment($env)?->hosts['web'] ?? null;
        $default = ($config && $webHost) ? $config->getSharedServiceHost($service, $env) : '';

        $host = text(
            label: "What host should {$service->label()} use in '{$env}'?",
            placeholder: $default !== '' ? $default : 'e.g. vpn.example.com',
            default: $default,
            required: true,
            hint: 'Point this DNS at the cluster and add TLS like any other ingress host.',
        );

        if ($config) {
            $config->setHost($env, $service->value, $host);
            $config->saveToFile(getcwd());
            $this->laraKubeInfo("Saved {$service->label()} host for '{$env}' to .larakube.json");
        }

        return $host;
    }
}
