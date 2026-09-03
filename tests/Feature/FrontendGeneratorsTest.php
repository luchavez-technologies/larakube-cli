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

test('vite:new passes a template through but owns no --ts of its own', function (): void {
    $definition = app(ViteNewCommand::class)->getDefinition();

    expect($definition->hasOption('template'))->toBeTrue()
        // create-vite has no --ts flag: the TypeScript variants are separate
        // template ids, and which ones exist is create-vite's own wizard to
        // ask about. Synthesising `-ts` here meant maintaining that mapping.
        ->and($definition->hasOption('ts'))->toBeFalse();
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

test('vite:new scaffolds a project with a complete, deployable blueprint', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();
    $oldCwd = getcwd();

    try {
        Process::fake([
            // create-vite now runs inside Node rather than on the host, so the
            // toolchain matches the dev pod's and no local Node is required.
            '*create-vite*' => function ($process) use ($tempDir) {
                mkdir("{$tempDir}/my-vite-app", 0755, true);
                file_put_contents(
                    "{$tempDir}/my-vite-app/vite.config.ts",
                    "import { defineConfig } from 'vite'\n\nexport default defineConfig({\n  plugins: [],\n})\n",
                );
                // create-vite emits one, and hardenGitIgnore() no-ops without it.
                file_put_contents("{$tempDir}/my-vite-app/.gitignore", "node_modules\ndist\n");

                return Process::result(output: 'Scaffolded');
            },
            '*docker pull*' => Process::result(output: 'pulled'),
            '*chown*' => Process::result(output: ''),
        ]);

        chdir($tempDir);

        $this->artisan('vite:new my-vite-app --template=react-ts --no-interaction')
            ->assertExitCode(0);

        $project = "{$tempDir}/my-vite-app";

        expect(file_exists("{$project}/.larakube.json"))->toBeTrue();
        $config = ConfigData::loadFromFile($project);
        expect($config->framework->value)->toBe('vite');

        // The whole point of the rewrite: infrastructure is actually generated.
        // Previously vite:new never called orchestrateProjectScaffolding(), so
        // .larakube.json was the only artifact and nothing was deployable.
        expect("{$project}/.infrastructure/k8s/overlays/local")->toBeDirectory()
            ->and(file_exists("{$project}/.infrastructure/k8s/overlays/local/kustomization.yaml"))->toBeTrue()
            ->and(file_exists("{$project}/.infrastructure/k8s/overlays/local/dev-server.yaml"))->toBeTrue();

        // A static site has no PHP image; rendering docker.php would fatal on a
        // null ServerVariation, so it must not be written at all. It gets its
        // own image instead: bundle built in Node, served by Caddy.
        expect(file_exists("{$project}/Dockerfile.php"))->toBeFalse()
            ->and(file_exists("{$project}/Dockerfile.static"))->toBeTrue()
            ->and(file_exists("{$project}/Caddyfile"))->toBeTrue();

        // The host never builds — its node_modules lives in the dev pod's PVC,
        // so `npm run build` there finds no vite at all.
        expect(file_get_contents("{$project}/Dockerfile.static"))
            ->toContain('FROM node:24-alpine AS assets')
            ->toContain('FROM caddy:2.11.2-alpine')
            // VITE_* values compile into the bundle, so .env must be readable
            // during the build and must never become an image layer.
            ->toContain('--mount=type=secret,id=dotenv');

        expect(file_get_contents("{$project}/Caddyfile"))
            // The deep-link failure a dev server structurally cannot show.
            ->toContain('try_files {path} /index.html')
            ->toContain('max-age=31536000, immutable')
            ->toContain('max-age=0, must-revalidate');

        // The dev-server config must declare the proxied host or Vite 6+ blocks
        // every request coming through Traefik.
        expect(file_get_contents("{$project}/vite.config.ts"))
            ->toContain('allowedHosts')
            ->toContain('usePolling: true');

        // Regression: skipping the whole Dockerfile step for static sites also
        // skipped hardenGitIgnore(), leaving .infrastructure/ — which holds the
        // local TLS PRIVATE KEY — committable.
        $gitignore = file_get_contents("{$project}/.gitignore");
        expect($gitignore)->toContain('.infrastructure/traefik/certificates')
            ->and($gitignore)->toContain('.infrastructure/k8s/overlays/local');

        // A landing page has no backend: nothing may imply PocketBase exists.
        if (file_exists("{$project}/.env")) {
            expect(file_get_contents("{$project}/.env"))
                ->not->toContain('POCKETBASE')
                ->not->toContain('dev.test');
        }
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
            ->expectsOutputToContain('Scaffolding complete! Created Astro site in my-astro-app/');

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

test('generateK8sManifests builds a complete, self-contained tree for a static SPA', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();

    $config = new ConfigData(
        id: 'spa-test',
        name: 'spa-test',
        path: $tempDir,
        framework: AppFramework::VITE,
        frontend: null,
    );
    $config->setEnvironments(['local', 'production']);

    $traitHolder = new class
    {
        use GeneratesProjectInfrastructure;

        public function testGenerate(ConfigData $config): void
        {
            $this->generateK8sManifests($config);
        }
    };

    $traitHolder->testGenerate($config);

    $k8s = "{$tempDir}/.infrastructure/k8s";

    // The regression that made `larakube up` unusable: the three negative
    // isStaticSpa() guards skipped every stub, so this file was never written
    // even though UpCommand's is_dir() check passed on the mkdir'd directory
    // and it then ran `kustomize build` against nothing.
    expect(file_exists("{$k8s}/overlays/local/kustomization.yaml"))->toBeTrue()
        ->and(file_exists("{$k8s}/overlays/local/dev-server.yaml"))->toBeTrue()
        ->and(file_exists("{$k8s}/overlays/production/kustomization.yaml"))->toBeTrue()
        ->and(file_exists("{$k8s}/overlays/production/caddy.yaml"))->toBeTrue()
        ->and(file_exists("{$k8s}/overlays/production/namespace.yaml"))->toBeTrue();

    // No shared base for static sites — local and cloud share no workload.
    expect(file_exists("{$k8s}/base/laravel.yaml"))->toBeFalse();

    expect(file_get_contents("{$k8s}/overlays/local/dev-server.yaml"))
        ->toContain('/app/node_modules')
        // The site IS the image now — nothing is fetched at runtime, so there
        // is no init container and `kubectl rollout undo` is a real rollback.
        ->and(file_get_contents("{$k8s}/overlays/production/caddy.yaml"))
        ->toContain('image: spa-test:latest')
        ->not->toContain('initContainers');

    $temporaryDirectory->delete();
});

test('astro:new and docs:new produce the same deployable tree as vite:new', function (AppFramework $framework, string $outputDir): void {
    // These had exactly the gaps vite:new had — no orchestrateProjectScaffolding
    // call at all, so .larakube.json was the only artifact and nothing was
    // deployable. They now inherit the whole static pipeline.
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();

    $config = new ConfigData(id: 'site', name: 'site', path: $tempDir, framework: $framework);
    $config->setEnvironments(['local', 'production']);
    $config->setPackageManager(App\Enums\PackageManager::NPM);

    $holder = new class
    {
        use GeneratesProjectInfrastructure;

        public function go(ConfigData $config): void
        {
            $this->orchestrateProjectScaffolding($config, installFeatures: false, buildImage: false);
        }
    };

    $holder->go($config);

    expect(file_exists("{$tempDir}/Dockerfile.static"))->toBeTrue()
        ->and(file_exists("{$tempDir}/Caddyfile"))->toBeTrue()
        ->and(file_exists("{$tempDir}/.infrastructure/k8s/overlays/local/dev-server.yaml"))->toBeTrue()
        ->and(file_exists("{$tempDir}/.infrastructure/k8s/overlays/production/caddy.yaml"))->toBeTrue()
        // A static site has no PHP image.
        ->and(file_exists("{$tempDir}/Dockerfile.php"))->toBeFalse();

    // Docusaurus writes build/, not dist/ — the difference has to reach the
    // Dockerfile, or the image would copy an empty directory and serve nothing.
    expect(file_get_contents("{$tempDir}/Dockerfile.static"))
        ->toContain("COPY --from=assets /app/{$outputDir} /srv");

    $temporaryDirectory->delete();
})->with([
    'astro' => [AppFramework::ASTRO, 'dist'],
    'docusaurus' => [AppFramework::DOCUSAURUS, 'build'],
]);
