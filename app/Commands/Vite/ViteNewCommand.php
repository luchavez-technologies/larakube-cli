<?php

namespace App\Commands\Vite;

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

class ViteNewCommand extends Command
{
    use CheckPrerequisites, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithProjectConfig, LaraKubeOutput;

    protected $signature = 'vite:new
        {name? : The name of the Vite application}
        {--template=react : Vite template (react, vue, svelte, solid)}
        {--ts : Use TypeScript variant}
        {--fast : Skip wizard and use defaults}';

    protected $description = 'Scaffold a new Vite SPA application (React, Vue, Svelte, Solid) with LaraKube infrastructure';

    public function handle(): int
    {
        $this->renderHeader();

        $projectPath = getcwd();

        if (! $this->checkPrerequisites(false)) {
            return 1;
        }

        $inputName = $this->argument('name') ?? text(
            label: 'What is the name of your Vite application?',
            placeholder: 'my-vite-app',
            required: true,
        );

        $appName = Str::slug($inputName);
        $projectDir = "{$projectPath}/{$appName}";

        if (is_dir($projectDir)) {
            $this->laraKubeError("Directory {$appName} already exists.");

            return 1;
        }

        $template = strtolower((string) $this->option('template'));
        if (! in_array($template, ['react', 'vue', 'svelte', 'solid', 'vanilla'], true)) {
            $template = 'react';
        }

        if ($this->option('ts') && ! str_ends_with($template, '-ts')) {
            $template .= '-ts';
        }

        $this->withSpin("Scaffolding Vite ({$template}) application...", function () use ($appName, $template) {
            $cmd = "npx -y create-vite@latest {$appName} --template {$template}";
            Process::run($cmd);
        });

        if (! is_dir($projectDir)) {
            $this->laraKubeError('Vite scaffolding failed.');

            return 1;
        }

        // Initialize .larakube.json blueprint
        $config = new ConfigData(
            id: $appName,
            name: $appName,
            path: $projectDir,
            framework: AppFramework::VITE,
            frontend: FrontendStack::VITE,
        );

        $this->saveProjectConfig($projectDir, $config);

        // Generate .env.example with PocketBase / API URL placeholders
        $envExamplePath = "{$projectDir}/.env.example";
        $envContent = "VITE_POCKETBASE_URL=https://data.dev.test\n"
            ."VITE_API_URL=https://api.dev.test\n";
        file_put_contents($envExamplePath, $envContent);
        file_put_contents("{$projectDir}/.env", $envContent);

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ Scaffolding complete! Created Vite ({$template}) app in {$appName}/");
        $this->newLine();
        $this->line('  <fg=gray>Run</> <fg=yellow>cd '.$appName.' && larakube data:wire</> <fg=gray>to connect PocketBase/Directus</>');
        $this->newLine();

        return 0;
    }
}
