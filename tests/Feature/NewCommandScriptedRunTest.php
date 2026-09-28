<?php

/**
 * `new` ends by offering `larakube up`, defaulting to yes. Under
 * --no-interaction a prompt returns its default, so the offer has to be
 * gated on a person being there — otherwise a scripted scaffold (the desktop
 * app, CI) starts a local cluster nobody asked for.
 */
test('laravel new only gets the terminal when there is one to hand over', function (bool $noInteraction, bool $tty, bool $expected): void {
    expect((new App\Commands\NewCommand)->installerCanPrompt($noInteraction, $tty))->toBe($expected);
})->with([
    'plain interactive run' => [false, true, true],
    // `docker run -it` fails with "stdin is not a terminal" here.
    'piped or desktop, no tty' => [false, false, false],
    'no-interaction' => [true, true, false],
]);

test('a headless laravel new runs without -it and with a non-interactive installer', function (): void {
    $source = (string) file_get_contents(base_path('app/Commands/NewCommand.php'));

    expect($source)->toContain('run --rm {$ttyFlag}-v')
        ->and($source)->not->toContain('run --rm -it -v $baseDir')
        ->and($source)->toContain("\$extraArgs[] = '--no-interaction';");
});

test('new only offers to start the app when a person can answer', function (): void {
    $source = (string) file_get_contents(base_path('app/Commands/NewCommand.php'));

    expect($source)->toContain("\$this->input->isInteractive() && confirm('Would you like to start your application now with `larakube up`?'");
});
