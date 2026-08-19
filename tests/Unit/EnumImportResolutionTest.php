<?php

/**
 * Guards against a class of runtime crash that other tests can't catch: an
 * `App\Enums\*` enum referenced via `Enum::` in a Trait/Command that forgot to
 * import it. PHP then resolves the name against the file's OWN namespace
 * (e.g. `App\Traits\IngressController`) and fatals — but only on the
 * interactive code path that hits it, which the suite never drives.
 *
 * This shipped three times in v0.9.0 (IngressController in the init wizard;
 * OperatingSystem + PhpVersion in `larakube add`). This static scan would have
 * caught all three.
 */

use Symfony\Component\Finder\SplFileInfo;

test('every App\\Enums enum referenced via :: is imported or fully-qualified', function (): void {
    $appDir = base_path('app');

    $enums = array_map(fn ($p) => basename($p, '.php'), glob($appDir.'/Enums/*.php'));

    $violations = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir));
    foreach ($files as $file) {
        /** @var SplFileInfo|\SplFileInfo $file */
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();

        // Enums live in App\Enums, so they reference each other without imports.
        if (str_contains($path, DIRECTORY_SEPARATOR.'Enums'.DIRECTORY_SEPARATOR)) {
            continue;
        }

        $src = file_get_contents($path);

        foreach ($enums as $enum) {
            // A bare `Enum::` not preceded by a backslash (FQN) or word char (longer name).
            if (! preg_match('/(?<![\\\\A-Za-z0-9_])'.preg_quote($enum, '/').'::/', $src)) {
                continue;
            }

            $imported = str_contains($src, "use App\\Enums\\{$enum};");
            $fullyQualified = str_contains($src, "App\\Enums\\{$enum}::");

            if (! $imported && ! $fullyQualified) {
                $violations[] = str_replace($appDir.DIRECTORY_SEPARATOR, '', $path)." uses {$enum}:: without importing it";
            }
        }
    }

    expect($violations)->toBeEmpty();
});
