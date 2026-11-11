<?php

use App\Exceptions\Handler;
use Illuminate\Contracts\Debug\ExceptionHandler;
use LaravelZero\Framework\Application;

// Scrub empty/false/parent-inherited Laravel cache & storage env overrides
// before Application boots: Env::get('APP_CONFIG_CACHE') treats "" (which
// Desktop's Windows wrapper previously passed) as a relative path, resolving
// to basePath('') (the PHAR root) and crashing LoadConfiguration/ProviderRepository
// with exit 255. Compiled binaries must also never load Desktop's caches.
$isPackagedOrFromDesktop = Phar::running() !== ''
    || getenv('NATIVEPHP_RUNNING') !== false
    || isset($_ENV['NATIVEPHP_RUNNING'])
    || isset($_SERVER['NATIVEPHP_RUNNING']);

foreach ([
    'APP_CONFIG_CACHE',
    'APP_SERVICES_CACHE',
    'APP_PACKAGES_CACHE',
    'APP_ROUTES_CACHE',
    'APP_EVENTS_CACHE',
    'VIEW_COMPILED_PATH',
    'LARAVEL_STORAGE_PATH',
] as $cacheEnvKey) {
    $raw = $_ENV[$cacheEnvKey] ?? $_SERVER[$cacheEnvKey] ?? getenv($cacheEnvKey);
    if ($raw === false) {
        continue;
    }

    $normalized = strtolower(trim((string) $raw));
    if ($isPackagedOrFromDesktop || in_array($normalized, ['', 'false', '(false)', 'null', '(null)', 'empty', '(empty)'], true)) {
        putenv($cacheEnvKey);
        unset($_ENV[$cacheEnvKey], $_SERVER[$cacheEnvKey]);
    }
}

$app = Application::configure(basePath: dirname(__DIR__))->create();

// Our own handler so exceptions that define renderForConsole() present their
// own actionable message instead of a stack trace. See App\Exceptions\Handler.
$app->singleton(ExceptionHandler::class, Handler::class);

return $app;
