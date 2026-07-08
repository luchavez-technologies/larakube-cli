<?php

namespace App\Commands\Companion;

use App\Data\GlobalConfigData;
use App\Enums\CompanionDriver;
use App\Traits\InteractsWithHosts;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesCompanions;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class CompanionAddCommand extends Command
{
    use InteractsWithHosts, InteractsWithProjectConfig, LaraKubeOutput, ManagesCompanions;

    protected $signature = 'companion:add {companion? : Companion slug (adminer, phpmyadmin, pgadmin, redisinsight, mongo-express)}';

    protected $description = 'Add a companion app to your local cluster';

    public function handle(): int
    {
        $this->renderHeader();

        if (! Process::run('kubectl get namespace larakube-system --no-headers')->successful()) {
            $this->error('  The larakube-system namespace does not exist yet.');
            $this->line('  Run <fg=yellow>larakube cluster:setup</> or <fg=yellow>larakube up</> first.');

            return 1;
        }

        $companion = $this->resolveCompanion();

        if ($companion === null) {
            return 0;
        }

        if ($this->isCompanionInstalled($companion)) {
            $this->line("  <fg=yellow>{$companion->getLabel()}</> is already installed at {$companion->getUrl()}");

            return 0;
        }

        $this->printAdminerTip($companion);

        $tld = GlobalConfigData::load()->getLocalTld();
        $host = "{$companion->value}.{$tld}";

        $this->withSpin("Installing {$companion->getLabel()}...", function () use ($companion) {
            $this->deployCompanion($companion);

            return true;
        });

        $this->ensureHostsAreSet([$host], 'larakube-companions');

        $this->laraKubeInfo("✅ {$companion->getLabel()} installed.");
        $this->line("  <fg=gray>URL:</> <fg=blue>https://{$host}</>");

        if ($hint = $companion->getConnectionHint()) {
            $this->line("  <fg=gray>Connection host:</> <fg=cyan>{$hint}</>");
            $this->line('  <fg=gray>Replace {appname} with your project name (e.g. mysql.hospital.svc.cluster.local)</>');
        }

        return 0;
    }

    private function resolveCompanion(): ?CompanionDriver
    {
        $slug = $this->argument('companion');

        if ($slug !== null) {
            $companion = CompanionDriver::tryFrom($slug);
            if ($companion === null) {
                $this->error("  Unknown companion: {$slug}");
                $this->line('  Available: '.implode(', ', array_map(fn ($c) => $c->value, CompanionDriver::installable())));

                return null;
            }

            return $companion;
        }

        $installable = array_filter(CompanionDriver::installable(), fn ($c) => ! $this->isCompanionInstalled($c));

        if (empty($installable)) {
            $this->line('  All companions are already installed.');

            return null;
        }

        // Project-aware ordering: if we're inside a LaraKube project, surface the
        // companions that match its backing services first (e.g. Postgres → pgAdmin,
        // Adminer) and pre-select the top pick. Falls back to the flat list outside
        // a project, or when nothing matches.
        $recommended = [];
        if ($this->isLaraKubeProject(false) && $config = $this->getProjectConfig(getcwd())) {
            $recommended = array_filter(
                CompanionDriver::recommendedFor($config),
                fn ($c) => in_array($c, $installable, true),
            );
        }

        $ordered = array_merge(
            $recommended,
            array_filter($installable, fn ($c) => ! in_array($c, $recommended, true)),
        );

        $choices = [];
        foreach ($ordered as $c) {
            $label = $c->getLabel().' — '.$c->getDescription();
            if (in_array($c, $recommended, true)) {
                $label .= ' (recommended)';
            }
            $choices[$c->value] = $label;
        }

        $selected = select(
            'Which companion would you like to add?',
            $choices,
            default: $recommended ? reset($recommended)->value : null,
        );

        return CompanionDriver::from($selected);
    }

    private function printAdminerTip(CompanionDriver $companion): void
    {
        if (! in_array($companion, [CompanionDriver::PHPMYADMIN, CompanionDriver::PGADMIN], true)) {
            return;
        }
        if ($this->isCompanionInstalled(CompanionDriver::ADMINER)) {
            return;
        }

        $this->line('');
        $this->line('  <fg=gray>ℹ  Tip: Adminer covers MySQL, MariaDB, and PostgreSQL in a single lightweight UI.</>');
        $this->line('     Run <fg=yellow>larakube companion:add adminer</> for a lighter alternative.');
        $this->line('');
    }
}
