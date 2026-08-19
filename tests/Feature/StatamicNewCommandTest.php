<?php

use App\Enums\AppFramework;
use Illuminate\Contracts\Console\Kernel;
use Spatie\TemporaryDirectory\TemporaryDirectory;

// ── AppFramework Enum Tests ──────────────────────────────────────────────────

test('AppFramework has the expected cases', function (): void {
    expect(AppFramework::cases())->toHaveCount(15)
        ->and(AppFramework::LARAVEL->value)->toBe('laravel')
        ->and(AppFramework::STATAMIC->value)->toBe('statamic')
        ->and(AppFramework::WORDPRESS->value)->toBe('wordpress')
        ->and(AppFramework::NEXTJS->value)->toBe('nextjs');
});

test('AppFramework::getLabel returns human-readable names', function (): void {
    expect(AppFramework::LARAVEL->getLabel())->toBe('Laravel')
        ->and(AppFramework::STATAMIC->getLabel())->toBe('Statamic')
        ->and(AppFramework::WORDPRESS->getLabel())->toBe('WordPress (Bedrock)')
        ->and(AppFramework::NEXTJS->getLabel())->toBe('Next.js');
});

test('AppFramework::healthProbePath returns correct paths', function (): void {
    expect(AppFramework::LARAVEL->healthProbePath())->toBe('/up')
        ->and(AppFramework::STATAMIC->healthProbePath())->toBe('/up')
        ->and(AppFramework::WORDPRESS->healthProbePath())->toBe('/wp-includes/version.php')
        ->and(AppFramework::NEXTJS->healthProbePath())->toBe('/api/health');
});

test('AppFramework::detect returns STATAMIC when statamic/cms in composer.json', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    touch("$dir/artisan");
    file_put_contents("$dir/composer.json", json_encode([
        'require' => ['statamic/cms' => '^5.0', 'laravel/framework' => '^12.0'],
    ]));

    expect(AppFramework::detect($dir))->toBe(AppFramework::STATAMIC);

    $temporaryDirectory->delete();
});

test('AppFramework::detect returns WORDPRESS when wp-config.php present', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    touch("$dir/wp-config.php");

    expect(AppFramework::detect($dir))->toBe(AppFramework::WORDPRESS);

    $temporaryDirectory->delete();
});

test('AppFramework::detect returns WORDPRESS when roots/bedrock in composer.json', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    file_put_contents("$dir/composer.json", json_encode([
        'require' => ['roots/bedrock' => '*'],
    ]));

    expect(AppFramework::detect($dir))->toBe(AppFramework::WORDPRESS);

    $temporaryDirectory->delete();
});

test('AppFramework::detect returns NEXTJS when next.config.ts present', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    touch("$dir/next.config.ts");

    expect(AppFramework::detect($dir))->toBe(AppFramework::NEXTJS);

    $temporaryDirectory->delete();
});

test('AppFramework::detect returns LARAVEL for a plain Laravel project', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    touch("$dir/artisan");
    file_put_contents("$dir/composer.json", json_encode([
        'require' => ['laravel/framework' => '^12.0'],
    ]));

    expect(AppFramework::detect($dir))->toBe(AppFramework::LARAVEL);

    $temporaryDirectory->delete();
});

test('AppFramework::detect returns null for an unknown directory', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();

    expect(AppFramework::detect($dir))->toBeNull();

    $temporaryDirectory->delete();
});

// ── statamic:new Command Tests ───────────────────────────────────────────────

test('statamic:new command is registered and has correct signature', function (): void {
    $this->artisan('statamic:new --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('statamic:new');
});

test('statamic:new command has --fast option', function (): void {
    $kernel = app(Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('statamic:new')
        ->and($commands['statamic:new']->getDefinition()->hasOption('fast'))->toBeTrue();
});
