<?php

use Illuminate\Support\Facades\Process;

test('backup:prune --json outputs structured keep/delete retention plan without deleting', function (): void {
    $configJson = json_encode([
        'bucket' => 'larakube-backups',
        'endpoint' => 'https://nyc3.digitaloceanspaces.com',
        'region' => 'nyc3',
        'prefix' => 'cluster',
    ]);
    $secretJson = json_encode([
        'data' => [
            'access-key' => base64_encode('AKIAFAKE'),
            'secret-key' => base64_encode('SECRETFAKE'),
            'passphrase' => base64_encode('passphrase-123456'),
        ],
    ]);

    Process::fake([
        '*larakube-backup-config*bucket*' => Process::result(output: base64_encode('larakube-backups')),
        '*larakube-backup-config*endpoint*' => Process::result(output: base64_encode('https://nyc3.digitaloceanspaces.com')),
        '*larakube-backup-config*' => Process::result(output: base64_encode('value')),
        '*s3 ls*' => Process::result(output: implode("\n", [
            '2026-11-19 03:21:19 1024 larakube/2026-11-19-191701/manifest.json',
            '2026-11-01 03:20:40 2048 larakube/2026-11-01-191702/data.tar.gz',
        ])),
        '*' => Process::result(output: ''),
    ]);

    $exit = Illuminate\Support\Facades\Artisan::call('backup:prune', [
        'environment' => 'production',
        '--context' => 'fake-ctx',
        '--json' => true,
        '--no-interaction' => true,
    ]);
    expect($exit)->toBe(0);

    $json = json_decode(trim(Illuminate\Support\Facades\Artisan::output()), true);
    expect($json)->toBeArray()
        ->and($json['success'])->toBeTrue()
        ->and($json)->toHaveKeys(['keepCount', 'deleteCount', 'freedHuman', 'items']);

    Process::assertNotRan(fn ($p): bool => str_contains((string) $p->command, 's3 rm'));
});
