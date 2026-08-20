<?php

use Illuminate\Support\Facades\Process;

test('paste:remove refuses because Yopass is not yet shipped', function (): void {
    Process::fake([
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('paste:remove local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('Secure Paste Sharing (Yopass) is not yet shipped');

    Process::assertNotRan(fn ($process) => true);
});
