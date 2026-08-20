<?php

use Illuminate\Support\Facades\Process;

test('paste:show exits non-zero and points at init when Yopass is not installed', function (): void {
    Process::fake([
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('paste:show local')
        ->assertExitCode(1)
        ->expectsOutputToContain('is not installed')
        ->expectsOutputToContain('paste:init local');
});
