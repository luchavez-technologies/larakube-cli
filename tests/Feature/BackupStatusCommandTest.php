<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

function backupStatusFakes(array $overrides = []): array
{
    $val = fn (string $v) => Process::result(output: base64_encode($v));

    return array_merge([
        '*larakube-backup-config*bucket*' => $val('off-site-bucket'),
        '*larakube-backup-config*endpoint*' => $val('https://example.r2.cloudflarestorage.com'),
        '*larakube-backup-config*access-key*' => $val('AK-SECRET'),
        '*larakube-backup-config*secret-key*' => $val('SK-SECRET'),
        '*larakube-backup-config*passphrase*' => $val('PASSPHRASE-SECRET'),
        '*larakube-backup-config*region*' => $val('auto'),
    ], $overrides, ['*' => Process::result(output: '')]);
}

test('backup:status --json says so when no destination is configured', function (): void {
    Process::fake(['*' => Process::result(output: '')]);

    $exit = Artisan::call('backup:status local --json --no-interaction');
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exit)->toBe(0)
        ->and($payload)->toBe(['success' => true, 'configured' => false]);
});

test('backup:status --json reports the destination, schedule and latest backup, and never a secret', function (): void {
    $cronjob = json_encode([
        'spec' => ['schedule' => '0 3 * * *', 'timeZone' => 'Asia/Manila', 'suspend' => false],
        'status' => ['lastScheduleTime' => '2026-10-03T03:00:00Z', 'lastSuccessfulTime' => '2026-10-03T03:09:00Z'],
    ]);

    Process::fake(backupStatusFakes([
        '*get cronjob larakube-backup*' => Process::result(output: $cronjob),
        'command -v aws' => Process::result(output: '/usr/local/bin/aws'),
        '*s3 ls*' => Process::result(output: implode("\n", [
            '2026-10-01 03:09:00      100 larakube/2026-10-01-030000/manifest.json',
            '2026-10-01 03:08:00     5000 larakube/2026-10-01-030000/postgres.dump.enc',
            '2026-10-02 03:09:00      100 larakube/2026-10-02-030000/manifest.json',
            '2026-10-02 03:08:00     7000 larakube/2026-10-02-030000/postgres.dump.enc',
            '2026-10-02 03:08:30     9000 larakube/2026-10-02-030000/forgejo.tar.enc',
            '2026-10-02 04:00:00      500 larakube/2026-10-02-040000/postgres.dump.enc',
        ])),
    ]));

    Artisan::call('backup:status local --json --no-interaction');
    $raw = trim(Artisan::output());
    $payload = json_decode($raw, true);

    expect($payload['configured'])->toBeTrue()
        ->and($payload['destination'])->toBe(['endpoint' => 'https://example.r2.cloudflarestorage.com', 'bucket' => 'off-site-bucket', 'region' => 'auto'])
        ->and($payload['schedule'])->toMatchArray(['scheduled' => true, 'cron' => '0 3 * * *', 'timezone' => 'Asia/Manila', 'suspended' => false, 'lastSuccessfulTime' => '2026-10-03T03:09:00Z'])
        ->and($payload['backups'])->toMatchArray(['available' => true, 'count' => 2, 'incomplete' => 1])
        ->and($payload['backups']['last'])->toBe(['id' => '2026-10-02-030000', 'taken' => '2026-10-02 03:09:00', 'bytes' => 16100, 'items' => 2])
        ->and($raw)->not->toContain('AK-SECRET')
        ->and($raw)->not->toContain('SK-SECRET')
        ->and($raw)->not->toContain('PASSPHRASE-SECRET');
});

test('backup:status does not claim there are no backups when the aws CLI is missing', function (): void {
    Process::fake(backupStatusFakes([
        'command -v aws' => Process::result(output: '', exitCode: 1),
    ]));

    Artisan::call('backup:status local --json --no-interaction');
    $payload = json_decode(trim(Artisan::output()), true);

    expect($payload['backups'])->toBe(['available' => false, 'count' => 0, 'incomplete' => 0, 'last' => null])
        ->and($payload['schedule']['scheduled'])->toBeFalse();
});

test('backup:list --json lists complete backups newest first, with the incomplete ones counted', function (): void {
    Process::fake(backupStatusFakes([
        '*s3 ls*' => Process::result(output: implode("\n", [
            '2026-10-01 03:09:00      100 larakube/2026-10-01-030000/manifest.json',
            '2026-10-01 03:08:00     5000 larakube/2026-10-01-030000/postgres.dump.enc',
            '2026-10-02 03:09:00      100 larakube/2026-10-02-030000/manifest.json',
            '2026-10-02 03:08:00     7000 larakube/2026-10-02-030000/postgres.dump.enc',
            '2026-10-02 04:00:00      500 larakube/2026-10-02-040000/postgres.dump.enc',
        ])),
    ]));

    Artisan::call('backup:list local --json --no-interaction');
    $payload = json_decode(trim(Artisan::output()), true);

    expect($payload['success'])->toBeTrue()
        ->and(array_column($payload['backups'], 'id'))->toBe(['2026-10-02-030000', '2026-10-01-030000'])
        ->and($payload['backups'][0])->toMatchArray(['bytes' => 7100, 'items' => 1])
        ->and($payload['incomplete'])->toBe(1);
});
