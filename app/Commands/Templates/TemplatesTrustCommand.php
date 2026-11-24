<?php

namespace App\Commands\Templates;

use App\Enums\ClusterTool;
use App\Exceptions\MissingFlagException;
use App\Services\TemplateStore;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

use LaravelZero\Framework\Commands\Command;

class TemplatesTrustCommand extends Command
{
    use LaraKubeOutput, RequiresFlagsWhenNonInteractive;

    protected $signature = 'templates:trust
        {app : The app slug whose modified templates should be trusted}
        {--engine= : The engine slug, for multi-engine apps}
        {--force : Trust modified files without interactive confirmation}';

    protected $description = 'Trust locally modified template files for an installed app release';

    public function handle(TemplateStore $store): int
    {
        $store->activate();

        $this->renderHeader();
        $this->surfaceActivationWarnings($store);

        $appSlug = strtolower(trim((string) $this->argument('app')));
        $tool = ClusterTool::tryFrom($appSlug);
        $engineOption = trim((string) ($this->option('engine') ?? ''));
        $engineSlug = $engineOption !== '' ? $engineOption : ($tool?->defaultEngine() ?? 'default');
        $lockKey = "{$appSlug}/{$engineSlug}";

        $installed = $store->installed();
        if (! isset($installed[$lockKey])) {
            $message = "No installed template release found for '{$lockKey}'.";
            $this->laraKubeError($message);
            $this->laraKubeLine($message);

            return self::FAILURE;
        }

        $modifiedFiles = $store->modifiedFiles($appSlug, $engineSlug);
        if ($modifiedFiles === []) {
            $message = "No modified template files found for '{$lockKey}'.";
            $this->laraKubeInfo($message);
            $this->laraKubeLine($message);

            return self::SUCCESS;
        }

        $this->laraKubeLine("Modified files in {$lockKey}:");
        foreach ($modifiedFiles as $path) {
            $this->laraKubeLine("  - {$path}");
        }

        $ownershipWarning = 'You now own these files: template updates will not overwrite them, and LaraKube cannot support customized templates.';
        warning($ownershipWarning);
        $this->laraKubeWarn($ownershipWarning);

        if (! $this->option('force')) {
            if ($this->cannotPrompt()) {
                throw new MissingFlagException('force', 'confirm trusting modified templates when running non-interactively', '--force');
            }

            if (! confirm(label: "Trust modified templates for {$lockKey}?", default: false)) {
                $this->laraKubeInfo('Aborted.');
                $this->laraKubeLine('Aborted.');

                return self::FAILURE;
            }
        }

        $store->trust($appSlug, $engineSlug);
        $store->activate();

        $this->laraKubeInfo("Trusted modified templates for {$lockKey}.");
        $this->laraKubeLine("Trusted modified templates for {$lockKey}.");

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
