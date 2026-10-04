<?php

use App\Data\ConfigData;
use App\Traits\InteractsWithArchitecturalEngine;
use App\Traits\InteractsWithProjectConfig;
use Illuminate\Support\Facades\Process;

/** The command the scaffold's JS step runs, with the given images already present on the machine. */
function jsStepCommand(array $presentImages): string
{
    Process::fake(function ($process) use ($presentImages) {
        $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        foreach ($presentImages as $image) {
            if (str_contains($cmd, 'images -q') && str_contains($cmd, $image)) {
                return Process::result(output: 'sha256:abc');
            }
        }

        return Process::result(output: '');
    });

    $harness = new class
    {
        use InteractsWithArchitecturalEngine, InteractsWithProjectConfig;

        public string $ran = '';

        public function js(ConfigData $config): string
        {
            $this->runJsInContainer($config, '/work/shop', 'npm install laravel-echo && npm run build');

            return $this->ran;
        }

        protected function runStreaming(string $command, ...$rest): void
        {
            $this->ran = $command;
        }
    };

    return $harness->js(new ConfigData(name: 'shop'));
}

function jsStepPhp(): string
{
    return (new ConfigData(name: 'shop'))->getPhpVersion()->value;
}

test('before the project image exists the JS step uses the builder image the scaffold pulled', function (): void {
    $command = jsStepCommand(['larakube-builder/php:'.jsStepPhp()]);

    expect($command)->toContain('ghcr.io/luchavez-technologies/larakube-builder/php:'.jsStepPhp())
        ->toContain('npm install laravel-echo && npm run build')
        ->not->toContain('apk add');
});

test('without a builder image the JS step adds Node to the base image first, instead of failing with npm not found', function (): void {
    $command = jsStepCommand([]);

    expect($command)->toContain('serversideup/php:'.jsStepPhp().'-cli')
        ->toContain('apk add --no-cache nodejs npm && npm install laravel-echo');
});

test('once the project image is built the JS step uses it', function (): void {
    $command = jsStepCommand(['shop:local']);

    expect($command)->toContain(' shop:local ')
        ->not->toContain('apk add')
        ->not->toContain('larakube-builder');
});
