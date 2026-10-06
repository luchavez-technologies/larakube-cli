<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Services\Kubectl;
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
        {--context= : Target a specific kube-context}
        {--json : Output accounts list as machine-readable JSON}';

    protected $description = 'List all Stalwart mail accounts';

    public function handle(): int
    {
        if (! $this->option('json')) {
            $this->renderHeader();
        }

        $env = (string) $this->argument('environment');
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = (string) $this->option('context') ?: null;
        if (! $context && $config && $env !== 'local') {
            $context = $this->environmentContextOrCurrent($config, $env);
        }

        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = $this->mailNamespace();

        if (! $this->isMailInstalled($kubectl, $ns)) {
            if ($this->option('json')) {
                $this->line((string) json_encode(['installed' => false, 'accounts' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return 1;
            }

            $this->laraKubeError('Stalwart is not installed. Run `larakube tool:init --tool=stalwart` first.');

            return 1;
        }

        $accounts = $this->stalwartAccounts($kubectl, $ns);

        if ($accounts === null) {
            if ($this->option('json')) {
                $this->line((string) json_encode(['installed' => true, 'error' => 'Could not connect to the Stalwart API.', 'accounts' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return 1;
            }

            $this->laraKubeError('Could not connect to the Stalwart API.');

            return 1;
        }

        $accountList = array_values(array_map(function (array $a): array {
            $quotaBytes = isset($a['quotas']['maxDiskQuota']) ? (int) $a['quotas']['maxDiskQuota'] : null;
            $usedBytes = isset($a['usedDiskQuota']) ? (int) $a['usedDiskQuota'] : null;

            return [
                'email' => (string) ($a['emailAddress'] ?? ($a['name'].'@?')),
                'name' => (string) ($a['description'] ?? $a['name'] ?? '-'),
                'role' => (string) ($a['roles']['@type'] ?? 'User'),
                'quota' => $quotaBytes !== null ? round($quotaBytes / 1073741824, 1).' GB' : 'Unlimited',
                'quotaBytes' => $quotaBytes,
                'used' => $usedBytes !== null ? round($usedBytes / 1048576, 1).' MB' : '-',
                'usedBytes' => $usedBytes,
            ];
        }, $accounts));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'installed' => true,
                'accounts' => $accountList,
                'queue' => (int) ($this->stalwartQueueCount($kubectl, $ns) ?? 0),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if ($accounts === []) {
            $this->laraKubeInfo('No accounts found.');

            return 0;
        }

        $this->newLine();
        table(
            ['Email', 'Name', 'Role', 'Quota', 'Used'],
            array_map(fn (array $a): array => [
                $a['email'],
                $a['name'],
                $a['role'],
                $a['quota'],
                $a['used'],
            ], $accountList),
        );

        $queued = $this->stalwartQueueCount($kubectl, $ns);
        if ($queued !== null && $queued > 0) {
            $this->newLine();
            $this->laraKubeWarn("Outbound queue: {$queued} message(s) waiting to send — inspect or clear with `larakube mail:queue`.");
        }

        return 0;
    }
}
