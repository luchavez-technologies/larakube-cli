<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\PackageManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * `larakube static:build` exists because a static-only site (Astro/Vite/
 * Docusaurus, no backend) doesn't need a LaraKube environment of its own —
 * see cli/plans/active/static-site-deploy.md. It just builds on the host and
 * points at where to deploy the result for free.
 */
function staticBuildProject(string $path, AppFramework $framework = AppFramework::VITE): ConfigData
{
    file_put_contents("{$path}/package.json", json_encode([
        'scripts' => ['dev' => 'vite', 'build' => 'vite build'],
    ]));

    $config = new ConfigData(name: 'portfolio', path: $path, framework: $framework);
    $config->setPackageManager(PackageManager::NPM);
    $config->saveToFile($path);

    return $config;
}

function withStaticBuildProject(callable $callback, AppFramework $framework = AppFramework::VITE): mixed
{
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();
    staticBuildProject($tempDir, $framework);

    $originalDir = getcwd();
    chdir($tempDir);

    try {
        // getcwd() resolves symlinks (/private/var/... on macOS) while the
        // TemporaryDirectory's own ->path() does not — ConfigData::loadFromFile()
        // resolves the project path through getcwd(), so callback assertions
        // must compare against that same canonical form, not $tempDir.
        return $callback(getcwd());
    } finally {
        chdir($originalDir);
        $temporaryDirectory->delete();
    }
}

test('builds a static site and points at the free hosts', function (): void {
    withStaticBuildProject(function (string $tempDir): void {
        Process::fake([
            '*grep*' => Process::result(output: '', exitCode: 1), // no local-host references
            '*' => Process::result(output: 'built'), // npm install && npm run build
        ]);

        mkdir("{$tempDir}/dist");
        file_put_contents("{$tempDir}/dist/index.html", '<html></html>');

        $this->artisan('static:build')
            ->assertExitCode(0)
            ->expectsOutputToContain("Built to: {$tempDir}/dist")
            ->expectsOutputToContain('Cloudflare Pages')
            ->expectsOutputToContain('Netlify')
            ->expectsOutputToContain('Vercel')
            ->expectsOutputToContain('GitHub Pages');

        Process::assertRan(fn ($p) => str_contains($p->command, 'npm install') && str_contains($p->command, 'npm run build'));
    });
});

test('refuses on a project with a backend', function (): void {
    withStaticBuildProject(function (): void {
        Process::fake();

        $this->artisan('static:build')->assertExitCode(1);

        Process::assertNothingRan();
    }, AppFramework::LARAVEL);
});

test('fails cleanly when the build itself fails', function (): void {
    withStaticBuildProject(function (): void {
        Process::fake(['*' => Process::result(exitCode: 1)]);

        $this->artisan('static:build')->assertExitCode(1);
    });
});

test('warns, but still succeeds, when the bundle references local dev hosts', function (): void {
    withStaticBuildProject(function (string $tempDir): void {
        Process::fake([
            '*grep*' => Process::result(output: "{$tempDir}/dist/index.html\n"),
            '*' => Process::result(output: 'built'),
        ]);

        mkdir("{$tempDir}/dist");
        file_put_contents("{$tempDir}/dist/index.html", 'http://portfolio.test');

        $this->artisan('static:build')
            ->assertExitCode(0)
            ->expectsOutputToContain('local dev hosts');
    });
});

test('--json emits the output path and host links as structured data', function (): void {
    withStaticBuildProject(function (string $tempDir): void {
        Process::fake([
            '*grep*' => Process::result(output: '', exitCode: 1),
            '*' => Process::result(output: 'built'),
        ]);

        mkdir("{$tempDir}/dist");

        expect(Artisan::call('static:build', ['--json' => true]))->toBe(0);

        $result = json_decode(Arr::last(array_filter(explode("\n", trim(Artisan::output())))), true);
        expect($result['success'])->toBeTrue()
            ->and($result['outputPath'])->toBe("{$tempDir}/dist")
            ->and($result['localHostWarning'])->toBeNull()
            ->and($result['hosts'])->toHaveKeys(['cloudflare-pages', 'netlify', 'vercel', 'github-pages']);
    });
});
