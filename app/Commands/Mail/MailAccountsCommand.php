<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class MailAccountsCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithMail, InteractsWithStalwartApi, LaraKubeOutput;

    protected $signature = 'mail:accounts
        {environment=local : Environment whose mail server to target}
        {--context= : Target a specific kube-context}';

    protected $description = 'List all Stalwart mail accounts';

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

        $this->newLine();
        table(
            ['Email', 'Name', 'Role', 'Quota', 'Used'],
            array_map(fn (array $a): array => [
                $a['emailAddress'] ?? ($a['name'].'@?'),
                $a['description'] ?? '-',
                ($a['roles']['@type'] ?? 'User'),
                isset($a['quotas']['maxDiskQuota'])
                    ? round($a['quotas']['maxDiskQuota'] / 1073741824, 1).' GB'
                    : 'Unlimited',
                isset($a['usedDiskQuota'])
                    ? round($a['usedDiskQuota'] / 1048576, 1).' MB'
                    : '-',
            ], $accounts),
        );

        $queued = $this->stalwartQueueCount($kubectl, $ns);
        if ($queued !== null && $queued > 0) {
            $this->newLine();
            $this->laraKubeWarn("Outbound queue: {$queued} message(s) waiting to send — inspect or clear with `larakube mail:queue`.");
        }

        return 0;
    }
}
