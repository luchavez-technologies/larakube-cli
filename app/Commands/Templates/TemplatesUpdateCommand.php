<?php

namespace App\Commands\Templates;

use App\Enums\ClusterTool;
use App\Facades\State;
use App\Services\TemplateStore;
use App\Traits\EmitsJsonOutput;
use App\Traits\FailsWithJson;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class TemplatesUpdateCommand extends Command
{
    use EmitsJsonOutput, FailsWithJson, LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'templates:update
        {app? : The app slug to update (updates all installed apps when omitted)}
        {--engine= : The engine slug, for multi-engine apps}
        {--force : Overwrite modified-and-trusted template releases}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Download newer compatible app template releases from LaraKube Cloud';

    public function handle(TemplateStore $store): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        } else {
            State::setJsonMode(false);
            State::setStdout(null);
        }

        $store->activate();

        if (! $this->flag('json')) {
            $this->renderHeader();
            $this->surfaceActivationWarnings($store);
        }

        $installed = $store->installed();
        $modified = $store->modified();
        $refused = $store->refused();
        $appArg = $this->argument('app');
        $engineOption = trim((string) ($this->option('engine') ?? ''));
        $force = (bool) $this->option('force');

        $targets = [];

        if (is_string($appArg) && trim($appArg) !== '') {
            $appSlug = strtolower(trim($appArg));
            $tool = ClusterTool::tryFrom($appSlug);

            if ($tool === null) {
                return $this->failed("Unknown app '{$appSlug}'.");
            }

            if ($engineOption !== '') {
                $lockKey = "{$tool->value}/{$engineOption}";
                $targets[$lockKey] = [$tool, $engineOption, $installed[$lockKey] ?? null];
            } else {
                foreach ($installed as $lockKey => $entry) {
                    if (str_starts_with($lockKey, $tool->value.'/')) {
                        [, $engineSlug] = explode('/', $lockKey, 2);
                        $targets[$lockKey] = [$tool, $engineSlug, $entry];
                    }
                }

                if ($targets === []) {
                    $engineSlug = $tool->defaultEngine() ?? 'default';
                    $lockKey = "{$tool->value}/{$engineSlug}";
                    $targets[$lockKey] = [$tool, $engineSlug, null];
                }
            }
        } else {
            foreach ($installed as $lockKey => $entry) {
                [$appSlug, $engineSlug] = str_contains($lockKey, '/')
                    ? explode('/', $lockKey, 2)
                    : [$lockKey, 'default'];

                if ($engineOption !== '' && $engineSlug !== $engineOption) {
                    continue;
                }

                $tool = ClusterTool::tryFrom($appSlug);
                if ($tool === null) {
                    continue;
                }

                $targets[$lockKey] = [$tool, $engineSlug, $entry];
            }
        }

        $results = [];

        foreach ($targets as $lockKey => [$tool, $engineSlug, $currentEntry]) {
            $previousVersion = is_array($currentEntry) ? ((string) ($currentEntry['version'] ?? '')) : null;

            if (in_array($lockKey, $modified, true) && ! $force) {
                $results[] = [
                    'app' => $tool->value,
                    'engine' => $engineSlug,
                    'previous_version' => $previousVersion,
                    'version' => $previousVersion,
                    'updated' => false,
                    'skipped' => true,
                    'reason' => 'modified and trusted (pass --force to overwrite)',
                ];

                if (! $this->flag('json')) {
                    $this->laraKubeLine("Skipped {$tool->value} ({$engineSlug}): modified and trusted (use --force to overwrite).");
                }

                continue;
            }

            try {
                $newEntry = $store->download($tool, $engineSlug === 'default' ? null : $engineSlug);
            } catch (RuntimeException $e) {
                return $this->failed($e->getMessage());
            }

            $newVersion = $newEntry['version'];
            $changed = $previousVersion !== $newVersion;
            // Same version, but the copy on disk had been changed: download rewrote the signed files.
            $restored = ! $changed && (in_array($lockKey, $modified, true) || in_array($lockKey, $refused, true));

            $results[] = [
                'app' => $tool->value,
                'engine' => $engineSlug,
                'previous_version' => $previousVersion,
                'version' => $newVersion,
                'updated' => $changed,
                'restored' => $restored,
                'skipped' => false,
            ];

            if (! $this->flag('json')) {
                if ($previousVersion === null || $previousVersion === '') {
                    $this->laraKubeLine("Downloaded {$tool->value} ({$engineSlug}) {$newVersion}.");
                } elseif ($restored) {
                    $this->laraKubeLine("Restored the signed {$tool->value} ({$engineSlug}) {$newVersion}; your local changes were replaced.");
                } elseif ($changed) {
                    $this->laraKubeLine("Updated {$tool->value} ({$engineSlug}): {$previousVersion} -> {$newVersion}.");
                } else {
                    $this->laraKubeLine("{$tool->value} ({$engineSlug}) is already up to date ({$newVersion}).");
                }
            }
        }

        $store->activate();

        if ($this->flag('json')) {
            $this->jsonOutput(['apps' => $results]);

            return self::SUCCESS;
        }

        if ($results === []) {
            $this->laraKubeLine('No installed template releases to update.');
        }

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
