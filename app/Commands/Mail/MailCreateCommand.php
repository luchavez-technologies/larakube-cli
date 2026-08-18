<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithBulwark;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithSso;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\InteractsWithZitadelApi;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class MailCreateCommand extends Command
{
    use DeploysClusterTool, InteractsWithBulwark, InteractsWithClusterContext, InteractsWithMail, InteractsWithSso, InteractsWithStalwartApi, InteractsWithZitadelApi, LaraKubeOutput, RequiresFlagsWhenNonInteractive;

    protected $signature = 'mail:create
        {environment=local : Environment whose mail server to target}
        {--email= : Full email address for the new account}
        {--name=     : Display name for the account}
        {--password= : Account password (auto-generated if omitted)}
        {--quota=1   : Mailbox quota in GB}
        {--sso       : Force-create the matching SSO identity (default when Zitadel is installed)}
        {--no-sso     : Skip the SSO identity — opt out of the default sync}
        {--context=  : Target a specific kube-context}';

    protected $description = 'Create a new Stalwart mail account';

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

        $kubectl = $this->mailKubectl($context);
        $ns = $this->mailNamespace();

        if (! $this->isMailInstalled($kubectl, $ns)) {
            $this->laraKubeError('Stalwart is not installed. Run `larakube mail:init` first.');

            return 1;
        }

        $domains = $this->stalwartDomains($kubectl, $ns);
        if ($domains === null || $domains === []) {
            $this->laraKubeError('No domains are configured in Stalwart. Add one in the admin UI first.');

            return 1;
        }

        $domainId = $domains[0]['id'] ?? null;
        $domainName = $domains[0]['name'] ?? '?';
        $domainNames = array_column($domains, 'name', 'id');

        $email = (string) ($this->option('email') ?: text(
            label: 'Email address',
            placeholder: 'user@'.$domainName,
            required: true,
        ));

        $parts = explode('@', $email);
        $localPart = $parts[0];
        $givenDomain = $parts[1] ?? null;

        if ($givenDomain && isset(array_flip($domainNames)[$givenDomain])) {
            $domainId = array_flip($domainNames)[$givenDomain];
            $domainName = $givenDomain;
        }

        $displayName = (string) ($this->option('name') ?: text(
            label: 'Display name',
            placeholder: $localPart,
        ));

        $rawPassword = (string) ($this->option('password') ?: Str::password(24));

        // Prompt for quota in interactive mode (no positional email given), same
        // as email/name/password; a scripted call or --no-interaction uses --quota.
        $quotaGb = (! $this->option('email') && ! $this->option('no-interaction'))
            ? (int) text(
                label: 'Mailbox quota (GB)',
                default: (string) $this->option('quota'),
                validate: fn (string $v) => is_numeric($v) && (int) $v >= 0 ? null : 'Enter a whole number of GB (0 = unlimited).',
            )
            : (int) $this->option('quota');
        $quotaBytes = $quotaGb * 1073741824;

        $created = $this->withSpin('Creating account...', function () use ($kubectl, $ns, $localPart, $domainId, $rawPassword, $displayName, $quotaBytes) {
            $responses = $this->stalwartJmap($kubectl, $ns, [[
                'x:Account/set',
                [
                    'create' => [
                        'new1' => [
                            '@type' => 'User',
                            'name' => $localPart,
                            'domainId' => $domainId,
                            'description' => $displayName,
                            // (object) cast: a plain ['0' => ...] array serializes as a
                            // JSON array via json_encode(), but Stalwart's schema requires
                            // credentials to be a JSON object keyed by credential id.
                            'credentials' => (object) [
                                '0' => [
                                    '@type' => 'Password',
                                    'secret' => $rawPassword,
                                ],
                            ],
                            'roles' => ['@type' => 'User'],
                            'permissions' => ['@type' => 'Inherit'],
                            'quotas' => ['maxDiskQuota' => $quotaBytes],
                            'encryptionAtRest' => ['@type' => 'Disabled'],
                        ],
                    ],
                ],
                'c1',
            ]]);

            return $responses;
        });

        if ($created === null) {
            $this->laraKubeError('Failed to create account. Check the Stalwart API connection.');

            return 1;
        }

        $notCreated = $created[0][1]['notCreated'] ?? null;
        if ($notCreated) {
            $error = reset($notCreated);
            $this->laraKubeError('Account creation failed: '.($error['description'] ?? 'Unknown error'));

            return 1;
        }

        $accountId = $created[0][1]['created']['new1']['id'] ?? null;

        $host = $this->resolveMailHostReadOnly($env, $config);
        $fullEmail = "{$localPart}@{$domainName}";

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Account created successfully.');
        $this->newLine();
        $this->line("  <fg=gray>Email:</>    <fg=blue>{$fullEmail}</>");
        $this->line("  <fg=gray>Password:</> <fg=yellow>{$rawPassword}</>");
        $this->line("  <fg=gray>Name:</>     {$displayName}");
        $this->line("  <fg=gray>Quota:</>    {$quotaGb} GB");

        if ($host) {
            $this->newLine();
            $this->line('  <fg=yellow>Apple Mail / Thunderbird:</>');
            $this->line("     IMAP:  <fg=blue>{$host}</>  port <fg=blue>993</>  (SSL/TLS)   ·   SMTP:  <fg=blue>{$host}</>  port <fg=blue>465</>  (SSL/TLS)");
            $this->line("     Username: <fg=blue>{$fullEmail}</>   ·   Password: the account password above");
        } else {
            $this->newLine();
            $this->line('  <fg=gray>IMAP:</> <fg=blue>993</> (SSL/TLS)   <fg=gray>SMTP:</> <fg=blue>465</> (SSL/TLS)');
        }

        if ($this->isBulwarkInstalled($kubectl, $ns)) {
            $webmailHost = $this->resolveBulwarkHostReadOnly($env, $config);
            if ($webmailHost !== null) {
                $this->line("     <fg=gray>Or webmail:</> <fg=blue>https://{$webmailHost}</>  (same address + password)");
            }
        }
        $this->newLine();

        $this->maybeCreateSsoIdentity($env, $fullEmail, $displayName, $rawPassword);

        return 0;
    }

    /**
     * Create a matching Zitadel identity for the mailbox just created.
     *
     * Default-ON when Zitadel is installed: a mailbox without a login identity
     * can't be reached through SSO, and Stalwart won't auto-provision on first
     * OIDC sign-in (no JIT — the account must already exist), so keeping the two
     * in lockstep at creation time is the least-surprising behaviour. Opt out
     * per-account with --no-sso (e.g. a shared noreply@ that needs no login);
     * they can always be back-filled later with `larakube mail:sync-sso`.
     *
     * When Zitadel ISN'T installed this is a no-op (only --sso turns that into
     * an error). Never fails the command: the mailbox already exists by this
     * point, so a failed/skipped SSO step is a warning, not a rollback trigger.
     */
    protected function maybeCreateSsoIdentity(string $env, string $email, string $displayName, string $password): void
    {
        $ssoKubectl = $this->ssoKubectl($this->resolveToolContext($env));
        $ssoNs = $this->ssoNamespace();

        if (! $this->isSsoInstalled($ssoKubectl, $ssoNs)) {
            if ($this->option('sso')) {
                $this->laraKubeError('--sso was requested, but Zitadel is not installed. Run `larakube sso:init` first.');
            }

            return;
        }

        // Zitadel is installed, so default to syncing: --sso forces it, --no-sso
        // opts out, and both the interactive prompt and the non-interactive
        // fallback default to yes.
        $wantsSso = $this->flagOrConfirm(
            'sso',
            fn () => confirm(label: 'Also create an SSO identity for this account?', default: true),
            default: true,
        );

        if (! $wantsSso) {
            return;
        }

        $host = $this->resolveSsoHostReadOnly($env, null, $ssoKubectl);
        $pat = $this->readSsoSecret($ssoKubectl, $ssoNs, 'machine-pat');

        if ($host === null || $pat === null) {
            $this->laraKubeError('Mailbox created, but could not reach Zitadel\'s automation credentials — re-run `larakube sso:init` to recapture them, then create the SSO identity manually via the console.');

            return;
        }

        // Same password as the mailbox, so one credential unlocks both mail and
        // SSO — the account password printed above is all the user needs.
        $userId = $this->zitadelCreateUser($host, $pat, $email, $displayName, $password);

        if ($userId === null) {
            $this->laraKubeError("Mailbox created, but the matching SSO identity could not be created — check Zitadel's console.");

            return;
        }

        $this->laraKubeInfo("✅ SSO identity created for {$email} — same password as the mailbox (log in at https://{$host}).");
    }
}
