<?php

use App\Commands\Astro\AstroNewCommand;
use App\Commands\Docs\DocsNewCommand;
use App\Commands\Vite\ViteNewCommand;

test('vite:new, astro:new, docs:new, and data:wire commands are registered', function () {
    $this->artisan('list --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('vite:new')
        ->expectsOutputToContain('astro:new')
        ->expectsOutputToContain('docs:new')
        ->expectsOutputToContain('data:wire');
});

test('vite:new command has template and ts options', function () {
    $command = app(ViteNewCommand::class);
    $definition = $command->getDefinition();

    expect($definition->hasOption('template'))->toBeTrue();
    expect($definition->hasOption('ts'))->toBeTrue();
});

test('astro:new command has template option', function () {
    $command = app(AstroNewCommand::class);
    $definition = $command->getDefinition();

    expect($definition->hasOption('template'))->toBeTrue();
});

test('docs:new command has template and typescript options', function () {
    $command = app(DocsNewCommand::class);
    $definition = $command->getDefinition();

    expect($definition->hasOption('template'))->toBeTrue();
    expect($definition->hasOption('typescript'))->toBeTrue();
});

test('vite:new scaffolds project and generates .larakube.json blueprint', function () {
    $tempDir = sys_get_temp_dir().'/test-vite-scaffold-'.uniqid();
    mkdir($tempDir, 0755, true);
    $oldCwd = getcwd();

    try {
        Illuminate\Support\Facades\Process::fake([
            '*create-vite*' => function ($process) use ($tempDir) {
                mkdir("{$tempDir}/my-vite-app", 0755, true);

                return Illuminate\Support\Facades\Process::result(output: 'Scaffolded');
            },
        ]);

        chdir($tempDir);

        $this->artisan('vite:new my-vite-app --template=react --ts')
            ->assertExitCode(0)
            ->expectsOutputToContain('Scaffolding complete! Created Vite (react-ts) app in my-vite-app/');

        expect(file_exists("{$tempDir}/my-vite-app/.larakube.json"))->toBeTrue();
        $config = App\Data\ConfigData::loadFromFile("{$tempDir}/my-vite-app");
        expect($config->framework->value)->toBe('vite');
    } finally {
        chdir($oldCwd);
        exec('rm -rf '.escapeshellarg($tempDir));
    }
});

test('astro:new scaffolds project and generates .larakube.json blueprint', function () {
    $tempDir = sys_get_temp_dir().'/test-astro-scaffold-'.uniqid();
    mkdir($tempDir, 0755, true);
    $oldCwd = getcwd();

    try {
        Illuminate\Support\Facades\Process::fake([
            '*create-astro*' => function ($process) use ($tempDir) {
                mkdir("{$tempDir}/my-astro-app", 0755, true);

                return Illuminate\Support\Facades\Process::result(output: 'Scaffolded');
            },
        ]);

        chdir($tempDir);

        $this->artisan('astro:new my-astro-app --template=minimal')
            ->assertExitCode(0)
            ->expectsOutputToContain('Scaffolding complete! Created Astro (minimal) site in my-astro-app/');

        expect(file_exists("{$tempDir}/my-astro-app/.larakube.json"))->toBeTrue();
        $config = App\Data\ConfigData::loadFromFile("{$tempDir}/my-astro-app");
        expect($config->framework->value)->toBe('astro');
    } finally {
        chdir($oldCwd);
        exec('rm -rf '.escapeshellarg($tempDir));
    }
});

test('docs:new scaffolds project and generates .larakube.json blueprint', function () {
    $tempDir = sys_get_temp_dir().'/test-docs-scaffold-'.uniqid();
    mkdir($tempDir, 0755, true);
    $oldCwd = getcwd();

    try {
        Illuminate\Support\Facades\Process::fake([
            '*create-docusaurus*' => function ($process) use ($tempDir) {
                mkdir("{$tempDir}/my-docs-app", 0755, true);

                return Illuminate\Support\Facades\Process::result(output: 'Scaffolded');
            },
        ]);

        chdir($tempDir);

        $this->artisan('docs:new my-docs-app --template=classic')
            ->assertExitCode(0)
            ->expectsOutputToContain('Scaffolding complete! Created Docusaurus documentation site in my-docs-app/');

        expect(file_exists("{$tempDir}/my-docs-app/.larakube.json"))->toBeTrue();
        $config = App\Data\ConfigData::loadFromFile("{$tempDir}/my-docs-app");
        expect($config->framework->value)->toBe('docusaurus');
    } finally {
        chdir($oldCwd);
        exec('rm -rf '.escapeshellarg($tempDir));
    }
});

test('generateK8sManifests renders spa-s3-ingress for SPA frameworks', function () {
    $tempDir = sys_get_temp_dir().'/test-spa-ingress-'.uniqid();
    mkdir($tempDir, 0755, true);

    $config = new App\Data\ConfigData(
        id: 'spa-test',
        name: 'spa-test',
        path: $tempDir,
        framework: App\Enums\AppFramework::VITE,
        frontend: null,
    );

    $traitHolder = new class
    {
        use App\Traits\GeneratesProjectInfrastructure;

        public function testGenerate(App\Data\ConfigData $config): void
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

    exec('rm -rf '.escapeshellarg($tempDir));
});
