<?php

/**
 * InteractsWithPlex reaches the Commons through its OWN kubectl — plexKubectl(),
 * built from the $plexContext property — not through whatever $kubectl the
 * calling command assembled. A command that resolves a cloud context for its own
 * work but leaves $plexContext null therefore queries the Commons on the
 * CURRENT context (usually local) instead of the target cluster.
 *
 * That failure is silent and nasty: getCommonsSpec() just returns null, so
 * configureStalwartStore() and printPlexHint() no-op and the operator sees
 * nothing at all — which is exactly how mail:init stopped printing its Plex
 * Commons connection details. Worse, on a teardown path it would drop a tenant
 * from the WRONG cluster's Commons.
 *
 * So: any command that reads the Commons must also set $plexContext.
 */
test('every command that reads the Plex Commons also sets plexContext', function (): void {
    $commandFiles = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('app/Commands'), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $commandFiles[] = $file->getPathname();
        }
    }

    // Helpers whose implementation routes through plexKubectl().
    $commonsReaders = [
        'getCommonsSpec(',
        'printPlexHint(',
        'allocateDatabase(',
        'plexKubectl(',
        'getRegistry(',
        'releaseCommonsRedisIndex(',
    ];

    $offenders = [];

    foreach ($commandFiles as $path) {
        $src = (string) file_get_contents($path);

        $readsCommons = false;
        foreach ($commonsReaders as $needle) {
            if (str_contains($src, '$this->'.$needle)) {
                $readsCommons = true;
                break;
            }
        }

        if (! $readsCommons) {
            continue;
        }

        // Either the command sets it directly, or it inherits a base class that
        // does (AbstractToolRemoveCommand sets it for all 24 remove commands).
        $setsContext = str_contains($src, 'plexContext = ')
            || str_contains($src, 'extends AbstractToolRemoveCommand');

        if (! $setsContext) {
            $offenders[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($offenders)->toBe(
        [],
        'These commands read the Plex Commons but never set $plexContext, so they '
        ."inspect the current kube-context instead of the target environment:\n  "
        .implode("\n  ", $offenders),
    );
});
