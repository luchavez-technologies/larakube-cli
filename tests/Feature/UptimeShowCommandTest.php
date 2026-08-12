<?php

use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

test('uptime:show refuses because Uptime Kuma is not yet shipped', function () {
    Process::fake([
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('uptime:show local')
        ->assertExitCode(1)
        ->expectsOutputToContain('Status Pages (Uptime Kuma) is not yet shipped');

    Process::assertNotRan(fn ($process) => true);
});

test('uptime stays hidden from tool:list until shipped', function () {
    expect(ClusterTool::UPTIME->isShipped())->toBeFalse();
});
