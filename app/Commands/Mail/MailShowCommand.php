<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithBulwark;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithSso;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\InteractsWithZitadelApi;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class MailShowCommand extends Command
{
    use DeploysClusterTool, InteractsWithBulwark, InteractsWithClusterContext, InteractsWithMail, InteractsWithPlex, InteractsWithSso, InteractsWithStalwartApi, InteractsWithZitadelApi, LaraKubeOutput;

    protected $signature = 'mail:show
        {environment=local : Environment whose mail server to show}
        {--email=   : Show client setup for this account instead of admin access (never shows its password — that\'s never recoverable; use mail:password to reset it)}
        {--context= : Target a specific kube-context}';

    protected $description = 'Show Stalwart admin credentials and access info, or a specific account\'s client setup';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = (string) $this->option('context') ?: null;
        if (! $context && $config && $env !== 'local') {
            $context = $this->environmentContextOrCurrent($config, $env);
        }

        // Same as mail:init — printPlexHint() reads the Commons through
        // plexKubectl(), which needs this or it inspects the wrong cluster.
        $this->plexContext = $context;

        $kubectl = $this->mailKubectl($context);
        $ns = $this->mailNamespace();

        if (! $this->isMailInstalled($kubectl, $ns)) {
            $this->laraKubeError('Stalwart is not installed. Run `larakube mail:init` first.');

            return 1;
        }

        $email = (string) ($this->option('email') ?? '');
        if ($email !== '') {
            return $this->showAccount($kubectl, $ns, $env, $config, $email);
        }

        $host = $this->resolveMailHostReadOnly($env, $config);
        $adminPassword = $this->readMailSecret($kubectl, $ns, 'admin-password');

        if ($adminPassword === null) {
            $this->laraKubeError('Could not read admin password from secrets.');

            return 1;
        }

        $this->newLine();
        $this->line(' <fg=blue>Stalwart Mail Server</>');
        $this->line(' '.str_repeat('─', 40));
        $this->newLine();

        if ($host) {
            $this->line("  <fg=gray>Admin URL:</>      <fg=blue>https://{$host}/admin</>");
        }
        $this->line('  <fg=gray>Admin login:</>    <fg=blue>admin</>');
        $this->line("  <fg=gray>Admin password:</> <fg=yellow>{$adminPassword}</>");
        $this->newLine();

        if ($host) {
            $this->line('  <fg=gray>IMAP:</>          <fg=blue>'.$host.'</>  port <fg=blue>993</> (SSL/TLS)');
            $this->line('  <fg=gray>SMTP:</>          <fg=blue>'.$host.'</>  port <fg=blue>465</> (SSL/TLS)');
            $this->line('  <fg=gray>SMTP (alt):</>    <fg=blue>'.$host.'</>  port <fg=blue>587</> (STARTTLS — only if you added a 587 listener)');
            $this->newLine();
        }

        $queued = $this->stalwartQueueCount($kubectl, $ns);
        if ($queued !== null) {
            $status = $queued === 0
                ? '<fg=green>0</> — nothing waiting'
                : "<fg=yellow>{$queued} waiting</> — inspect/clear with <fg=blue>larakube mail:queue</>";
            $this->line("  <fg=gray>Outbound queue:</> {$status}");
            $this->newLine();
        }

        $webmail = $this->webmailUrl($kubectl, $ns, $env, $config);
        if ($webmail !== null) {
            $this->line("  <fg=gray>Webmail:</>       <fg=blue>{$webmail}</>  (browser client for the team)");
            $this->newLine();
        }

        $this->line('  Share these credentials with your teammates so they can');
        $this->line('  configure their email clients (Apple Mail, Thunderbird, etc.).');
        $this->newLine();

        if ($host) {
            $this->printPlexHint($kubectl, $host, storeBootstrap: $this->detectStoreBootstrap($kubectl, $ns));
        }

        return 0;
    }

    /**
     * Reconstruct a printPlexHint()-shaped storeBootstrap array from LIVE
     * server state, for installs deployed via the local wizard-skip path
     * (MailInitCommand::bootstrapStalwartStoreForLocal()). mail:show has no
     * access to the array mail:init built at deploy time, so it detects the
     * same thing a different way: the 'stalwart-config' ConfigMap only
     * exists on that path (it's what pre-seeds config.json and skips
     * bootstrap mode), so its presence is the signal; the actual per-store
     * details then come from asking Stalwart's own management API what's
     * really configured right now, not from re-deriving Commons state.
     *
     * Returns null on any wizard-driven install — printPlexHint() then keeps
     * its original, unmodified behaviour for the majority of clusters that
     * haven't opted into this experimental path.
     */
    protected function detectStoreBootstrap(string $kubectl, string $ns): ?array
    {
        $configMapExists = trim(Process::run(
            "{$kubectl} get configmap stalwart-config -n {$ns} --no-headers --ignore-not-found",
        )->output()) !== '';

        if (! $configMapExists) {
            return null;
        }

        $blob = $this->stalwartJmap($kubectl, $ns, [['x:BlobStore/get', ['ids' => ['singleton']], 'c1']])[0][1]['list'][0] ?? null;
        $redisType = $this->stalwartJmap($kubectl, $ns, [['x:InMemoryStore/get', ['ids' => ['singleton']], 'c1']])[0][1]['list'][0]['@type'] ?? null;
        $searchType = $this->stalwartJmap($kubectl, $ns, [['x:SearchStore/get', ['ids' => ['singleton']], 'c1']])[0][1]['list'][0]['@type'] ?? null;

        $backend = 'seaweedfs';
        $endpoint = (string) ($blob['region']['customEndpoint'] ?? '');
        foreach (['seaweedfs', 'minio', 'garage'] as $candidate) {
            if (str_contains($endpoint, "{$candidate}.")) {
                $backend = $candidate;
                break;
            }
        }

        return [
            'blob' => ($blob['@type'] ?? null) === 'S3' ? ['backend' => $backend] : null,
            'redis' => $redisType === 'Redis' ? ['url' => ''] : null,
            'search' => ['type' => $searchType === 'Meilisearch' ? 'meilisearch' : 'default'],
        ];
    }

    /**
     * Print an existing account's client setup — host, ports, username. Never
     * the password: Stalwart only stores a hash, so a lost password can only
     * be replaced (`mail:password <email>`), never recovered.
     */
    protected function showAccount(string $kubectl, string $ns, string $env, ?ConfigData $config, string $email): int
    {
        $accounts = $this->stalwartAccounts($kubectl, $ns);
        if ($accounts === null) {
            $this->laraKubeError('Could not connect to the Stalwart API.');

            return 1;
        }

        $account = null;
        foreach ($accounts as $a) {
            if (($a['emailAddress'] ?? ($a['name'].'@?')) === $email) {
                $account = $a;
                break;
            }
        }

        if ($account === null) {
            $this->laraKubeError("Account '{$email}' not found.");

            return 1;
        }

        $host = $this->resolveMailHostReadOnly($env, $config);

        $this->newLine();
        $this->line(" <fg=blue>{$email}</>");
        $this->line(' '.str_repeat('─', 40));
        $this->newLine();
        $this->line('  <fg=gray>Name:</>  '.($account['description'] ?? $account['name']));
        $this->line('  <fg=gray>Role:</>  '.($account['roles']['@type'] ?? 'User'));
        $this->newLine();

        if ($host) {
            $this->line('  <fg=yellow>Apple Mail / Thunderbird:</>');
            $this->line("     IMAP:  <fg=blue>{$host}</>  port <fg=blue>993</>  (SSL/TLS)   ·   SMTP:  <fg=blue>{$host}</>  port <fg=blue>465</>  (SSL/TLS)");
            $this->line("     Username: <fg=blue>{$email}</>");
        }

        $webmail = $this->webmailUrl($kubectl, $ns, $env, $config);
        if ($webmail !== null) {
            $this->line("  <fg=yellow>Webmail:</> <fg=blue>{$webmail}</>  — log in with this address + password");
        }

        $ssoLine = $this->ssoStatusLine($env, $email);
        if ($ssoLine !== null) {
            $this->line($ssoLine);
        }

        $this->newLine();
        $this->line("  <fg=gray>Password isn't recoverable — Stalwart only stores a hash.</>");
        $this->line('  <fg=gray>Lost it? Issue a new one:</>');
        $this->line("  <fg=blue>larakube mail:password {$env} --email={$email}</>");
        $this->newLine();

        return 0;
    }

    /**
     * "SSO: yes/no" status line for showAccount() — null when Zitadel isn't
     * installed at all (nothing worth mentioning), otherwise looks the
     * account up by email. A lookup failure (credentials unreachable) is
     * reported as "unknown", not silently treated as "no".
     */
    protected function ssoStatusLine(string $env, string $email): ?string
    {
        $ssoKubectl = $this->ssoKubectl($this->resolveToolContext($env));
        $ssoNs = $this->ssoNamespace();

        if (! $this->isSsoInstalled($ssoKubectl, $ssoNs)) {
            return null;
        }

        $host = $this->resolveSsoHostReadOnly($env, null, $ssoKubectl);
        $pat = $this->readSsoSecret($ssoKubectl, $ssoNs, 'machine-pat');

        if ($host === null || $pat === null) {
            return '  <fg=gray>SSO:</>     <fg=gray>unknown (could not reach Zitadel\'s automation credentials)</>';
        }

        $userId = $this->zitadelFindUserByEmail($host, $pat, $email);

        return $userId !== null
            ? "  <fg=gray>SSO:</>     <fg=green>yes</> — log in at <fg=blue>https://{$host}</>"
            : '  <fg=gray>SSO:</>     <fg=gray>no</>';
    }

    /**
     * The Bulwark webmail URL for this environment, or null when Bulwark isn't
     * installed. Bulwark shares Stalwart's namespace, so the mail kubectl works.
     */
    protected function webmailUrl(string $kubectl, string $ns, string $env, ?ConfigData $config): ?string
    {
        if (! $this->isBulwarkInstalled($kubectl, $ns)) {
            return null;
        }

        $host = $this->resolveBulwarkHostReadOnly($env, $config);

        return $host !== null ? "https://{$host}" : null;
    }
}
