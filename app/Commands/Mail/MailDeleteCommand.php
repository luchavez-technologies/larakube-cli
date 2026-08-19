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

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class MailDeleteCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithMail, InteractsWithSso, InteractsWithStalwartApi, InteractsWithZitadelApi, LaraKubeOutput, RequiresFlagsWhenNonInteractive;

    protected $signature = 'mail:delete
        {environment=local : Environment whose mail server to target}
        {--email= : Email address of the account to delete}
        {--force    : Skip confirmation prompt}
        {--sso      : Also remove the matching SSO identity (requires sso:init)}
        {--no-sso     : Never touch SSO, even interactively}
        {--context= : Target a specific kube-context}';

    protected $description = 'Delete a Stalwart mail account';

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
            $this->laraKubeInfo('No accounts to delete.');

            return 0;
        }

        $target = $this->resolveTarget($accounts);

        if ($target === null) {
            return 1;
        }

        if (! $this->option('force')) {
            $confirmed = confirm(
                label: "Delete account '{$target['email']}' ({$target['name']})?",
                default: false,
            );

            if (! $confirmed) {
                $this->laraKubeInfo('Deletion cancelled.');

                return 0;
            }
        }

        $accountId = $target['id'];

        $destroyed = false;
        $this->withSpin('Deleting account...', function () use (&$destroyed, $kubectl, $ns, $accountId): void {
            $responses = $this->stalwartJmap($kubectl, $ns, [[
                'x:Account/set',
                ['destroy' => [$accountId]],
                'c1',
            ]]);

            $destroyed = in_array($accountId, $responses[0][1]['destroyed'] ?? [], true);
        });

        if (! $destroyed) {
            $this->laraKubeError('Failed to delete the account. Check the Stalwart API connection.');

            return 1;
        }

        $this->laraKubeInfo("✅ Account '{$target['email']}' deleted.");

        $this->maybeRemoveSsoIdentity($env, $target['email']);

        return 0;
    }

    /**
     * Mirror of MailCreateCommand::maybeCreateSsoIdentity() — --sso forces
     * removal; otherwise, if SSO is installed, ask (skippable). Never fails
     * the command: the mailbox is already gone by this point.
     */
    protected function maybeRemoveSsoIdentity(string $env, string $email): void
    {
        $ssoKubectl = $this->ssoKubectl($this->resolveToolContext($env));
        $ssoNs = $this->ssoNamespace();

        if (! $this->isSsoInstalled($ssoKubectl, $ssoNs)) {
            if ($this->option('sso')) {
                $this->laraKubeError('--sso was requested, but Zitadel is not installed. Run `larakube sso:init` first.');
            }

            return;
        }

        $wantsSso = $this->flagOrConfirm('sso', fn () => confirm(label: 'Also remove the matching SSO identity?', default: false));

        if (! $wantsSso) {
            return;
        }

        $host = $this->resolveSsoHostReadOnly($env, null, $ssoKubectl);
        $pat = $this->readSsoSecret($ssoKubectl, $ssoNs, 'machine-pat');

        if ($host === null || $pat === null) {
            $this->laraKubeError('Could not reach Zitadel\'s automation credentials — re-run `larakube sso:init` to recapture them, then remove the SSO identity manually via the console.');

            return;
        }

        $userId = $this->zitadelFindUserByEmail($host, $pat, $email);

        if ($userId === null) {
            $this->laraKubeInfo('No matching SSO identity found — nothing to remove.');

            return;
        }

        if (! $this->zitadelDeleteUser($host, $pat, $userId)) {
            $this->laraKubeError("Mailbox deleted, but the matching SSO identity could not be removed — check Zitadel's console.");

            return;
        }

        $this->laraKubeInfo("✅ SSO identity for {$email} removed.");
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
            throw new MissingFlagException('email', 'which account to delete', 'larakube mail:delete production --email=…');
        }
        $choice = select(
            label: 'Which account would you like to delete?',
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
