<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Exceptions\MissingFlagException;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class MailQuotaCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithMail, InteractsWithStalwartApi, LaraKubeOutput, RequiresFlagsWhenNonInteractive;

    protected $signature = 'mail:quota
        {environment=local : Environment whose mail server to target}
        {--email= : Email address of the account}
        {--quota=   : Quota in GB (omit to prompt)}
        {--context= : Target a specific kube-context}';

    protected $description = 'Set the mailbox quota for a Stalwart account';

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

        $quotaGb = $this->option('quota');
        if ($quotaGb === null) {
            $currentQuota = $target['quota'] ?? 0;
            $quotaGb = (int) text(
                label: "Quota in GB for {$target['email']}",
                default: $currentQuota > 0 ? (string) round($currentQuota / 1073741824, 1) : '1',
                required: true,
                validate: fn (string $v) => is_numeric($v) && (float) $v >= 0 ? null : 'Must be a positive number.',
            );
        }

        $quotaBytes = (int) $quotaGb * 1073741824;

        $updated = false;
        $this->withSpin('Updating quota...', function () use (&$updated, $kubectl, $ns, $target, $quotaBytes) {
            $responses = $this->stalwartJmap($kubectl, $ns, [[
                'x:Account/set',
                [
                    'update' => [
                        $target['id'] => [
                            'quotas' => ['maxDiskQuota' => $quotaBytes],
                        ],
                    ],
                ],
                'c1',
            ]]);

            $updated = array_key_exists($target['id'], $responses[0][1]['updated'] ?? []);
        });

        if (! $updated) {
            $this->laraKubeError('Failed to update the quota. Check the Stalwart API connection.');

            return 1;
        }

        $this->laraKubeInfo("✅ Quota set to {$quotaGb} GB for {$target['email']}.");

        return 0;
    }

    protected function resolveTarget(array $accounts): ?array
    {
        $email = (string) ($this->option('email') ?? '');

        if ($email !== '') {
            foreach ($accounts as $a) {
                $addr = $a['emailAddress'] ?? ($a['name'].'@?');
                if ($addr === $email) {
                    return [
                        'id' => $a['id'],
                        'email' => $addr,
                        'name' => $a['description'] ?? $a['name'],
                        'quota' => $a['quotas']['maxDiskQuota'] ?? 0,
                    ];
                }
            }

            $this->laraKubeError("Account '{$email}' not found.");

            return null;
        }

        $options = [];
        foreach ($accounts as $a) {
            $addr = $a['emailAddress'] ?? ($a['name'].'@?');
            $quota = isset($a['quotas']['maxDiskQuota'])
                ? round($a['quotas']['maxDiskQuota'] / 1073741824, 1).' GB'
                : 'Unlimited';
            $options[$a['id']] = $addr.' — '.$quota;
        }

        // No --email and no way to ask: fail with the flag name rather than
        // hanging on a prompt that will never be answered (CI, MCP, larakube proxy).
        if ($this->cannotPrompt()) {
            throw new MissingFlagException('email', 'which account to set a quota on', 'larakube mail:quota production --email=…');
        }
        $choice = select(
            label: 'Which account would you like to update?',
            options: $options,
            scroll: count($options),
        );

        foreach ($accounts as $a) {
            if ($a['id'] === $choice) {
                return [
                    'id' => $a['id'],
                    'email' => $a['emailAddress'] ?? ($a['name'].'@?'),
                    'name' => $a['description'] ?? $a['name'],
                    'quota' => $a['quotas']['maxDiskQuota'] ?? 0,
                ];
            }
        }

        return null;
    }
}
