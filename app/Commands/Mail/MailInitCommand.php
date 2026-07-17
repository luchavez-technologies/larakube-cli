<?php

namespace App\Commands\Mail;

use App\Data\CloudData;
use App\Data\ConfigData;
use App\Enums\SharedClusterService;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithRemoteSsh;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesCloudFirewall;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class MailInitCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithMail, InteractsWithRemoteSsh, LaraKubeOutput, ManagesCloudFirewall, StreamsProcessOutput;

    protected $signature = 'mail:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--env=      : Legacy alias for the environment}
        {--domain=   : Raw override for the Stalwart cluster domain}
        {--vpn-only  : Restrict the admin UI via NetBird VPN IP whitelisting}
        {--remove    : Tear down the Stalwart stack}';

    protected $description = 'Deploy the Stalwart mail server (SMTP/IMAP/JMAP) into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->option('remove')
            ? $this->removeMail()
            : $this->deployMail();
    }

    protected function deployMail(): int
    {
        $env = $this->resolveEnvironment();
        $host = $this->resolveMailHost($env);

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = (string) $this->option('context') ?: null;
        if (! $context && $config && $env !== 'local') {
            $context = $this->environmentContextOrCurrent($config, $env);
        }

        $kubectl = $this->mailKubectl($context);
        $ns = $this->mailNamespace();
        $vpnOnly = (bool) $this->option('vpn-only');

        // Stalwart is self-contained (embedded RocksDB store on its PVC) — no Commons.
        // Keep the admin password stable across re-runs by reading it back.
        $adminPassword = $this->readMailSecret($kubectl, $ns, 'admin-password') ?? Str::random(24);

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $adminPassword) {
            Process::run(
                "{$kubectl} create secret generic mail-secrets -n {$ns} "
                .'--from-literal=recovery-admin='.escapeshellarg('admin:'.$adminPassword).' '
                .'--from-literal=admin-password='.escapeshellarg($adminPassword).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
        });

        $manifest = view('k8s.mail.stalwart', [
            'host' => $host,
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-mail.yaml';
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying Stalwart manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        $this->withSpin('Waiting for Stalwart...', fn () => $this->runStreaming(
            "{$kubectl} rollout status statefulset/stalwart -n {$ns} --timeout=180s",
            190,
        ));

        // On a cloud VPS, punch the mail L4 ports through both firewall layers
        // (DO cloud edge + host UFW) — klipper binds them, but both default-deny.
        $this->openMailPorts($env);

        $domain = $this->mailDomain($host);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Stalwart mail server is live.');
        $this->newLine();
        $this->line("  <fg=gray>Admin console:</>          <fg=blue>https://{$host}/admin</>");
        $this->line('  <fg=gray>Admin login:</>            <fg=blue>admin</> / <fg=blue>'.$adminPassword.'</>');
        $this->line('  <fg=gray>Verify anytime:</>         <fg=blue>larakube mail:check '.$env.'</> <fg=gray>— runs every check below and shows what\'s left.</>');
        $this->newLine();
        $this->line('  <fg=yellow>1. First-run setup</> — open <fg=blue>https://'.$host.'</> and complete the wizard.');
        $this->line('     <fg=gray>At the wizard\'s "Setup complete" screen, apply it with:</> <fg=blue>larakube mail:restart '.$env.'</>');
        $this->line('     <fg=gray>(the config lives in Stalwart\'s store and needs a restart to load — else /admin loops the wizard).</>');
        $this->line('     <fg=gray>Won\'t load right after (re)deploy? That\'s a stale DNS cache, not a failure —</>');
        $this->line('     <fg=gray>ExternalDNS just (re)created the record. Flush it or use an Incognito window:</>');
        $this->line('       <fg=blue>sudo dscacheutil -flushcache; sudo killall -HUP mDNSResponder</> <fg=gray>(macOS)</>');
        $this->newLine();
        $this->line("  <fg=yellow>2. Valid TLS on the mail ports</> (so Apple Mail/Thunderbird don't warn).");
        $this->line('     The web UI cert is handled by the ingress; the mail ports (465/993) need');
        $this->line('     Stalwart to hold its own cert. In <fg=gray>Settings → Server → TLS → ACME Providers</>:');
        $this->line('       • Create provider · Challenge = <fg=blue>DNS-01</>');
        $this->line('       • DNS provider = <fg=blue>Cloudflare</> · paste your API token');
        $this->line("       • Subject names = <fg=blue>{$host}</>");
        $this->newLine();
        $this->line("  <fg=yellow>3. Add the domain</> in <fg=gray>Directory → Domains</> → <fg=blue>{$domain}</>, then open its");
        $this->line('     <fg=gray>DKIM</> tab and copy the generated selector record for step 5.');
        $this->newLine();
        $this->line('  <fg=yellow>4. Create accounts</> in <fg=gray>Directory → Accounts</>:');
        $this->line('       • one per workmate (issues their email address + login)');
        $this->line("       • a <fg=blue>noreply@{$domain}</> account + an <fg=gray>application password</>");
        $this->line('         (used by <fg=gray>larakube mail:wire</>).');
        $this->newLine();
        $this->line("  <fg=yellow>5. DNS records for {$domain}</> (Cloudflare — the A record is auto-created):");
        $this->line("       MX     <fg=gray>{$domain}</>            → <fg=blue>{$host}</>  (priority 10)");
        $this->line('       TXT    <fg=gray>'.$domain.'</>            → <fg=blue>"v=spf1 mx ~all"</>');
        $this->line('       TXT    <fg=gray>(DKIM selector)</>      → paste from step 3');
        $this->line('       TXT    <fg=gray>_dmarc.'.$domain.'</>     → <fg=blue>"v=DMARC1; p=quarantine; rua=mailto:postmaster@'.$domain.'"</>');
        $this->line("       PTR    <fg=gray>rDNS on the droplet</>  → <fg=blue>{$host}</>  (set in your provider)");
        $this->newLine();
        $this->line('  <fg=yellow>6. Sending to external addresses (Gmail, etc.)</> needs an outbound relay —');
        $this->line('     most clouds (incl. DigitalOcean) block outbound port 25, so direct delivery fails.');
        $this->line('     Route outbound through Brevo/SES: <fg=blue>larakube mail:relay</> <fg=gray>(internal + inbound mail work without it).</>');
        $this->newLine();
        $this->line('  <fg=yellow>Apple Mail / Thunderbird (per workmate):</>');
        $this->line("     IMAP:  <fg=blue>{$host}</>  port <fg=blue>993</>  (SSL/TLS)   ·   SMTP:  <fg=blue>{$host}</>  port <fg=blue>465</>  (SSL/TLS)");
        $this->line('     Username: the full email address   ·   Password: the account password');
        $this->newLine();
        $this->line('  <fg=gray>Ports 25/465/587/993/4190 must be reachable.  Wire a tool:</> <fg=blue>larakube mail:wire</>');
        $this->newLine();

        return 0;
    }

    protected function removeMail(): int
    {
        // Resolve the target environment the same way deployMail() does, so
        // `mail:init production --remove` (or --env=) tears down the CLOUD install
        // instead of silently targeting the local context.
        $env = $this->resolveEnvironment();

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = (string) $this->option('context') ?: null;
        if (! $context && $config && $env !== 'local') {
            $context = $this->environmentContextOrCurrent($config, $env);
        }

        $kubectl = $this->mailKubectl($context);
        $ns = $this->mailNamespace();

        $ok = $this->removeResources(
            'Removing Stalwart resources...',
            "{$kubectl} delete statefulset/stalwart service/stalwart service/stalwart-mail ingress/stalwart secret/mail-secrets secret/mail-sender -n {$ns} --ignore-not-found",
        );

        // volumeClaimTemplate PVCs are not garbage-collected with the StatefulSet.
        $ok = $this->removeResources(
            'Removing Stalwart storage...',
            "{$kubectl} delete pvc/stalwart-data-stalwart-0 -n {$ns} --ignore-not-found",
        ) && $ok;

        if (! $ok) {
            $this->laraKubeError('One or more Stalwart resources failed to remove — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        // Reverse the firewall openings (delete the dedicated DO firewall + UFW rules).
        $this->closeMailPorts($env);

        $this->laraKubeInfo("Stalwart mail server removed from larakube-shared ({$env}).");

        return 0;
    }

    /**
     * Load the cloud (VPS) connection details for a non-local environment, or
     * null when there's nothing to act on: local, no project, or a managed
     * (context-only, no SSH host) env. Managed clusters expose L4 via a real
     * cloud LoadBalancer, so they need no firewall poking here.
     */
    protected function mailCloud(string $env): ?CloudData
    {
        if ($env === 'local') {
            return null;
        }

        $projectPath = getcwd();
        if (! file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)) {
            return null;
        }

        $cloud = ConfigData::loadFromFile($projectPath)->getCloud($env);

        return ($cloud && $cloud->ip) ? $cloud : null;
    }

    /**
     * Open Stalwart's L4 ports at both firewall layers on a VPS: the DO cloud
     * firewall (a dedicated, drift-free firewall) and the host UFW (over SSH).
     * Best-effort — a failure never fails the deploy; it prints the manual fix.
     */
    protected function openMailPorts(string $env): void
    {
        $cloud = $this->mailCloud($env);
        if ($cloud === null) {
            return;
        }

        $ports = SharedClusterService::MAIL->firewallPorts();

        // 1. DO cloud edge — skipped silently on a non-DO host / when no token.
        if ($this->ensureCloudFirewallPorts('mail', $cloud->ip, $ports)) {
            $this->laraKubeInfo('Opened mail ports on the DigitalOcean cloud firewall.');
        }

        // 2. Host UFW over SSH (prefers the VPN overlay IP when one is recorded).
        $sshIp = $cloud->vpnIp ?: $cloud->ip;
        $key = $cloud->key ? str_replace('~', home_path(), $cloud->key) : null;
        if ($sshIp && $key && file_exists($key)) {
            $script = "set -e\n".collect($ports)->map(fn ($p) => "ufw allow {$p}/tcp")->implode("\n")."\nufw reload";
            $ok = $this->runRemoteCommand($cloud->user ?? 'larakube', $sshIp, $cloud->port ?? 22, $key, $script);
            $this->laraKubeInfo($ok
                ? 'Opened mail ports in the host UFW firewall.'
                : 'Could not open UFW over SSH — do it manually: ufw allow '.implode(',', $ports).'/tcp');
        }
    }

    /** Reverse openMailPorts() on teardown. Best-effort. */
    protected function closeMailPorts(string $env): void
    {
        $cloud = $this->mailCloud($env);
        if ($cloud === null) {
            return;
        }

        $ports = SharedClusterService::MAIL->firewallPorts();
        $this->removeCloudFirewall('mail', $cloud->ip);

        $sshIp = $cloud->vpnIp ?: $cloud->ip;
        $key = $cloud->key ? str_replace('~', home_path(), $cloud->key) : null;
        if ($sshIp && $key && file_exists($key)) {
            $script = collect($ports)->map(fn ($p) => "ufw delete allow {$p}/tcp 2>/dev/null || true")->implode("\n")."\nufw reload || true";
            $this->runRemoteCommand($cloud->user ?? 'larakube', $sshIp, $cloud->port ?? 22, $key, $script);
        }
    }

    /** Best-effort mail domain (drops the leftmost "mail." label) for the noreply hint. */
    protected function mailDomain(string $host): string
    {
        $parts = explode('.', $host);

        return count($parts) > 2 ? implode('.', array_slice($parts, 1)) : $host;
    }

    protected function resolveMailHost(string $env): string
    {
        $service = SharedClusterService::MAIL;

        $domain = (string) ($this->option('domain') ?? '');
        if ($domain !== '') {
            return $service->hostFor($domain);
        }

        if ($env === 'local') {
            return (string) $this->resolveMailHostReadOnly('local', null);
        }

        return $this->promptForCloudMailHost($service, $env);
    }

    protected function resolveEnvironment(): string
    {
        $explicit = (string) ($this->argument('environment') ?: $this->option('env') ?: '');
        if ($explicit !== '') {
            return $explicit;
        }

        if ($this->option('no-interaction') || $this->option('domain')) {
            return 'local';
        }

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $envs = $config ? array_merge(['local'], $config->getCloudEnvironments()) : ['local'];

        return select(
            label: 'Which environment is this mail server for?',
            options: array_combine($envs, $envs),
            default: 'local',
        );
    }

    protected function promptForCloudMailHost(SharedClusterService $service, string $env): string
    {
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $existing = $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
        if ($existing) {
            return $existing;
        }

        $webHost = $config?->getEnvironment($env)?->hosts['web'] ?? null;
        $default = ($config && $webHost) ? $config->getSharedServiceHost($service, $env) : '';

        $host = text(
            label: "What host should {$service->label()} use in '{$env}'?",
            placeholder: $default !== '' ? $default : 'e.g. mail.example.com',
            default: $default,
            required: true,
        );

        if ($config) {
            $config->setHost($env, $service->value, $host);
            $config->saveToFile($projectPath);
            $this->laraKubeInfo("Saved {$service->label()} host for '{$env}' to .larakube.json");
        }

        return $host;
    }
}
