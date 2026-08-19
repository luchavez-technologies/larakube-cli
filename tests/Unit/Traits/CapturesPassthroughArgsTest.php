<?php

use App\Traits\CapturesPassthroughArgs;

test('looksLikeTestRunner matches artisan test', function (): void {
    expect(CapturesPassthroughArgs::looksLikeTestRunner("'artisan' 'test'"))->toBeTrue()
        ->and(CapturesPassthroughArgs::looksLikeTestRunner("'php' 'artisan' 'test'"))->toBeTrue()
        ->and(CapturesPassthroughArgs::looksLikeTestRunner("'php' 'artisan' 'test' '--filter=Foo'"))->toBeTrue();
});

test('looksLikeTestRunner matches vendor/bin/pest and phpunit', function (): void {
    expect(CapturesPassthroughArgs::looksLikeTestRunner("'vendor/bin/pest'"))->toBeTrue()
        ->and(CapturesPassthroughArgs::looksLikeTestRunner("'vendor/bin/phpunit'"))->toBeTrue()
        ->and(CapturesPassthroughArgs::looksLikeTestRunner("'./vendor/bin/pest'"))->toBeTrue()
        ->and(CapturesPassthroughArgs::looksLikeTestRunner("'vendor/bin/pest' '--filter=Foo'"))->toBeTrue();
});

test('looksLikeTestRunner does not false-positive on common non-test commands', function (): void {
    expect(CapturesPassthroughArgs::looksLikeTestRunner("'composer' 'require' 'pest'"))->toBeFalse()
        ->and(CapturesPassthroughArgs::looksLikeTestRunner("'composer' 'require' 'phpunit'"))->toBeFalse()
        ->and(CapturesPassthroughArgs::looksLikeTestRunner("'ls' '-la'"))->toBeFalse()
        ->and(CapturesPassthroughArgs::looksLikeTestRunner("'tinker'"))->toBeFalse()
        ->and(CapturesPassthroughArgs::looksLikeTestRunner("'migrate'"))->toBeFalse()
        ->and(CapturesPassthroughArgs::looksLikeTestRunner("'route:list'"))->toBeFalse()
        ->and(CapturesPassthroughArgs::looksLikeTestRunner("'make:test' 'FooTest'"))->toBeFalse()
        ->and(CapturesPassthroughArgs::looksLikeTestRunner("'artisan' 'test:install'"))->toBeFalse()
        ->and(CapturesPassthroughArgs::looksLikeTestRunner("'phpunit-watcher'"))->toBeFalse();
});

test('looksLikeTestRunner survives shell-escape quoting from the trait', function (): void {
    // The trait wraps each arg in single quotes via escapeshellarg before
    // joining. The helper must strip those before matching.
    $command = "'vendor/bin/pest' '--filter=ExampleTest'";

    expect(CapturesPassthroughArgs::looksLikeTestRunner($command))->toBeTrue();
});
