<?php

use App\Enums\CompanionDriver;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/**
 * companion:update re-applies an installed companion whose image is not the
 * one this build pins, keeping a paused one paused.
 *
 * @param  array<string, array{image: string, replicas?: int}>  $installed
 */
function companionUpdateFakes(array $installed, ?array &$applied = null): array
{
    $applied = [];
    $fakes = [];

    foreach ($installed as $slug => $deployment) {
        $fakes["*get deployment/{$slug} -n larakube-companions*"] = Process::result(output: (string) json_encode([
            'spec' => ['replicas' => $deployment['replicas'] ?? 1, 'template' => ['spec' => ['containers' => [['image' => $deployment['image']]]]]],
        ]));
    }

    return [
        ...$fakes,
        '*get configmap/phpmyadmin-hosts*' => Process::result(output: (string) json_encode(['data' => ['hosts' => 'mysql.shop.svc.cluster.local']])),
        '*apply*' => function (PendingProcess $process) use (&$applied) {
            // The namespace is ensured first; count the companion manifests only.
            if (str_contains((string) $process->input, 'kind: Deployment')) {
                $applied[] = (string) $process->input;
            }

            return Process::result(output: 'configured');
        },
        '*' => Process::result(output: ''),
    ];
}

function companionUpdateJson(array $arguments): array
{
    Artisan::call('companion:update', ['--json' => true, ...$arguments]);
    $lines = preg_split('/\R/', trim(Artisan::output())) ?: [];

    return json_decode((string) end($lines), true);
}

test('an outdated companion is re-applied with this build\'s pinned image', function (): void {
    Process::fake(companionUpdateFakes(['pgadmin' => ['image' => 'dpage/pgadmin4:latest']], $applied));

    expect(companionUpdateJson(['companion' => 'pgadmin']))->toBe(['success' => true, 'updated' => ['pgadmin'], 'current' => []])
        ->and($applied)->toHaveCount(1)
        ->and($applied[0])->toContain('image: '.CompanionDriver::PGADMIN->getImage())
        ->and($applied[0])->toContain('replicas: 1');
});

test('a companion already on the pinned image is left alone', function (): void {
    Process::fake(companionUpdateFakes(['adminer' => ['image' => CompanionDriver::ADMINER->getImage()]], $applied));

    expect(companionUpdateJson(['companion' => 'adminer']))->toBe(['success' => true, 'updated' => [], 'current' => ['adminer']])
        ->and($applied)->toBe([]);
});

test('a paused companion is updated but stays paused', function (): void {
    Process::fake(companionUpdateFakes(['redisinsight' => ['image' => 'redis/redisinsight:latest', 'replicas' => 0]], $applied));

    companionUpdateJson(['companion' => 'redisinsight']);

    expect($applied[0])->toContain('replicas: 0');
});

test('updating phpMyAdmin keeps every project database in its server list', function (): void {
    Process::fake(companionUpdateFakes(['phpmyadmin' => ['image' => 'phpmyadmin:latest']]));

    companionUpdateJson(['companion' => 'phpmyadmin']);

    Process::assertRan(fn ($process) => str_contains((string) $process->command, 'set env deployment/phpmyadmin')
        && str_contains((string) $process->command, 'PMA_HOSTS=mysql.shop.svc.cluster.local'));
});

test('--all updates the installed outdated companions and skips the rest', function (): void {
    Process::fake(companionUpdateFakes([
        'adminer' => ['image' => 'adminer:latest'],
        'pgadmin' => ['image' => CompanionDriver::PGADMIN->getImage()],
    ], $applied));

    expect(companionUpdateJson(['--all' => true]))->toBe(['success' => true, 'updated' => ['adminer'], 'current' => ['pgadmin']])
        ->and($applied)->toHaveCount(1);
});

test('naming a companion that is not installed is an error', function (): void {
    Process::fake(companionUpdateFakes([]));

    expect(companionUpdateJson(['companion' => 'mongo-express']))->toMatchArray(['success' => false, 'error' => 'Mongo Express is not installed.']);
});

test('every companion is pinned to an exact version, never latest', function (): void {
    foreach (CompanionDriver::cases() as $companion) {
        expect($companion->getImage())->toMatch('/:\d+(\.\d+)+$/');
    }
});
