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
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class AstroNewCommand extends Command
{
    use CheckPrerequisites, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithDocker, InteractsWithProjectConfig, LaraKubeOutput, StreamsProcessOutput;

    /** Same Node the dev pod and the image build use. */
    protected const NODE_IMAGE = 'node:24-alpine';

    protected $signature = 'astro:new
        {name? : The name of the Astro application}
        {--template=minimal : Astro template (minimal, blog, portfolio, docs)}
        {--fast : Skip wizard and use defaults}';

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

        $template = strtolower((string) $this->option('template'));
        if (! in_array($template, ['minimal', 'blog', 'portfolio', 'docs'], true)) {
            $template = 'minimal';
        }

        // --yes --no-git --skip-houston keep every prompt suppressed. With no
        // TTY a create-* tool that reaches a prompt exits 0 having produced
        // nothing, so the directory — not the exit code — is the real check.
        if (! $this->runScaffolderInNode(
            $appName,
            $projectPath,
            "Astro ({$template}) site",
            "npx --yes create-astro@latest {$appName} --template {$template} --yes --no-git --skip-houston --install",
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

        $this->laraKubeInfo("✅ Scaffolding complete! Created Astro ({$template}) site in {$appName}/");
        $this->newLine();
        $this->line('  <fg=gray>Run</> <fg=yellow>cd '.$appName.' && larakube data:wire</> <fg=gray>to connect PocketBase/Directus</>');
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
}
