<?php

/** Every scaffolder that joins the Commons also offers a way to stay self-hosted. */
test('the scaffolder registers --no-plex', function (string $command): void {
    $this->artisan("{$command} --help")
        ->expectsOutputToContain('--no-plex');
})->with(['new', 'statamic:new', 'wordpress:new', 'nextjs:new']);
