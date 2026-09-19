<?php

use Illuminate\Support\Facades\Process;

test('paste:remove deletes Yopass resources', function (): void {
    Process::fake([...registeredToolRemoveFakes('paste:remove', 'paste-example-com', 'paste.example.com'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('paste:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Yopass resources...');

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete deployment/paste-yopass-paste-example-com service/paste-yopass-paste-example-com ingress/paste-yopass-paste-example-com secret/paste-yopass-secrets-paste-example-com'));
});

test('paste:remove --domain removes only that instance, never the other one', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'paste', 'instance' => 'paste-example-com', 'host' => 'paste.example.com'],
            ['tool' => 'paste', 'instance' => 'paste-check-example-com', 'host' => 'paste.check.example.com'],
        ]))),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('paste:remove local --domain=paste.check.example.com --force')->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'deployment/paste-yopass-paste-check-example-com '));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'delete') && str_contains($process->command, 'paste-yopass-paste-example-com'));
});

test('paste:remove --purge flushes and frees only that instance\'s Commons Redis index and bucket', function (): void {
    $saved = [];
    $current = ['tenants' => [
        'paste_yopass_paste-check-example-com' => ['redis_index' => 5],
        'paste-yopass-paste-check-example-com' => ['s3_bucket' => 'paste-yopass-paste-check-example-com', 's3_service' => 'seaweedfs'],
        'paste_yopass_paste-example-com' => ['redis_index' => 2],
    ]];

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'paste', 'instance' => 'paste-example-com', 'host' => 'paste.example.com'],
            ['tool' => 'paste', 'instance' => 'paste-check-example-com', 'host' => 'paste.check.example.com'],
        ]))),
        '*get configmap plex-registry*' => function () use (&$current) {
            return Process::result(output: (string) json_encode($current));
        },
        '*create configmap plex-registry*' => function (Illuminate\Process\PendingProcess $process) use (&$saved, &$current) {
            preg_match("/registry\\.json='([^']+)'/", $process->command, $m);
            $current = json_decode((string) file_get_contents($m[1]), true);
            $saved[] = $current;

            return Process::result(output: 'configured');
        },
        '*plex-commons*' => Process::result(output: (string) json_encode(['version' => 1, 'services' => ['redis' => ['enabled' => true], 'seaweedfs' => ['enabled' => true]]])),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('paste:remove local --domain=paste.check.example.com --purge --force')->assertExitCode(0);

    // Its keys are cleared before the index is handed back.
    Process::assertRan(fn ($process) => str_contains($process->command, 'redis-cli -n 5 FLUSHDB'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'redis-cli -n 2 FLUSHDB'));

    $tenants = end($saved)['tenants'];
    expect($tenants)->not->toHaveKey('paste_yopass_paste-check-example-com')
        ->and($tenants)->not->toHaveKey('paste-yopass-paste-check-example-com')
        ->and($tenants)->toHaveKey('paste_yopass_paste-example-com');
});
