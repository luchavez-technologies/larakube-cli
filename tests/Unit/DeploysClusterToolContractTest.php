<?php

use App\Traits\DeploysClusterTool;
use Symfony\Component\Finder\Finder;

/**
 * Every command that composes DeploysClusterTool, discovered rather than
 * listed — a hand-maintained list would drift exactly when it mattered.
 *
 * @return list<class-string>
 */
function deploysClusterToolCommands(): array
{
    $classes = [];

    foreach (Finder::create()->files()->in(__DIR__.'/../../app/Commands')->name('*.php') as $file) {
        $class = 'App\\Commands\\'.str_replace(
            ['/', '.php'], ['\\', ''],
            substr($file->getRealPath(), strlen(realpath(__DIR__.'/../../app/Commands')) + 1),
        );

        if (class_exists($class) && in_array(DeploysClusterTool::class, class_uses_recursive($class), true)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

test('every consumer can actually call what resolveToolContext depends on', function (): void {
    // Regression guard. resolveToolContext() calls these on $this, and a trait
    // can happily reference a method it does not define — PHP only complains at
    // call time, on the real command. That is exactly what happened: a regex
    // removed canPromptForContext() along with the method above it, phpstan
    // stayed green, and the unit test STUBBED the missing method, so the whole
    // suite passed while `larakube data:init` was fatal on the first prompt.
    $required = ['resolveToolContext', 'recordedContextFor', 'canPromptForContext', 'kubeContextChoices'];

    $commands = deploysClusterToolCommands();
    expect($commands)->not->toBeEmpty();

    foreach ($commands as $command) {
        foreach ($required as $method) {
            expect(method_exists($command, $method))
                ->toBeTrue("{$command} cannot call {$method}()");
        }
    }
});

test('canPromptForContext does not depend on a trait its consumers may not compose', function (): void {
    // It used to call cannotPrompt(), which lives in
    // RequiresFlagsWhenNonInteractive — not composed by SsoWireCommand.
    foreach (deploysClusterToolCommands() as $command) {
        if (! method_exists($command, 'cannotPrompt')) {
            expect(method_exists($command, 'canPromptForContext'))->toBeTrue();

            return;
        }
    }
});
