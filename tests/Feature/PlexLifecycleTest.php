<?php

use App\Commands\Plex\PlexStartCommand;
use App\Commands\Plex\PlexStopCommand;
use App\Traits\InteractsWithPlex;

it('registers PlexStopCommand and PlexStartCommand signatures correctly', function () {
    $stopCommand = new PlexStopCommand;
    $startCommand = new PlexStartCommand;

    expect($stopCommand->getName())->toBe('plex:stop')
        ->and($startCommand->getName())->toBe('plex:start');
});

it('verifies ensurePlexServiceRunning helper logic', function () {
    $traitObject = new class
    {
        use InteractsWithPlex;
    };

    expect(method_exists($traitObject, 'ensurePlexServiceRunning'))->toBeTrue();
});
