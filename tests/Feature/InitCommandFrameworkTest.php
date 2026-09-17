<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function initFrameworkFakes(): array
{
    return [
        'command -v podman' => Process::result(exitCode: 1),
        'command -v docker' => Process::result(output: '/usr/bin/docker'),
        'which kubectl' => Process::result(output: '/usr/bin/kubectl'),
        'docker info' => Process::result(output: 'Server Version: 27.0.0'),
        '*' => Process::result(),
    ];
}

/** Run `init` inside a throwaway project directory seeded by $seed. */
function initFrameworkInProject(callable $seed, callable $run): void
{
    $directory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $project = $directory->path('site');
    is_dir($project) || mkdir($project);
    $seed($project);
    $previous = getcwd();
    // A successful init registers the project with the local Console over HTTP.
    Http::fake(['*' => Http::response([], 200)]);

    try {
        chdir($project);
        $run($project);
    } finally {
        chdir($previous);
        $directory->delete();
    }
}

function initFrameworkAstroSeed(string $project): void
{
    file_put_contents("{$project}/astro.config.mjs", 'export default {};');
    file_put_contents("{$project}/package.json", json_encode(['scripts' => ['dev' => 'astro dev', 'build' => 'astro build']]));
}

test('an existing Astro project is detected and adopted with the same blueprint astro:new writes', function (): void {
    Process::fake(initFrameworkFakes());

    initFrameworkInProject(function (string $project): void {
        initFrameworkAstroSeed($project);
        file_put_contents("{$project}/pnpm-lock.yaml", '');
    }, function (string $project): void {
        $this->artisan('init --no-interaction')->assertExitCode(0);

        $config = ConfigData::loadFromFile($project);
        $expected = ConfigData::forStaticSite(AppFramework::ASTRO, 'site', $project, App\Enums\PackageManager::PNPM);

        expect($config->framework)->toBe(AppFramework::ASTRO)
            ->and($config->getPackageManager())->toBe(App\Enums\PackageManager::PNPM)
            ->and(array_keys($config->environments))->toBe(['local'])
            ->and($config->watchPaths)->toBe($expected->watchPaths)
            ->and(file_exists("{$project}/Dockerfile.static"))->toBeTrue()
            ->and(file_exists("{$project}/.dockerignore"))->toBeTrue();
    });
});

test('the picker offers deployable frameworks with the detected one pre-selected', function (): void {
    Process::fake(initFrameworkFakes());

    initFrameworkInProject('initFrameworkAstroSeed', function (string $project): void {
        $options = collect(AppFramework::cases())
            ->filter(fn (AppFramework $f) => $f->isDeployable())
            ->mapWithKeys(fn (AppFramework $f) => [$f->value => $f->getLabel().($f === AppFramework::ASTRO ? ' (detected)' : '')])
            ->all();

        $this->artisan('init --dry-run')
            ->expectsChoice('Which framework is this project?', 'astro', $options)
            ->expectsOutputToContain('Would generate Dockerfile.static, Caddyfile, .dockerignore.')
            ->doesntExpectOutputToContain('Dockerfile.php')
            ->doesntExpectOutputToContain('Would sync the following variables')
            ->assertExitCode(0);

        expect(file_exists("{$project}/.larakube.json"))->toBeFalse();
    });
});

test('--framework overrides detection', function (): void {
    Process::fake(initFrameworkFakes());

    initFrameworkInProject('initFrameworkAstroSeed', function (string $project): void {
        $this->artisan('init --framework=docusaurus --no-interaction')->assertExitCode(0);

        expect(ConfigData::loadFromFile($project)->framework)->toBe(AppFramework::DOCUSAURUS);
    });
});

test('an undetectable project run without prompts asks for --framework', function (): void {
    Process::fake(initFrameworkFakes());

    initFrameworkInProject(fn () => null, function (string $project): void {
        $this->artisan('init --no-interaction')
            ->expectsOutputToContain('Could not detect')
            ->assertExitCode(1);

        expect(file_exists("{$project}/.larakube.json"))->toBeFalse();
    });
});

test('frameworks the LaraKube CLI cannot deploy yet are refused, detected or requested', function (): void {
    Process::fake(initFrameworkFakes());

    initFrameworkInProject(fn (string $p) => file_put_contents("{$p}/go.mod", 'module site'), function (string $project): void {
        $this->artisan('init --no-interaction')
            ->expectsOutputToContain("can't be deployed by the LaraKube CLI yet")
            ->assertExitCode(1);

        $this->artisan('init --framework=django --no-interaction')->assertExitCode(1);
        $this->artisan('init --framework=rails --no-interaction')
            ->expectsOutputToContain("Unknown --framework 'rails'")
            ->assertExitCode(1);

        expect(file_exists("{$project}/.larakube.json"))->toBeFalse();
    });
});

test('a Next.js dry run lists the project changes without touching the project', function (): void {
    Process::fake(initFrameworkFakes());

    initFrameworkInProject(function (string $project): void {
        file_put_contents("{$project}/next.config.ts", "const nextConfig = {};\nexport default nextConfig;\n");
        file_put_contents("{$project}/package.json", json_encode(['scripts' => ['dev' => 'next dev']]));
        mkdir("{$project}/src/app", 0755, true);
    }, function (string $project): void {
        $this->artisan('init --no-interaction --dry-run')
            ->expectsOutputToContain("next.config: output: 'standalone'")
            ->expectsOutputToContain('src/app/api/health/route.ts')
            ->assertExitCode(0);

        expect(file_get_contents("{$project}/next.config.ts"))->not->toContain('standalone')
            ->and(file_exists("{$project}/.larakube.json"))->toBeFalse();
    });
});

test('a static site watches its own framework paths, not Laravel\'s', function (): void {
    $config = ConfigData::forStaticSite(AppFramework::DOCUSAURUS, 'docs', '/tmp/docs', App\Enums\PackageManager::NPM);

    expect($config->watchPaths)->toContain('docs', 'docusaurus.config.ts', 'sidebars.ts')
        ->not->toContain('app', 'composer.lock');
});
