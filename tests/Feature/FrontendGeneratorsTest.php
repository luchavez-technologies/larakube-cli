<?php

use App\Commands\Astro\AstroNewCommand;
use App\Commands\Docs\DocsNewCommand;
use App\Commands\Vite\ViteNewCommand;
use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Traits\GeneratesProjectInfrastructure;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

test('vite:new, astro:new, docs:new, and data:wire commands are registered', function (): void {
    $this->artisan('list --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('vite:new')
        ->expectsOutputToContain('astro:new')
        ->expectsOutputToContain('docs:new')
        ->expectsOutputToContain('data:wire');
});

test('vite:new command has template and ts options', function (): void {
    $command = app(ViteNewCommand::class);
    $definition = $command->getDefinition();

    expect($definition->hasOption('template'))->toBeTrue()
        ->and($definition->hasOption('ts'))->toBeTrue();
});

test('astro:new command has template option', function (): void {
    $command = app(AstroNewCommand::class);
    $definition = $command->getDefinition();

    expect($definition->hasOption('template'))->toBeTrue();
});

test('docs:new command has template and typescript options', function (): void {
    $command = app(DocsNewCommand::class);
    $definition = $command->getDefinition();

    expect($definition->hasOption('template'))->toBeTrue()
        ->and($definition->hasOption('typescript'))->toBeTrue();
});

test('vite:new scaffolds project and generates .larakube.json blueprint', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();
    $oldCwd = getcwd();

    try {
        Process::fake([
            '*create-vite*' => function ($process) use ($tempDir) {
                mkdir("{$tempDir}/my-vite-app", 0755, true);

                return Process::result(output: 'Scaffolded');
            },
        ]);

        chdir($tempDir);

        $this->artisan('vite:new my-vite-app --template=react --ts')
            ->assertExitCode(0)
            ->expectsOutputToContain('Scaffolding complete! Created Vite (react-ts) app in my-vite-app/');

        expect(file_exists("{$tempDir}/my-vite-app/.larakube.json"))->toBeTrue();
        $config = ConfigData::loadFromFile("{$tempDir}/my-vite-app");
        expect($config->framework->value)->toBe('vite');
    } finally {
        chdir($oldCwd);
        $temporaryDirectory->delete();
    }
});

test('astro:new scaffolds project and generates .larakube.json blueprint', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();
    $oldCwd = getcwd();

    try {
        Process::fake([
            '*create-astro*' => function ($process) use ($tempDir) {
                mkdir("{$tempDir}/my-astro-app", 0755, true);

                return Process::result(output: 'Scaffolded');
            },
        ]);

        chdir($tempDir);

        $this->artisan('astro:new my-astro-app --template=minimal')
            ->assertExitCode(0)
            ->expectsOutputToContain('Scaffolding complete! Created Astro (minimal) site in my-astro-app/');

        expect(file_exists("{$tempDir}/my-astro-app/.larakube.json"))->toBeTrue();
        $config = ConfigData::loadFromFile("{$tempDir}/my-astro-app");
        expect($config->framework->value)->toBe('astro');
    } finally {
        chdir($oldCwd);
        $temporaryDirectory->delete();
    }
});

test('docs:new scaffolds project and generates .larakube.json blueprint', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();
    $oldCwd = getcwd();

    try {
        Process::fake([
            '*create-docusaurus*' => function ($process) use ($tempDir) {
                mkdir("{$tempDir}/my-docs-app", 0755, true);

                return Process::result(output: 'Scaffolded');
            },
        ]);

        chdir($tempDir);

        $this->artisan('docs:new my-docs-app --template=classic')
            ->assertExitCode(0)
            ->expectsOutputToContain('Scaffolding complete! Created Docusaurus documentation site in my-docs-app/');

        expect(file_exists("{$tempDir}/my-docs-app/.larakube.json"))->toBeTrue();
        $config = ConfigData::loadFromFile("{$tempDir}/my-docs-app");
        expect($config->framework->value)->toBe('docusaurus');
    } finally {
        chdir($oldCwd);
        $temporaryDirectory->delete();
    }
});

test('generateK8sManifests renders spa-s3-ingress for SPA frameworks', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();

    $config = new ConfigData(
        id: 'spa-test',
        name: 'spa-test',
        path: $tempDir,
        framework: AppFramework::VITE,
        frontend: null,
    );

    $traitHolder = new class
    {
        use GeneratesProjectInfrastructure;

        public function testGenerate(ConfigData $config): void
        {
            $this->generateK8sManifests($config);
        }
    };

    $traitHolder->testGenerate($config);

    $ingressFile = "{$tempDir}/.infrastructure/k8s/spa-ingress.yaml";
    expect(file_exists($ingressFile))->toBeTrue();
    $content = file_get_contents($ingressFile);
    expect($content)->toContain('spa-test-spa-ingress')
        ->and($content)->toContain('seaweedfs-s3');

    $temporaryDirectory->delete();
});
