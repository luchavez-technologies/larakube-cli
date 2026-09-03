<?php

use App\Enums\AppFramework;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * Write a bare project dir containing only the given marker files, so
 * detect() is exercised on exactly the signal it is supposed to key off.
 *
 * @param  array<int, string>  $files
 */
function appFrameworkStaticSiteProject(array $files): TemporaryDirectory
{
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();

    foreach ($files as $file) {
        file_put_contents($dir->path($file), '');
    }

    return $dir;
}

test('markerFiles() is exhaustive — no case throws UnhandledMatchError', function (): void {
    // The match had no arms for ASTRO/VITE/DOCUSAURUS and no default, so these
    // three threw. detect() only escaped it by never iterating them.
    foreach (AppFramework::cases() as $case) {
        expect($case->markerFiles())->toBeArray();
    }
});

test('detect() adopts a plain Vite project', function (): void {
    $dir = appFrameworkStaticSiteProject(['vite.config.ts', 'package.json']);

    expect(AppFramework::detect($dir->path()))->toBe(AppFramework::VITE);
});

test('detect() prefers Astro over Vite when both configs are present', function (): void {
    // Astro and Docusaurus are built ON Vite and can legitimately carry a
    // vite.config, so the more specific marker has to win the ordering.
    $dir = appFrameworkStaticSiteProject(['astro.config.mjs', 'vite.config.ts', 'package.json']);

    expect(AppFramework::detect($dir->path()))->toBe(AppFramework::ASTRO);
});

test('detect() prefers Docusaurus over Vite when both configs are present', function (): void {
    $dir = appFrameworkStaticSiteProject(['docusaurus.config.ts', 'vite.config.js', 'package.json']);

    expect(AppFramework::detect($dir->path()))->toBe(AppFramework::DOCUSAURUS);
});

test('detect() still prefers a server framework over a stray vite.config', function (): void {
    // A Next.js app carrying a vite.config (e.g. for a test runner) must not
    // be misread as a static SPA and silently lose its server manifests.
    $dir = appFrameworkStaticSiteProject(['next.config.ts', 'vite.config.ts', 'package.json']);

    expect(AppFramework::detect($dir->path()))->toBe(AppFramework::NEXTJS);
});

test('static frameworks report their real output directory', function (): void {
    expect(AppFramework::VITE->staticOutputDir())->toBe('dist')
        ->and(AppFramework::ASTRO->staticOutputDir())->toBe('dist')
        // Docusaurus is the odd one out.
        ->and(AppFramework::DOCUSAURUS->staticOutputDir())->toBe('build');
});

test('static frameworks report their dev-server port', function (): void {
    expect(AppFramework::VITE->devServerPort())->toBe(5173)
        ->and(AppFramework::ASTRO->devServerPort())->toBe(5173)
        ->and(AppFramework::DOCUSAURUS->devServerPort())->toBe(3000);
});

test('server-rendered frameworks return null rather than a guessed static answer', function (): void {
    // Null so a caller that wrongly assumes "static" fails loudly instead of
    // building into a directory that was never produced.
    expect(AppFramework::LARAVEL->staticOutputDir())->toBeNull()
        ->and(AppFramework::LARAVEL->devServerPort())->toBeNull()
        // Empty candidate lists so the resolver fails loudly on a non-static
        // framework instead of guessing a script.
        ->and(AppFramework::LARAVEL->buildScriptCandidates())->toBeEmpty()
        ->and(AppFramework::LARAVEL->devServerScriptCandidates())->toBeEmpty()
        ->and(AppFramework::NEXTJS->staticOutputDir())->toBeNull();
});

test('dev-server script candidates are hints, most-preferred first', function (): void {
    // Vite and Astro emit `dev`; Docusaurus emits `start` and no `dev` at all,
    // which is the crashloop this list exists to prevent. `dev` trails as a
    // fallback for a project that adds its own alias.
    expect(AppFramework::VITE->devServerScriptCandidates())->toBe(['dev'])
        ->and(AppFramework::ASTRO->devServerScriptCandidates())->toBe(['dev'])
        ->and(AppFramework::DOCUSAURUS->devServerScriptCandidates())->toBe(['start', 'dev']);
});

test('build script candidates cover every static framework', function (): void {
    // The resolver checks these against the real package.json; every create-*
    // scaffolder emits `build`.
    expect(AppFramework::VITE->buildScriptCandidates())->toBe(['build'])
        ->and(AppFramework::ASTRO->buildScriptCandidates())->toBe(['build'])
        ->and(AppFramework::DOCUSAURUS->buildScriptCandidates())->toBe(['build']);
});

test('publicEnvPrefixes reflects what each bundle can actually read', function (): void {
    expect(AppFramework::VITE->publicEnvPrefixes())->toBe(['VITE_'])
        // Laravel and Statamic ship browser assets through Vite too.
        ->and(AppFramework::LARAVEL->publicEnvPrefixes())->toBe(['VITE_'])
        ->and(AppFramework::ASTRO->publicEnvPrefixes())->toBe(['PUBLIC_'])
        ->and(AppFramework::NEXTJS->publicEnvPrefixes())->toBe(['NEXT_PUBLIC_']);
});

test('server-rendered and prefix-less frameworks return an empty list', function (): void {
    // They read the environment directly; the caller emits the bare name.
    expect(AppFramework::DJANGO->publicEnvPrefixes())->toBeEmpty()
        ->and(AppFramework::GIN->publicEnvPrefixes())->toBeEmpty()
        // Docusaurus has no standard client-env prefix.
        ->and(AppFramework::DOCUSAURUS->publicEnvPrefixes())->toBeEmpty();
});
