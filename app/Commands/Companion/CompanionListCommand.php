<?php

namespace App\Commands\Companion;

use App\Data\GlobalConfigData;
use App\Enums\CompanionDriver;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesCompanions;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class CompanionListCommand extends Command
{
    use EmitsJsonOutput, InteractsWithProjectConfig, LaraKubeOutput, ManagesCompanions;

    protected $signature = 'companion:list {--json : Output this project\'s installed companion connection details as JSON}';

    protected $description = 'List available and installed companion apps';

    public function handle(): int
    {
        if ($this->option('json')) {
            return $this->handleJson();
        }

        $this->renderHeader();
        $this->laraKubeInfo('Companion Apps');
        $this->line('');

        $tld = GlobalConfigData::load()->getLocalTld();
        $rows = [];

        foreach (CompanionDriver::cases() as $companion) {
            $installed = $this->isCompanionInstalled($companion);

            $rows[] = [
                $companion->getLabel(),
                $installed ? '<fg=green>✔ Installed</>' : '<fg=gray>Available</>',
                $installed ? "https://{$companion->value}.{$tld}" : '—',
                $companion->getDescription(),
            ];
        }

        table(['Companion', 'Status', 'URL', 'Description'], $rows);

        // When run inside a LaraKube project, append the same per-project
        // connection block `larakube up` prints — so the FQDN hints
        // (e.g. mysql.<project>.svc.cluster.local) are re-viewable on demand
        // without a full `up`. Reuses showCompanionAccess() so the two never drift.
        if ($this->isLaraKubeProject(false) && ($config = $this->getProjectConfig())) {
            $appName = $config->getName() ?? basename(getcwd());
            $this->showCompanionAccess($config, $appName, 'local');
        }

        $this->line('');
        $this->line('  <fg=gray>Run</> <fg=yellow>larakube companion:add</> <fg=gray>to add a companion.</>');
        $this->line('  <fg=gray>Run</> <fg=yellow>larakube companion:remove <name></> <fg=gray>to remove one.</>');

        return 0;
    }

    /**
     * This project's installed companion connection details as JSON — the
     * same data showCompanionAccess() prints after `up`, for a caller (Desktop's
     * companion credentials panel) that can't read a rendered Prompts table.
     * Empty outside a LaraKube project rather than erroring: a caller scoped to
     * a project path that turns out not to be one yet just sees nothing to show.
     */
    private function handleJson(): int
    {
        $companions = [];

        if ($this->isLaraKubeProject(false) && ($config = $this->getProjectConfig())) {
            $appName = $config->getName() ?? basename(getcwd());
            $companions = $this->companionAccessJson($config, $appName, 'local');
        }

        $this->jsonOutput(['success' => true, 'companions' => $companions]);

        return 0;
    }
}
