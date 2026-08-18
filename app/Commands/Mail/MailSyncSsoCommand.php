<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithSso;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\InteractsWithZitadelApi;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class MailSyncSsoCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithMail, InteractsWithSso, InteractsWithStalwartApi, InteractsWithZitadelApi, LaraKubeOutput;

    protected $signature = 'mail:sync-sso
        {environment=local : Environment whose mail server to target}
        {--context=  : Target a specific kube-context}';

    protected $description = 'Import existing Stalwart mail accounts into Zitadel SSO identities';

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

        $mailKubectl = $this->mailKubectl($context);
        $mailNs = $this->mailNamespace();

        if (! $this->isMailInstalled($mailKubectl, $mailNs)) {
            $this->laraKubeError('Stalwart is not installed. Run `larakube mail:init` first.');

            return 1;
        }

        $ssoKubectl = $this->ssoKubectl($context);
        $ssoNs = $this->ssoNamespace();

        if (! $this->isSsoInstalled($ssoKubectl, $ssoNs)) {
            $this->laraKubeError('Zitadel SSO is not installed. Run `larakube sso:init` first.');

            return 1;
        }

        $ssoHost = $this->resolveSsoHostReadOnly($env, $config, $ssoKubectl);
        $pat = $this->readSsoSecret($ssoKubectl, $ssoNs, 'machine-pat');

        if ($ssoHost === null || $pat === null) {
            $this->laraKubeError('Could not read Zitadel automation token. Re-run `larakube sso:init`.');

            return 1;
        }

        $accounts = $this->stalwartAccounts($mailKubectl, $mailNs);
        if ($accounts === null || $accounts === []) {
            $this->laraKubeInfo('No Stalwart mail accounts found to sync.');

            return 0;
        }

        $rows = [];
        $synced = 0;
        $failed = 0;

        $this->withSpin('Syncing Stalwart accounts to Zitadel SSO...', function () use ($accounts, $ssoHost, $pat, &$rows, &$synced, &$failed) {
            foreach ($accounts as $acc) {
                $name = $acc['name'] ?? '';
                $domainId = $acc['domainId'] ?? '';
                $displayName = $acc['description'] ?? $name;

                if ($name === '' || $name === 'admin' || $domainId === '') {
                    continue; // Skip admin and system accounts
                }

                $fullEmail = $acc['emailAddress'] ?? (str_contains($name, '@') ? $name : "{$name}@{$domainId}");
                $userId = $this->zitadelCreateUser($ssoHost, $pat, $fullEmail, $displayName);

                if ($userId !== null) {
                    $synced++;
                    $rows[] = [$fullEmail, $displayName, 'Created / Synced'];
                } else {
                    $failed++;
                    $rows[] = [$fullEmail, $displayName, 'Skipped / Failed'];
                }
            }
        });

        $this->laraKubeNewLine();
        $this->laraKubeInfo("Stalwart → Zitadel SSO Sync Complete ({$synced} synced, {$failed} skipped/failed).");
        $this->newLine();

        if ($rows !== []) {
            table(
                headers: ['Email Address', 'Display Name', 'SSO Status'],
                rows: $rows,
            );
        }

        return 0;
    }
}
