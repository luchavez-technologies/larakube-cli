<?php

namespace App\Commands\Templates;

use App\Facades\State;
use App\Services\TemplateStore;
use App\Traits\EmitsJsonOutput;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class TemplatesListCommand extends Command
{
    use EmitsJsonOutput, LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'templates:list
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'List installed app template releases and their verification status';

    public function handle(TemplateStore $store): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        } else {
            State::setJsonMode(false);
            State::setStdout(null);
        }

        $store->activate();

        $installed = $store->installed();
        $modified = $store->modified();
        $refused = $store->refused();

        $rows = [];
        foreach ($installed as $lockKey => $entry) {
            [$app, $engine] = str_contains($lockKey, '/')
                ? explode('/', $lockKey, 2)
                : [$lockKey, 'default'];

            $status = 'ok';
            if (in_array($lockKey, $refused, true)) {
                $status = 'refused';
            } elseif (in_array($lockKey, $modified, true)) {
                $status = 'modified';
            }

            $rows[] = [
                'app' => $app,
                'engine' => $engine,
                'version' => (string) ($entry['version'] ?? ''),
                'contract' => (int) ($entry['contract'] ?? 1),
                'key_id' => (string) ($entry['key_id'] ?? ''),
                'fetched_at' => (string) ($entry['fetched_at'] ?? ''),
                'trusted_hash' => $entry['trusted_hash'] ?? null,
                'status' => $status,
            ];
        }

        if ($this->flag('json')) {
            $this->jsonOutput(['apps' => $rows]);

            return self::SUCCESS;
        }

        $this->renderHeader();
        $this->surfaceActivationWarnings($store);

        if ($rows === []) {
            $this->laraKubeLine('No template releases installed.');

            return self::SUCCESS;
        }

        table(
            headers: ['App', 'Engine', 'Version', 'Contract', 'Key', 'Status'],
            rows: array_map(
                static fn (array $row): array => [
                    $row['app'],
                    $row['engine'],
                    $row['version'],
                    (string) $row['contract'],
                    $row['key_id'],
                    $row['status'],
                ],
                $rows,
            ),
        );

        return self::SUCCESS;
    }

    private function surfaceActivationWarnings(TemplateStore $store): void
    {
        foreach ($store->modified() as $lockKey) {
            $message = "Template '{$lockKey}' has local modifications (trusted).";
            $this->laraKubeWarn($message);
            $this->laraKubeLine("⚠ {$message}");
        }

        foreach ($store->refused() as $lockKey) {
            $message = "Template '{$lockKey}' has untrusted modifications and was refused (run `larakube templates:trust`).";
            $this->laraKubeWarn($message);
            $this->laraKubeLine("⚠ {$message}");
        }
    }
}
