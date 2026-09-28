<?php

/**
 * `new` ends by offering `larakube up`, defaulting to yes. Under
 * --no-interaction a prompt returns its default, so the offer has to be
 * gated on a person being there — otherwise a scripted scaffold (the desktop
 * app, CI) starts a local cluster nobody asked for.
 */
test('new only offers to start the app when a person can answer', function (): void {
    $source = (string) file_get_contents(base_path('app/Commands/NewCommand.php'));

    expect($source)->toContain("\$this->input->isInteractive() && confirm('Would you like to start your application now with `larakube up`?'");
});
