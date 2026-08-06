<?php

use App\Enums\AppFramework;

// ── AppFramework Enum Tests ──────────────────────────────────────────────────

test('AppFramework has the expected cases', function () {
    expect(AppFramework::cases())->toHaveCount(15);
    expect(AppFramework::LARAVEL->value)->toBe('laravel');
    expect(AppFramework::STATAMIC->value)->toBe('statamic');
    expect(AppFramework::WORDPRESS->value)->toBe('wordpress');
    expect(AppFramework::NEXTJS->value)->toBe('nextjs');
});

test('AppFramework::getLabel returns human-readable names', function () {
    expect(AppFramework::LARAVEL->getLabel())->toBe('Laravel');
    expect(AppFramework::STATAMIC->getLabel())->toBe('Statamic');
    expect(AppFramework::WORDPRESS->getLabel())->toBe('WordPress (Bedrock)');
    expect(AppFramework::NEXTJS->getLabel())->toBe('Next.js');
});

test('AppFramework::healthProbePath returns correct paths', function () {
    expect(AppFramework::LARAVEL->healthProbePath())->toBe('/up');
    expect(AppFramework::STATAMIC->healthProbePath())->toBe('/up');
    expect(AppFramework::WORDPRESS->healthProbePath())->toBe('/wp-includes/version.php');
    expect(AppFramework::NEXTJS->healthProbePath())->toBe('/api/health');
});

test('AppFramework::detect returns STATAMIC when statamic/cms in composer.json', function () {
    $dir = sys_get_temp_dir().'/larakube-test-statamic-'.uniqid();
    mkdir($dir, 0o755, true);
    touch("$dir/artisan");
    file_put_contents("$dir/composer.json", json_encode([
        'require' => ['statamic/cms' => '^5.0', 'laravel/framework' => '^12.0'],
    ]));

    expect(AppFramework::detect($dir))->toBe(AppFramework::STATAMIC);

    unlink("$dir/artisan");
    unlink("$dir/composer.json");
    rmdir($dir);
});

test('AppFramework::detect returns WORDPRESS when wp-config.php present', function () {
    $dir = sys_get_temp_dir().'/larakube-test-wp-'.uniqid();
    mkdir($dir, 0o755, true);
    touch("$dir/wp-config.php");

    expect(AppFramework::detect($dir))->toBe(AppFramework::WORDPRESS);

    unlink("$dir/wp-config.php");
    rmdir($dir);
});

test('AppFramework::detect returns WORDPRESS when roots/bedrock in composer.json', function () {
    $dir = sys_get_temp_dir().'/larakube-test-bedrock-'.uniqid();
    mkdir($dir, 0o755, true);
    file_put_contents("$dir/composer.json", json_encode([
        'require' => ['roots/bedrock' => '*'],
    ]));

    expect(AppFramework::detect($dir))->toBe(AppFramework::WORDPRESS);

    unlink("$dir/composer.json");
    rmdir($dir);
});

test('AppFramework::detect returns NEXTJS when next.config.ts present', function () {
    $dir = sys_get_temp_dir().'/larakube-test-nextjs-'.uniqid();
    mkdir($dir, 0o755, true);
    touch("$dir/next.config.ts");

    expect(AppFramework::detect($dir))->toBe(AppFramework::NEXTJS);

    unlink("$dir/next.config.ts");
    rmdir($dir);
});

test('AppFramework::detect returns LARAVEL for a plain Laravel project', function () {
    $dir = sys_get_temp_dir().'/larakube-test-laravel-'.uniqid();
    mkdir($dir, 0o755, true);
    touch("$dir/artisan");
    file_put_contents("$dir/composer.json", json_encode([
        'require' => ['laravel/framework' => '^12.0'],
    ]));

    expect(AppFramework::detect($dir))->toBe(AppFramework::LARAVEL);

    unlink("$dir/artisan");
    unlink("$dir/composer.json");
    rmdir($dir);
});

test('AppFramework::detect returns null for an unknown directory', function () {
    $dir = sys_get_temp_dir().'/larakube-test-unknown-'.uniqid();
    mkdir($dir, 0o755, true);

    expect(AppFramework::detect($dir))->toBeNull();

    rmdir($dir);
});

// ── statamic:new Command Tests ───────────────────────────────────────────────

test('statamic:new command is registered and has correct signature', function () {
    $this->artisan('statamic:new --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('statamic:new');
});

test('statamic:new command has --fast option', function () {
    $kernel = app(Illuminate\Contracts\Console\Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('statamic:new');
    expect($commands['statamic:new']->getDefinition()->hasOption('fast'))->toBeTrue();
});
