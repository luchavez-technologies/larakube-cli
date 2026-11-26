<?php

use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

test('kuma:show warns when Uptime Kuma is not installed', function (): void {
    Process::fake([
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('kuma:show local')
        ->assertExitCode(1)
        ->expectsOutputToContain('Uptime Kuma is not installed');
});

test('ClusterTool::options() advertises kuma and umami', function (): void {
    expect(ClusterTool::options())->toHaveKeys(['kuma', 'umami'])
        ->not->toHaveKey('plausible');
});
