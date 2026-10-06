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

class MailDomainsCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithMail, InteractsWithStalwartApi, LaraKubeOutput;

    protected $signature = 'mail:domains
        {environment=local : Environment whose mail server to target}
        {--context= : Target a specific kube-context}
        {--json : Output domains list as machine-readable JSON}';

    protected $description = 'List domains configured in Stalwart';

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
                $this->line((string) json_encode(['installed' => false, 'domains' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return 1;
            }

            $this->laraKubeError('Stalwart is not installed. Run `larakube tool:init --tool=stalwart` first.');

            return 1;
        }

        $domains = $this->stalwartDomains($kubectl, $ns);

        if ($domains === null) {
            if ($this->option('json')) {
                $this->line((string) json_encode(['installed' => true, 'error' => 'Could not connect to the Stalwart API.', 'domains' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return 1;
            }

            $this->laraKubeError('Could not connect to the Stalwart API.');

            return 1;
        }

        if ($domains === []) {
            if ($this->option('json')) {
                $this->line((string) json_encode(['installed' => true, 'domains' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return 0;
            }

            $this->laraKubeInfo('No domains configured. Add one in the admin UI at Directory → Domains.');

            return 0;
        }

        $accounts = $this->stalwartAccounts($kubectl, $ns) ?? [];

        $accountCounts = [];
        foreach ($accounts as $a) {
            $d = $a['domainId'] ?? '?';
            $accountCounts[$d] = ($accountCounts[$d] ?? 0) + 1;
        }

        $domainList = array_values(array_map(fn (array $d): array => [
            'id' => (string) ($d['id'] ?? '-'),
            'name' => (string) ($d['name'] ?? '-'),
            'accounts' => (int) ($accountCounts[$d['id'] ?? ''] ?? 0),
        ], $domains));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'installed' => true,
                'domains' => $domainList,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->newLine();
        table(
            ['ID', 'Domain Name', 'Accounts'],
            array_map(fn (array $d) => [
                $d['id'],
                $d['name'],
                (string) $d['accounts'],
            ], $domainList),
        );

        return 0;
    }
}
