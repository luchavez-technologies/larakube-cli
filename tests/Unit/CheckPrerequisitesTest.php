<?php

use App\Traits\CheckPrerequisites;
use Illuminate\Support\Facades\Process;

function prerequisitesChecker(): object
{
    return new class
    {
        use CheckPrerequisites;

        public function check(bool $requireK9s = false): bool
        {
            return $this->checkPrerequisites($requireK9s);
        }

        // Prompts helpers (error/info/warning) write directly to stdout via
        // Termwind, independent of Artisan's output — silence isn't needed for
        // the assertions here, but laraKubeError() is called on the Docker-not-
        // running path and isn't part of this trait.
        public function laraKubeError($text = null) {}
    };
}

test('checkPrerequisites passes when docker, kubectl, and the docker engine are all available', function (): void {
    Process::fake([
        'which docker' => Process::result(exitCode: 0),
        'which kubectl' => Process::result(exitCode: 0),
        'docker info' => Process::result(exitCode: 0),
    ]);

    expect(prerequisitesChecker()->check())->toBeTrue();
});

test('checkPrerequisites fails when docker is missing', function (): void {
    Process::fake([
        'which docker' => Process::result(exitCode: 1),
        'which kubectl' => Process::result(exitCode: 0),
    ]);

    expect(prerequisitesChecker()->check())->toBeFalse();
});

test('checkPrerequisites fails when kubectl is missing', function (): void {
    Process::fake([
        'which docker' => Process::result(exitCode: 0),
        'which kubectl' => Process::result(exitCode: 1),
    ]);

    expect(prerequisitesChecker()->check())->toBeFalse();
});

test('checkPrerequisites fails when the Docker engine is not running', function (): void {
    Process::fake([
        'which docker' => Process::result(exitCode: 0),
        'which kubectl' => Process::result(exitCode: 0),
        'docker info' => Process::result(exitCode: 1),
    ]);

    expect(prerequisitesChecker()->check())->toBeFalse();
});

test('checkPrerequisites does not require k9s unless requested', function (): void {
    Process::fake([
        'which docker' => Process::result(exitCode: 0),
        'which kubectl' => Process::result(exitCode: 0),
        'which k9s' => Process::result(exitCode: 1),
        'docker info' => Process::result(exitCode: 0),
    ]);

    expect(prerequisitesChecker()->check(requireK9s: true))->toBeTrue();
    Process::assertRan('which k9s');
});
