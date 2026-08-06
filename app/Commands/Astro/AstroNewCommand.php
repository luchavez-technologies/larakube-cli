<?php

namespace App\Commands\Astro;

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Traits\CheckPrerequisites;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class AstroNewCommand extends Command
{
    use CheckPrerequisites, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithProjectConfig, LaraKubeOutput;

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

        $this->withSpin("Scaffolding Astro ({$template}) application...", function () use ($appName, $template) {
            $cmd = "npx -y create-astro@latest {$appName} --template {$template} --yes --no-git";
            Process::run($cmd);
        });

        if (! is_dir($projectDir)) {
            $this->laraKubeError('Astro scaffolding failed.');

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

        $this->saveProjectConfig($projectDir, $config);

        // Generate .env.example with PocketBase / API URL placeholders
        $envExamplePath = "{$projectDir}/.env.example";
        $envContent = "PUBLIC_POCKETBASE_URL=https://data.dev.test\n"
            ."PUBLIC_API_URL=https://api.dev.test\n";
        file_put_contents($envExamplePath, $envContent);
        file_put_contents("{$projectDir}/.env", $envContent);

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ Scaffolding complete! Created Astro ({$template}) site in {$appName}/");
        $this->newLine();
        $this->line('  <fg=gray>Run</> <fg=yellow>cd '.$appName.' && larakube data:wire</> <fg=gray>to connect PocketBase/Directus</>');
        $this->newLine();

        return 0;
    }
}
