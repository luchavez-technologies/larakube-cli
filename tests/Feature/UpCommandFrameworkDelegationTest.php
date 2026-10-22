<?php

test('UpCommand delegates framework-specific lifecycle steps to AppFramework', function (): void {
    $upSource = (string) file_get_contents(base_path('app/Commands/UpCommand.php'));
    $dockerSource = (string) file_get_contents(base_path('app/Traits/InteractsWithDocker.php'));
    $depsSource = (string) file_get_contents(base_path('app/Traits/EnsuresHostDependencies.php'));

    // UpCommand delegates to AppFramework methods instead of hardcoded checks
    expect($upSource)->toContain('$framework->requiresEnvFile()')
        ->and($upSource)->toContain('$framework->postEnvSetupCommand()')
        ->and($upSource)->toContain('$framework->hasMissingDependencies(')
        ->and($upSource)->toContain('$framework->usesLocalSqlite(')
        ->and($upSource)->toContain('$framework->localPersistentVolumeNames(')
        // No hardcoded Artisan key:generate in UpCommand
        ->and($upSource)->not->toContain("runStreaming('php artisan key:generate")
        // No hardcoded vendor/ or node_modules/ presence check in UpCommand
        ->and($upSource)->not->toContain("is_dir(\$projectPath.'/vendor') || ! is_dir(\$projectPath.'/node_modules')")
        // No hardcoded PV list in UpCommand
        ->and($upSource)->not->toContain('"{$appName}-laravel-storage-pv"')
        // No hardcoded "root of your Laravel project"
        ->and($upSource)->not->toContain('root of your Laravel project');

    // InteractsWithDocker delegates to AppFramework::dockerfile()
    expect($dockerSource)->toContain('$framework->dockerfile()')
        ->and($dockerSource)->not->toContain('Dockerfile.nextjs');

    // EnsuresHostDependencies guards composer behind isPhp()
    expect($depsSource)->toContain('$framework->isPhp()');
});
