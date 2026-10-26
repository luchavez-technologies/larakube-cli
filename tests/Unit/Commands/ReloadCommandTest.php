<?php

use App\Data\ConfigData;
use App\Enums\LaravelFeature;
use App\Enums\ServerVariation;
use Illuminate\Support\Facades\Process;

test('reload command terminates horizon and restarts queues when configured', function (): void {
    Process::fake([
        '*get pods*horizon*' => Process::result("horizon-pod-1 horizon-pod-2\n"),
        '*get pods*queue*' => Process::result("queue-pod-1\n"),
        '*exec*horizon*' => Process::result("Process: 1 DONE\n"),
        '*exec*queue*' => Process::result("Broadcasting queue restart signal.\n"),
    ]);

    $config = new ConfigData(
        name: 'shop',
        path: getcwd(),
        features: [LaravelFeature::HORIZON, LaravelFeature::QUEUES],
    );

    // Assert that LaravelFeature cases have correct reload commands
    expect(LaravelFeature::HORIZON->getReloadCommand())->toBe('php artisan horizon:terminate')
        ->and(LaravelFeature::QUEUES->getReloadCommand())->toBe('php artisan queue:restart')
        ->and(ServerVariation::FRANKENPHP->getReloadCommand())->toBe('php artisan octane:reload');
});
