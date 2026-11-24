<?php

namespace App\Commands\Templates;

use App\Enums\ClusterTool;
use App\Facades\State;
use App\Http\Integrations\LaraKubeCloud\LaraKubeCloudConnector;
use App\Http\Integrations\LaraKubeCloud\Requests\LatestRelease;
use App\Services\TemplateStore;
use App\Traits\EmitsJsonOutput;
use App\Traits\FailsWithJson;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;
use Throwable;

class TemplatesOutdatedCommand extends Command
{
    use EmitsJsonOutput, FailsWithJson, LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'templates:outdated
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Check LaraKube Cloud for newer compatible app template releases';

    public function handle(TemplateStore $store): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        } else {
            State::setJsonMode(false);
            State::setStdout(null);
        }

        $store->activate();

        $connector = new LaraKubeCloudConnector;
        $rows = [];

        foreach ($store->installed() as $lockKey => $entry) {
            [$app, $engine] = str_contains($lockKey, '/')
                ? explode('/', $lockKey, 2)
                : [$lockKey, 'default'];

            $installedVersion = (string) ($entry['version'] ?? '');
            $tool = ClusterTool::tryFrom($app);
            $contracts = $tool !== null
                ? $tool->templateContracts($engine === 'default' ? null : $engine)
                : [(int) ($entry['contract'] ?? 1)];

            $latestVersion = null;
            $security = false;
            $note = '';
            $outdated = false;

            try {
                $response = $connector->send(new LatestRelease($app, $engine, $contracts));

                if ($response->status() === 404) {
                    $bodyMessage = trim((string) ($response->json('message') ?? $response->json('error') ?? ''));
                    if ($bodyMessage === '') {
                        $note = 'update the LaraKube CLI';
                    } elseif (str_contains(strtolower($bodyMessage), 'update the larakube cli')) {
                        $note = $bodyMessage;
                    } else {
                        $note = "{$bodyMessage} (update the LaraKube CLI)";
                    }
                } elseif ($response->status() === 402) {
                    $note = 'This app needs a LaraKube Cloud plan';
                } elseif ($response->successful()) {
                    $manifest = $response->json('manifest');
                    if (is_array($manifest)) {
                        $latestVersion = is_string($manifest['version'] ?? null) ? $manifest['version'] : null;
                        $security = (bool) ($manifest['security'] ?? false);
                        $outdated = $latestVersion !== null && version_compare($latestVersion, $installedVersion, '>');
                    }
                    $note = trim((string) ($response->json('note') ?? $response->json('message') ?? ''));
                } else {
                    $note = trim((string) ($response->json('message') ?? "HTTP {$response->status()}"));
                }
            } catch (Throwable $e) {
                $note = $e->getMessage();
            }

            $rows[] = [
                'app' => $app,
                'engine' => $engine,
                'installed' => $installedVersion,
                'latest' => $latestVersion,
                'outdated' => $outdated,
                'security' => $security,
                'security_fix' => $security ? 'yes' : 'no',
                'note' => $note,
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
            headers: ['App', 'Engine', 'Installed', 'Latest', 'Security fix', 'Note'],
            rows: array_map(
                static fn (array $row): array => [
                    $row['app'],
                    $row['engine'],
                    $row['installed'],
                    $row['latest'] ?? '-',
                    $row['security_fix'],
                    $row['note'],
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
