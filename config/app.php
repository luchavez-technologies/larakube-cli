<?php

use App\Providers\AppServiceProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application. This value is used when the
    | framework needs to place the application's name in a notification or
    | any other location as required by the application or its packages.
    |
    */

    'name' => 'LaraKube',

    /*
    |--------------------------------------------------------------------------
    | Application Version
    |--------------------------------------------------------------------------
    |
    | This value determines the "version" your application is currently running
    | in. You may want to follow the "Semantic Versioning" - Given a version
    | number MAJOR.MINOR.PATCH when an update happens: https://semver.org.
    |
    */

    'version' => app('git.version'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. This can be overridden using
    | the global command line "--env" option when calling commands.
    |
    */

    // env('APP_ENV', ...), not a bare literal: phpunit.xml.dist sets
    // APP_ENV=testing so Illuminate\Foundation\Application::runningUnitTests()
    // (bound('env') && $this['env'] === 'testing') actually returns true.
    // Without this being env-var-driven, runningUnitTests() was ALWAYS
    // false regardless of the test env var, and Illuminate\Console\Command's
    // configurePrompts() — which runs on every real command execution, even
    // inside tests — falls back to pure live TTY detection for
    // Prompt::interactive(), silently overriding anything set in
    // Tests\TestCase::setUp() on every command run. Confirmed live
    // (2026-08-19): this is what made unfaked prompts genuinely block on
    // real keyboard input under a real terminal, while looking fine under
    // a non-TTY runner.
    'env' => env('APP_ENV', 'development'),

    /*
    |--------------------------------------------------------------------------
    | Autoloaded Service Providers
    |--------------------------------------------------------------------------
    |
    | The service providers listed here will be automatically loaded on the
    | request to your application. Feel free to add your own services to
    | this array to grant expanded functionality to your applications.
    |
    */

    'providers' => [
        AppServiceProvider::class,
    ],
];
