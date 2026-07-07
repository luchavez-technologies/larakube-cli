<?php

namespace App\Commands\Companion;

use App\Enums\CompanionDriver;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesCompanions;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class CompanionRemoveCommand extends Command
{
    use LaraKubeOutput, ManagesCompanions;

    protected $signature = 'companion:remove {companion? : Companion slug to remove (omit to pick from installed)}
                            {--force : Skip confirmation}';

    protected $description = 'Remove a companion app from your local cluster';

    public function handle(): int
    {
        $this->renderHeader();

        $slug = $this->argument('companion');

        if ($slug !== null) {
            $companion = CompanionDriver::tryFrom($slug);

            if ($companion === null) {
                $this->error("  Unknown companion: {$slug}");
                $this->line('  Available: '.implode(', ', array_map(fn ($c) => $c->value, CompanionDriver::cases())));

                return 1;
            }
        } else {
            $companion = $this->selectInstalledCompanion('remove');

            if ($companion === null) {
                return 0;
            }
        }

        if (! $this->isCompanionInstalled($companion)) {
            $this->line("  <fg=gray>{$companion->getLabel()} is not installed.</>");

            return 0;
        }

        if (! $this->option('force') && ! confirm("Remove {$companion->getLabel()} from larakube-companions?", false)) {
            return 0;
        }

        $this->withSpin("Removing {$companion->getLabel()}...", function () use ($companion) {
            $this->removeCompanion($companion);

            return true;
        });

        $this->laraKubeInfo("✅ {$companion->getLabel()} removed.");

        return 0;
    }
}
