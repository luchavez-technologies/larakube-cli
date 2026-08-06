<?php

namespace App\Commands\Docs;

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\FrontendStack;
use App\Traits\CheckPrerequisites;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class DocsNewCommand extends Command
{
    use CheckPrerequisites, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithProjectConfig, LaraKubeOutput;

    protected $signature = 'docs:new
        {name? : The name of the Docusaurus documentation site}
        {--template=classic : Docusaurus template (classic, facebook)}
        {--typescript : Use TypeScript variant}
        {--fast : Skip wizard and use defaults}';

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

        $template = strtolower((string) $this->option('template'));
        if (! in_array($template, ['classic', 'facebook'], true)) {
            $template = 'classic';
        }

        $tsFlag = $this->option('typescript') ? ' --typescript' : '';

        $this->withSpin("Scaffolding Docusaurus ({$template}) site...", function () use ($appName, $template, $tsFlag) {
            $cmd = "npx -y create-docusaurus@latest {$appName} {$template}{$tsFlag}";
            Process::run($cmd);
        });

        if (! is_dir($projectDir)) {
            $this->laraKubeError('Docusaurus scaffolding failed.');

            return 1;
        }

        // Initialize .larakube.json blueprint
        $config = new ConfigData(
            id: $appName,
            name: $appName,
            path: $projectDir,
            framework: AppFramework::DOCUSAURUS,
            frontend: FrontendStack::DOCUSAURUS,
        );

        $this->saveProjectConfig($projectDir, $config);

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ Scaffolding complete! Created Docusaurus documentation site in {$appName}/");
        $this->newLine();

        return 0;
    }
}
