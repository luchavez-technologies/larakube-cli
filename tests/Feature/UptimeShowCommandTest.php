<?php

use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

test('kuma:show refuses because Uptime Kuma is not yet shipped', function (): void {
    Process::fake([
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('kuma:show local')
        ->assertExitCode(1)
        ->expectsOutputToContain('Uptime Kuma (Status Pages) is not yet shipped');

    Process::assertNotRan(fn ($process) => true);
});

test('uptime stays hidden from tool:list until shipped', function (): void {
    expect(ClusterTool::KUMA->isShipped())->toBeFalse();
});
