<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Exceptions\MissingFlagException;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithSso;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\InteractsWithZitadelApi;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class MailPasswordCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithMail, InteractsWithSso, InteractsWithStalwartApi, InteractsWithZitadelApi, LaraKubeOutput, RequiresFlagsWhenNonInteractive;

    protected $signature = 'mail:password
        {environment=local : Environment whose mail server to target}
        {--email= : Email address of the account}
        {--password= : New password (auto-generated if omitted)}
        {--force     : Skip confirmation prompt}
        {--sso       : Force-update the matching SSO password too (default when Zitadel is installed)}
        {--no-sso     : Skip the SSO password — leave Zitadel untouched}
        {--context=  : Target a specific kube-context}';

    protected $description = 'Reset the password for a Stalwart mail account';

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

        $accounts = $this->stalwartAccounts($kubectl, $ns);
        if ($accounts === null) {
            $this->laraKubeError('Could not connect to the Stalwart API.');

            return 1;
        }

        if ($accounts === []) {
            $this->laraKubeInfo('No accounts found.');

            return 0;
        }

        $target = $this->resolveTarget($accounts);

        if ($target === null) {
            return 1;
        }

        if (! $this->option('force')) {
            $this->laraKubeNewLine();
            $this->line("  <fg=yellow>⚠ This immediately invalidates {$target['email']}'s current password.</>");
            $this->line('  Any device or client still signed in with the old one — Apple Mail, Thunderbird,');
            $this->line('  IMAP/SMTP, or a tool wired via `mail:wire` — stops authenticating until you');
            $this->line('  update it with the new password.');
            $this->newLine();

            $confirmed = confirm(
                label: "Reset the password for '{$target['email']}'?",
                default: false,
            );

            if (! $confirmed) {
                $this->laraKubeInfo('Password reset cancelled.');

                return 0;
            }
        }

        $newPassword = (string) ($this->option('password') ?: Str::password(24));

        $updated = false;
        $this->withSpin('Updating password...', function () use (&$updated, $kubectl, $ns, $target, $newPassword) {
            $responses = $this->stalwartJmap($kubectl, $ns, [[
                'x:Account/set',
                [
                    'update' => [
                        $target['id'] => [
                            // (object) cast: a plain ['0' => ...] array serializes as a
                            // JSON array via json_encode(), but Stalwart's schema requires
                            // credentials to be a JSON object keyed by credential id.
                            'credentials' => (object) [
                                '0' => [
                                    '@type' => 'Password',
                                    'secret' => $newPassword,
                                ],
                            ],
                        ],
                    ],
                ],
                'c1',
            ]]);

            $updated = array_key_exists($target['id'], $responses[0][1]['updated'] ?? []);
        });

        if (! $updated) {
            $this->laraKubeError('Failed to update the password. Check the Stalwart API connection.');

            return 1;
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ Password updated for {$target['email']}.");
        $this->line("  <fg=gray>New password:</> <fg=yellow>{$newPassword}</>");
        $this->newLine();

        $this->maybeSyncSsoPassword($env, $target['email'], $newPassword);

        return 0;
    }

    /**
     * Keep the account's Zitadel password in step with the mailbox password just
     * reset, so the single shared credential mail:create set up stays shared.
     *
     * Default-ON when Zitadel is installed, mirroring mail:create's --sso/--no-sso;
     * opt out per-reset with --no-sso. Only updates an identity that already
     * exists — it never creates one (that's mail:create/mail:sync-sso's job), so
     * a mailbox with no SSO identity is a no-op with a hint, not an error. Never
     * fails the command: the mailbox password is already changed by this point.
     */
    protected function maybeSyncSsoPassword(string $env, string $email, string $password): void
    {
        $ssoKubectl = $this->ssoKubectl($this->resolveToolContext($env));
        $ssoNs = $this->ssoNamespace();

        if (! $this->isSsoInstalled($ssoKubectl, $ssoNs)) {
            if ($this->option('sso')) {
                $this->laraKubeError('--sso was requested, but Zitadel is not installed. Run `larakube sso:init` first.');
            }

            return;
        }

        $wantsSso = $this->flagOrConfirm(
            'sso',
            fn () => confirm(label: 'Also update this account\'s SSO password to match?', default: true),
            default: true,
        );

        if (! $wantsSso) {
            return;
        }

        $host = $this->resolveSsoHostReadOnly($env, null, $ssoKubectl);
        $pat = $this->readSsoSecret($ssoKubectl, $ssoNs, 'machine-pat');

        if ($host === null || $pat === null) {
            $this->laraKubeError('Mailbox password updated, but could not reach Zitadel\'s automation credentials — re-run `larakube sso:init`, then update the SSO password via the console.');

            return;
        }

        $userId = $this->zitadelFindUserByEmail($host, $pat, $email);

        if ($userId === null) {
            $this->laraKubeInfo("No matching SSO identity for {$email} — nothing to sync (create one with `larakube mail:create --sso` or `larakube mail:sync-sso`).");

            return;
        }

        if (! $this->zitadelSetPassword($host, $pat, $userId, $password)) {
            $this->laraKubeError("Mailbox password updated, but the matching SSO password could not be changed — update it in Zitadel's console.");

            return;
        }

        $this->laraKubeInfo("✅ SSO password updated for {$email} — same as the new mailbox password.");
    }

    protected function resolveTarget(array $accounts): ?array
    {
        $email = (string) ($this->option('email') ?? '');

        if ($email !== '') {
            foreach ($accounts as $a) {
                $addr = $a['emailAddress'] ?? ($a['name'].'@?');
                if ($addr === $email) {
                    return ['id' => $a['id'], 'email' => $addr, 'name' => $a['description'] ?? $a['name']];
                }
            }

            $this->laraKubeError("Account '{$email}' not found.");

            return null;
        }

        $options = [];
        foreach ($accounts as $a) {
            $addr = $a['emailAddress'] ?? ($a['name'].'@?');
            $options[$a['id']] = $addr.' — '.($a['description'] ?? $a['name']);
        }

        // No --email and no way to ask: fail with the flag name rather than
        // hanging on a prompt that will never be answered (CI, MCP, larakube proxy).
        if ($this->cannotPrompt()) {
            throw new MissingFlagException('email', 'which account to reset', 'larakube mail:password production --email=…');
        }
        $choice = select(
            label: 'Which account would you like to update?',
            options: $options,
            scroll: count($options),
        );

        foreach ($accounts as $a) {
            if ($a['id'] === $choice) {
                return ['id' => $a['id'], 'email' => $a['emailAddress'] ?? ($a['name'].'@?'), 'name' => $a['description'] ?? $a['name']];
            }
        }

        return null;
    }
}
