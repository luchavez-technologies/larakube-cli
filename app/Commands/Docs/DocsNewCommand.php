<?php

namespace App\Commands\Docs;

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

class DocsNewCommand extends Command
{
    use CheckPrerequisites, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithDocker, InteractsWithProjectConfig, LaraKubeOutput, ScaffoldsInNode, StreamsProcessOutput;

    /** Same Node the dev pod and the image build use. */
    protected const NODE_IMAGE = 'node:24-alpine';

    /** What a scripted run gets when nobody can answer create-docusaurus's wizard. */
    protected const SCRIPTED_TEMPLATE = 'classic';

    protected $signature = 'docs:new
        {name? : The name of the Docusaurus documentation site}
        {--template= : Pass a create-docusaurus template through (skips its wizard)}
        {--typescript : Use the TypeScript variant (skips its language prompt)}
        {--fast : Skip create-docusaurus\'s wizard and take classic + TypeScript}';

    protected $description = 'Scaffold a new Docusaurus documentation site with LaraKube infrastructure';

    public function handle(): int
    {
        $this->renderHeader();

        $projectPath = getcwd();

        if (! $this->checkPrerequisites(false)) {
            return 1;
        }

        $inputName = $this->argument('name') ?? text(
            label: 'What is the name of your Docusaurus documentation site?',
            placeholder: 'my-docs-site',
            required: true,
        );

        $appName = Str::slug($inputName);
        $projectDir = "{$projectPath}/{$appName}";

        if (is_dir($projectDir)) {
            $this->laraKubeError("Directory {$appName} already exists.");

            return 1;
        }

        $template = $this->option('template');

        // Interactive, create-docusaurus asks its own language question and any
        // others Docusaurus adds later. Scripted, that same question is fatal:
        // unanswered with no TTY it exits 0 having created nothing, so the
        // command reported a ✓ spinner immediately followed by "scaffolding
        // failed" — the exit code was genuinely 0. Confirmed live. Hence a
        // language flag is mandatory on the scripted line and absent from the
        // interactive one.
        // --fast is documented as classic + TypeScript, so it has to answer the
        // language question the same way an explicit --typescript would.
        $typescript = (bool) $this->option('typescript') || (bool) $this->option('fast');
        $language = $typescript ? '--typescript' : '--javascript';

        if (! $this->scaffoldInNode(
            $appName,
            $projectPath,
            'Docusaurus site',
            "npx --yes create-docusaurus@latest {$appName}"
                .($template !== null ? ' '.escapeshellarg((string) $template) : '')
                .($this->option('typescript') ? ' --typescript' : ''),
            "npx --yes create-docusaurus@latest {$appName} "
                .escapeshellarg((string) ($template ?? self::SCRIPTED_TEMPLATE))
                ." {$language}",
        )) {
            $this->laraKubeError('Docusaurus scaffolding failed — no project directory was created.');
            $this->line('   <fg=gray>create-docusaurus exits 0 even when it produces nothing, so the</> ');
            $this->line('   <fg=gray>directory is the real check. Re-run with -v to see its output.</>');

            return 1;
        }

        // Initialize .larakube.json blueprint
        $config = new ConfigData(
            id: $appName,
            name: $appName,
            path: $projectDir,
            framework: AppFramework::DOCUSAURUS,
            frontend: null,
        );

        $config->setIsScaffolding(true);

        $config->setName($appName);

        $config->setPath($projectDir);

        // Cloud environments are opt-in — `larakube env production` adds one.

        $config->setEnvironments(['local']);

        $config->setPackageManager(PackageManager::NPM);

        $this->saveProjectConfig($projectDir, $config);

        // No backend vars are seeded. Unlike Vite and Astro, Docusaurus has no
        // client-env prefix at all — a .env value never reaches browser code,
        // so data:wire has nothing useful to write here (see its own guard).
        $this->withSpin('Orchestrating infrastructure manifests...', function () use ($config): void {
            $this->orchestrateProjectScaffolding($config, installFeatures: false, buildImage: false);
        });

        $this->laraKubeNewLine();

        $this->laraKubeInfo("✅ Scaffolding complete! Created Docusaurus documentation site in {$appName}/");
        $this->newLine();

        return 0;
    }
}
