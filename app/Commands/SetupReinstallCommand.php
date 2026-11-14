<?php

namespace App\Commands;

use App\Enums\CliTool;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

/**
 * Replaces an installed CLI tool with a fresh copy, through whoever owns it:
 * `brew reinstall` for Homebrew, a fresh download for the LaraKube CLI's own
 * copy, `gcloud components reinstall` for the Google Cloud SDK. For a binary
 * that is corrupted or broken in a way `setup --update` cannot fix (Homebrew
 * sees nothing to upgrade; a self-updater cannot start). A copy owned by
 * apt/snap or another installer is left alone, with the reason.
 */
class SetupReinstallCommand extends Command
{
    use LaraKubeOutput;

    protected $signature = 'setup:reinstall
        {tool : The CLI tool to reinstall (kubectl, k9s, tofu, gcloud, aws, hcloud, gh, tea)}';

    protected $description = 'Reinstall a CLI tool setup manages, replacing a broken or corrupted copy';

    public function handle(): int
    {
        $slug = strtolower(trim((string) $this->argument('tool')));
        $tool = CliTool::tryFrom($slug);

        if ($tool === null) {
            $valid = implode(', ', array_map(fn (CliTool $case): string => $case->value, CliTool::cases()));
            $this->laraKubeError("Unknown tool '{$slug}'. Valid tools: {$valid}.");

            return 1;
        }

        if (! $tool->isInstallableHere()) {
            $this->laraKubeError("{$tool->label()} has no native installer on this machine.");

            return 1;
        }

        $this->laraKubeInfo("Reinstalling {$tool->label()}...");

        if (! $tool->reinstall()) {
            $detail = $tool->lastError();
            $this->laraKubeError("Failed to reinstall {$tool->label()}.".($detail !== null ? "\n{$detail}" : ''));

            return 1;
        }

        $this->line("  <fg=green>✓</> {$tool->label()} reinstalled at: ".($tool->resolveBinary() ?? $tool->binary()));

        return 0;
    }
}
