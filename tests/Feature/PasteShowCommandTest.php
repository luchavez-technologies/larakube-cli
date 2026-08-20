<?php

use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

test('paste:show refuses because Yopass is not yet shipped', function (): void {
    Process::fake([
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('paste:show local')
        ->assertExitCode(1)
        ->expectsOutputToContain('Secure Paste Sharing (Yopass) is not yet shipped');

    Process::assertNotRan(fn ($process) => true);
});

test('paste stays hidden from tool:list until shipped', function (): void {
    expect(ClusterTool::PASTE->isShipped())->toBeFalse();
});
