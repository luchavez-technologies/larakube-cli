<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class MailDomainsCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithMail, InteractsWithStalwartApi, LaraKubeOutput;

    protected $signature = 'mail:domains
        {--context= : Target a specific kube-context}
        {--env=      : Environment whose mail server to query (default: local)}';

    protected $description = 'List domains configured in Stalwart';

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

        $domains = $this->stalwartDomains($kubectl, $ns);

        if ($domains === null) {
            $this->laraKubeError('Could not connect to the Stalwart API.');

            return 1;
        }

        if ($domains === []) {
            $this->laraKubeInfo('No domains configured. Add one in the admin UI at Directory → Domains.');

            return 0;
        }

        $accounts = $this->stalwartAccounts($kubectl, $ns) ?? [];

        $accountCounts = [];
        foreach ($accounts as $a) {
            $d = $a['domainId'] ?? '?';
            $accountCounts[$d] = ($accountCounts[$d] ?? 0) + 1;
        }

        $this->newLine();
        table(
            ['ID', 'Domain Name', 'Accounts'],
            array_map(fn (array $d) => [
                $d['id'] ?? '-',
                $d['name'] ?? '-',
                (string) ($accountCounts[$d['id']] ?? 0),
            ], $domains),
        );

        return 0;
    }
}
