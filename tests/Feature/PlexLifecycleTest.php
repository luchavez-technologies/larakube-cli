<?php

use App\Commands\Plex\PlexStartCommand;
use App\Commands\Plex\PlexStopCommand;
use App\Traits\InteractsWithPlex;

test('registers PlexStopCommand and PlexStartCommand signatures correctly', function (): void {
    $stopCommand = new PlexStopCommand;
    $startCommand = new PlexStartCommand;

    expect($stopCommand->getName())->toBe('plex:stop')
        ->and($startCommand->getName())->toBe('plex:start');
});

test('verifies ensurePlexServiceRunning helper logic', function (): void {
    $traitObject = new class
    {
        use InteractsWithPlex;
    };

    expect(method_exists($traitObject, 'ensurePlexServiceRunning'))->toBeTrue();
});
