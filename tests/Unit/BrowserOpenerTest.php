<?php

use App\Services\BrowserOpener;
use Illuminate\Support\Facades\Process;

test('opens a URL with the platform command, shell-escaped', function (): void {
    Process::fake();

    expect(BrowserOpener::open('https://console.test/?a=1&b=2'))->toBeTrue();

    $expected = match (PHP_OS_FAMILY) {
        'Darwin' => 'open',
        'Windows' => 'start',
        default => 'xdg-open',
    };

    Process::assertRan(fn ($process) => $process->command === $expected.' '.escapeshellarg('https://console.test/?a=1&b=2'));
});

test('reports failure when the platform command fails', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1)]);

    expect(BrowserOpener::open('https://console.test'))->toBeFalse();
});
