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
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class DocsNewCommand extends Command
{
    use CheckPrerequisites, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithDocker, InteractsWithProjectConfig, LaraKubeOutput, StreamsProcessOutput;

    /** Same Node the dev pod and the image build use. */
    protected const NODE_IMAGE = 'node:24-alpine';

    protected $signature = 'docs:new
        {name? : The name of the Docusaurus documentation site}
        {--template= : Docusaurus template (classic, facebook) — skips the prompt}
        {--typescript : Use TypeScript variant}
        {--fast : Skip the wizard and use defaults (classic + TypeScript)}';

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

        [$template, $typescript] = $this->gatherStack();

        // The language flag is REQUIRED, not cosmetic: without it
        // create-docusaurus asks "Which language do you want to use?", and with
        // no TTY it exits 0 having created nothing. The command then reported a
        // ✓ spinner immediately followed by "scaffolding failed", because the
        // exit code was genuinely 0 — confirmed live.
        $langFlag = $typescript ? '--typescript' : '--javascript';

        if (! $this->runScaffolderInNode(
            $appName,
            $projectPath,
            "Docusaurus ({$template}) site",
            "npx --yes create-docusaurus@latest {$appName} {$template} {$langFlag}",
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

    /**
     * Run the upstream scaffolder in Node rather than on the host.
     *
     * Matches vite:new, and removes the host-Node dependency entirely. $create
     * MUST be fully non-interactive: there is no TTY here, and a create-* tool
     * that reaches a prompt exits 0 having produced nothing — which is why the
     * is_dir() check below is the real success test, not the exit code.
     */
    protected function runScaffolderInNode(string $appName, string $baseDir, string $label, string $create): bool
    {
        $this->laraKubeInfo('Pulling the Node builder image...');
        Process::forever()->run('docker pull '.self::NODE_IMAGE);

        $this->withSpin("Scaffolding {$label}...", fn (): bool => Process::forever()->run(
            'docker run --rm -v '.escapeshellarg($baseDir).':/app -w /app --user root '
            .self::NODE_IMAGE.' sh -c '.escapeshellarg($create),
        )->successful());

        if (! is_dir("{$baseDir}/{$appName}")) {
            return false;
        }

        // The container writes as root; hand the tree back to the host user.
        $this->runStreaming(
            'docker run --rm -v '.escapeshellarg($baseDir).':/app --user root '
            .self::NODE_IMAGE.' chown -R '.$this->hostUid().':'.$this->hostGid().' /app/'.escapeshellarg($appName),
        );

        return true;
    }

    /**
     * Ask what create-docusaurus would have asked.
     *
     * Its language question is the reason this exists at all: unanswered, with
     * no TTY, create-docusaurus exits 0 having created nothing, so the command
     * printed a ✓ spinner and "scaffolding failed" in the same breath. Asking
     * here — before the container starts — is what makes the answer available
     * to pass as a flag.
     *
     * @return array{0: string, 1: bool}
     */
    protected function gatherStack(): array
    {
        $fast = (bool) $this->option('fast');
        $explicit = $this->option('template') !== null;

        $template = $this->option('template') ?? ($fast ? 'classic' : select(
            label: 'Which Docusaurus template would you like?',
            options: [
                'classic' => 'Classic — docs, blog and pages (Recommended)',
                'facebook' => 'Facebook — the internal-style preset',
            ],
            default: 'classic',
        ));

        $template = strtolower((string) $template);

        if (! in_array($template, ['classic', 'facebook'], true)) {
            $template = 'classic';
        }

        $typescript = $this->option('typescript')
            || ($fast && ! $explicit)
            || (! $fast && ! $explicit && confirm(
                label: 'Use TypeScript?',
                default: true,
            ));

        return [$template, $typescript];
    }
}
