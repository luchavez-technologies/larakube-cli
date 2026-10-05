<?php

use App\Data\ConfigData;
use App\Enums\FrontendStack;
use App\Enums\LaravelFeature;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/** A project folder, wherever the command runs from, with the given package.json dependencies. */
function reverbProject(array $dependencies): ConfigData
{
    static $keep = [];
    $keep[] = $temporary = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporary->path();
    file_put_contents($dir.'/package.json', json_encode(['devDependencies' => $dependencies]));

    $config = new ConfigData(name: 'shop', frontend: FrontendStack::REACT, features: [LaravelFeature::REVERB]);
    $config->setPath($dir);

    return $config;
}

test('Reverb installs its Echo packages into the project folder, not a folder named after the project under the current one', function (): void {
    $commands = LaravelFeature::REVERB->getJsDependencies(reverbProject(['react' => '^19']));

    expect($commands)->toHaveCount(1)
        ->and($commands[0])->toContain('laravel-echo')
        ->toContain('pusher-js')
        ->toContain('@laravel/echo-react');
});

test('Reverb leaves a project that already has Echo alone', function (): void {
    expect(LaravelFeature::REVERB->getJsDependencies(reverbProject(['laravel-echo' => '^2'])))->toBeEmpty();
});
