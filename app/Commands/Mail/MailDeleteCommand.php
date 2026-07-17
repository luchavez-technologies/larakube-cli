<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class MailDeleteCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithMail, InteractsWithStalwartApi, LaraKubeOutput;

    protected $signature = 'mail:delete
        {email?     : Email address of the account to delete}
        {--force    : Skip confirmation prompt}
        {--context= : Target a specific kube-context}
        {--env=      : Environment whose mail server to use (default: local)}';

    protected $description = 'Delete a Stalwart mail account';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) ($this->option('env') ?: 'local');
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
        $this->withSpin('Deleting account...', function () use (&$destroyed, $kubectl, $ns, $accountId) {
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

        return 0;
    }

    protected function resolveTarget(array $accounts): ?array
    {
        $email = (string) ($this->argument('email') ?? '');

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
