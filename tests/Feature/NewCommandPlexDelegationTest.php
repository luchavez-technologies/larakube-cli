<?php

use App\Commands\NewCommand;
use App\Commands\Statamic\StatamicNewCommand;

/**
 * Every scaffolder used to provision the Commons itself, BEFORE the project
 * directory existed: it set `plex` but never `managed` (what the manifest
 * generator actually reads) and never wrote .env, so the app kept talking to
 * self-hosted pods while a Commons tenant sat unused. plex:join is the one path
 * that does all three, so they delegate to it now — from one shared helper.
 */
test('the join helper lives on the shared trait, not copied per scaffolder', function (): void {
    $trait = (string) file_get_contents(base_path('app/Traits/InteractsWithPlex.php'));

    expect($trait)->toContain('protected function joinPlexCommons(')
        ->and($trait)->toContain("\$this->call('plex:join'")
        ->and($trait)->toContain("'--no-interaction' => true")
        // Commons has no home for these, so joining would only emit noise.
        ->and($trait)->toContain('DatabaseDriver::SQLITE')
        ->and($trait)->toContain('DatabaseDriver::MONGODB');
});

test('no scaffolder provisions the Commons on its own any more', function (): void {
    $files = [
        'app/Commands/NewCommand.php',
        'app/Commands/Wordpress/WordpressNewCommand.php',
        'app/Commands/Statamic/StatamicNewCommand.php',
        'app/Commands/Nextjs/NextjsNewCommand.php',
    ];

    foreach ($files as $file) {
        $source = (string) file_get_contents(base_path($file));

        expect($source)->not->toContain('$this->ensurePlexProvisionedForApp(')
            // Writing `plex` without `managed` is what split config from cluster.
            ->and($source)->not->toContain("environments['local']->plex");
    }
});

test('each scaffolder joins only after its project exists', function (): void {
    // Ordering is the fix: the installer writes its own fresh .env, so Commons
    // wiring done before scaffolding is overwritten.
    $cases = [
        'app/Commands/NewCommand.php' => '$this->runLaravelNew($inputName, $config)',
        'app/Commands/Wordpress/WordpressNewCommand.php' => '$this->runBedrockNew(',
        'app/Commands/Statamic/StatamicNewCommand.php' => '$this->runStatamicNew(',
        'app/Commands/Nextjs/NextjsNewCommand.php' => '$this->runCreateNextApp(',
    ];

    foreach ($cases as $file => $scaffoldCall) {
        $source = (string) file_get_contents(base_path($file));
        $scaffold = strpos($source, $scaffoldCall);
        $join = strpos($source, '$this->joinPlexCommons(');

        expect($scaffold)->not->toBeFalse("{$file}: scaffold call not found")
            ->and($join)->not->toBeFalse("{$file}: never joins the Commons")
            ->and($join)->toBeGreaterThan($scaffold, "{$file}: joins before the project exists");
    }
});

test('the dead credentials parameters are gone', function (): void {
    expect((new ReflectionMethod(NewCommand::class, 'runLaravelNew'))->getNumberOfParameters())->toBe(2)
        ->and((new ReflectionMethod(StatamicNewCommand::class, 'runStatamicNew'))->getNumberOfParameters())->toBe(3);
});

test('up wakes the joined Commons services instead of allocating new ones', function (): void {
    // up only wakes services a project already joined; it never allocates.
    $up = (string) file_get_contents(base_path('app/Commands/UpCommand.php'));
    $trait = (string) file_get_contents(base_path('app/Traits/InteractsWithPlex.php'));

    expect($up)->not->toContain('$this->ensurePlexProvisionedForApp(')
        ->and($up)->toContain('$this->wakeJoinedCommonsServices($config)')
        ->and($trait)->toContain('protected function wakeJoinedCommonsServices(');
});

test('a non-interactive local join is not aborted by the human-only warning', function (): void {
    // plex:join warns that local Commons data dies with `larakube down` and asks
    // "Continue anyway?" with default FALSE. Prompt::interactive(false) resolves
    // that to the default, so every programmatic local join returned 0 having
    // done nothing — which callers correctly read as success.
    $source = (string) file_get_contents(base_path('app/Commands/Plex/PlexJoinCommand.php'));

    expect($source)->toContain("! \$this->option('no-interaction') && ! confirm('Continue anyway?', false)");
});

test('WordPress, Statamic and Next.js keep their --no-plex escape hatch', function (): void {
    foreach (['app/Commands/Wordpress/WordpressNewCommand.php', 'app/Commands/Statamic/StatamicNewCommand.php', 'app/Commands/Nextjs/NextjsNewCommand.php'] as $file) {
        $source = (string) file_get_contents(base_path($file));

        expect($source)->toContain("if (! \$this->option('no-plex')) {\n            \$this->joinPlexCommons(");
    }
});

test('the allocating provisioning path no longer exists', function (): void {
    $trait = (string) file_get_contents(base_path('app/Traits/InteractsWithPlex.php'));

    expect($trait)->not->toContain('function ensurePlexProvisionedForApp(');
});
