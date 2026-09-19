<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\ServerVariation;

/**
 * Statamic needs gd + exif (statamic/cms → intervention/image, whose GD driver
 * runs checkHealth() during boot). Those lived on Blueprint::STATAMIC until
 * b7d7a10 gave Statamic its own command — and the framework is NOT part of
 * getComponents(), so the declaration was silently lost from both the built
 * image and the scaffold container.
 */
test('the framework declares its own extensions', function (): void {
    expect(AppFramework::STATAMIC->getPhpExtensions())->toBe(['gd', 'exif'])
        ->and(AppFramework::LARAVEL->getPhpExtensions())->toBeEmpty();
});

test('a Statamic config rolls those extensions up for the Dockerfile', function (): void {
    $config = new ConfigData;
    $config->framework = AppFramework::STATAMIC;

    // php.blade.php renders `install-php-extensions {{ …getAllPhpExtensions() }}`.
    expect($config->getAllPhpExtensions())->toEqualCanonicalizing(['gd', 'exif']);
});

test('a framework with no extra needs adds nothing', function (): void {
    $config = new ConfigData;
    $config->framework = AppFramework::LARAVEL;

    expect($config->getAllPhpExtensions())->toBeEmpty();
});

// Extension-before-installer ordering is exercised in StatamicNewCommandTest.

test('statamic:new sets a server variation', function (): void {
    // Without one, manifest views deref null on getServerVariation()->value and
    // orchestration fails after the project is already on disk.
    $source = (string) file_get_contents(base_path('app/Commands/Statamic/StatamicNewCommand.php'));

    expect($source)->toContain('$config->serverVariation = ServerVariation::')
        ->and(ServerVariation::FPM_NGINX)->not->toBeNull();
});

test('statamic:new offers Laravel features, because Statamic is a Laravel app', function (): void {
    // gatherConfig() offered these while Statamic was a Blueprint; b7d7a10 gave
    // it its own command that reimplemented only the driver prompts, so Horizon,
    // Queues, Scheduler and Reverb silently disappeared from the wizard.
    $source = (string) file_get_contents(base_path('app/Commands/Statamic/StatamicNewCommand.php'));

    expect($source)->toContain('LaravelFeature::getSelectOptions($config)')
        ->and($source)->toContain('$config->setFeatures(')
        // Both would process the same queue twice.
        ->and($source)->toContain('You cannot select both Horizon and Queues');
});

test('features are asked BEFORE the drivers they determine', function (): void {
    // gatherConfig() asks features at step 3 for a reason: Horizon forces Redis
    // (step 12 skips the cache prompt entirely) and AI flips the database
    // default to PostgreSQL (step 11). Asking features last would let the wizard
    // produce Horizon with Memcached — a queue worker with nothing to run on.
    $source = (string) file_get_contents(base_path('app/Commands/Statamic/StatamicNewCommand.php'));

    $features = strpos($source, 'Select Laravel features:');
    $database = strpos($source, 'Which database engine would you like to use?');
    $cache = strpos($source, 'Which cache driver would you like to use?');

    expect($features)->not->toBeFalse()
        ->and($features)->toBeLessThan($database)
        ->and($features)->toBeLessThan($cache);
});

test('Horizon forces Redis instead of offering a cache that cannot run it', function (): void {
    $source = (string) file_get_contents(base_path('app/Commands/Statamic/StatamicNewCommand.php'));

    expect($source)->toContain('$config->hasFeature(LaravelFeature::HORIZON)')
        ->and($source)->toContain('Horizon detected: Auto-selecting Redis')
        ->and($source)->toContain('$cacheDriver = CacheDriver::REDIS;');
});

test('the AI feature flips the database default to PostgreSQL', function (): void {
    // pgvector — same recommendation gatherConfig() makes.
    $source = (string) file_get_contents(base_path('app/Commands/Statamic/StatamicNewCommand.php'));

    expect($source)->toContain('$config->hasFeature(LaravelFeature::AI)')
        ->and($source)->toContain('$defaultDb = DatabaseDriver::POSTGRESQL->value;');
});

test('--fast picks a working default, but the wizard pre-ticks nothing', function (): void {
    // A pre-ticked Horizon silently provisions Redis and a queue worker the user
    // never asked for; gatherConfig() defaults to whatever the config already
    // carries, which is nothing on a fresh scaffold.
    $source = (string) file_get_contents(base_path('app/Commands/Statamic/StatamicNewCommand.php'));

    expect($source)->toContain("\$this->option('fast')")
        ->and($source)->toContain('LaravelFeature::TASK_SCHEDULING->value')
        ->and($source)->toContain('default: array_map(fn (LaravelFeature $f) => $f->value, $config->getFeatures())');
});

test('non-Laravel scaffolders correctly do NOT offer Laravel features', function (): void {
    // WordPress is Bedrock and the rest are Go/Rust/Python/.NET/Node — Horizon
    // is meaningless there, so the missing prompt is right, not a gap.
    foreach ([
        'app/Commands/Wordpress/WordpressNewCommand.php',
        'app/Commands/Gin/GinNewCommand.php',
        'app/Commands/Django/DjangoNewCommand.php',
    ] as $file) {
        expect((string) file_get_contents(base_path($file)))->not->toContain('LaravelFeature::getSelectOptions');
    }
});
