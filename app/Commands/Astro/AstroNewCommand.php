<?php

namespace App\Commands\Astro;

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\PackageManager;
use App\Traits\CheckPrerequisites;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ScaffoldsInNode;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class AstroNewCommand extends Command
{
    use CheckPrerequisites, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithDocker, InteractsWithProjectConfig, LaraKubeOutput, ScaffoldsInNode, StreamsProcessOutput;

    /** Same Node the dev pod and the image build use. */
    protected const NODE_IMAGE = 'node:24-alpine';

    /** What a scripted run gets when nobody can answer create-astro's wizard. */
    protected const SCRIPTED_TEMPLATE = 'minimal';

    protected $signature = 'astro:new
        {name? : The name of the Astro application}
        {--template= : Pass a create-astro template through (skips its wizard)}
        {--fast : Skip create-astro\'s wizard and take the minimal template}';

    protected $description = 'Scaffold a new Astro application (minimal, blog, portfolio, docs) with LaraKube infrastructure';

    public function handle(): int
    {
        $this->renderHeader();

        $projectPath = getcwd();

        if (! $this->checkPrerequisites(false)) {
            return 1;
        }

        $inputName = $this->argument('name') ?? text(
            label: 'What is the name of your Astro application?',
            placeholder: 'my-astro-site',
            required: true,
        );

        $appName = Str::slug($inputName);
        $projectDir = "{$projectPath}/{$appName}";

        if (is_dir($projectDir)) {
            $this->laraKubeError("Directory {$appName} already exists.");

            return 1;
        }

        $template = $this->option('template');

        // Interactive: create-astro runs its OWN wizard (template, TypeScript,
        // dependencies, git) — the one Astro keeps current — so nothing is
        // passed that would answer for it. --skip-houston only silences the
        // mascot animation, which garbles a wrapped terminal; it suppresses no
        // question. Scripted: every prompt must be pre-answered, since a
        // create-* tool that reaches one with no terminal exits 0 having
        // produced nothing.
        if (! $this->scaffoldInNode(
            $appName,
            $projectPath,
            'Astro site',
            "npx --yes create-astro@latest {$appName} --skip-houston",
            "npx --yes create-astro@latest {$appName} --template "
                .escapeshellarg((string) ($template ?? self::SCRIPTED_TEMPLATE))
                .' --yes --no-git --skip-houston --install',
        )) {
            $this->laraKubeError('Astro scaffolding failed — no project directory was created.');

            return 1;
        }

        // Initialize .larakube.json blueprint
        $config = new ConfigData(
            id: $appName,
            name: $appName,
            path: $projectDir,
            framework: AppFramework::ASTRO,
            frontend: null,
        );

        $config->setIsScaffolding(true);

        $config->setName($appName);

        $config->setPath($projectDir);

        // Cloud environments are opt-in — `larakube env production` adds one.

        $config->setEnvironments(['local']);

        $config->setPackageManager(PackageManager::NPM);

        $this->saveProjectConfig($projectDir, $config);

        // No backend vars are seeded — `larakube data:wire` adds them on demand,
        // as PUBLIC_* so Astro's client islands can read them.

        $this->withSpin('Orchestrating infrastructure manifests...', function () use ($config): void {
            $this->orchestrateProjectScaffolding($config, installFeatures: false, buildImage: false);
        });

        $this->laraKubeNewLine();

        $this->laraKubeInfo("✅ Scaffolding complete! Created Astro site in {$appName}/");
        $this->newLine();
        $this->line('  <fg=gray>Run</> <fg=yellow>cd '.$appName.' && larakube data:wire</> <fg=gray>to connect PocketBase/Directus</>');
        $this->newLine();

        return 0;
    }
}
