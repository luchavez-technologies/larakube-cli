<?php

use App\Traits\InteractsWithPlex;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/**
 * plex:stop and plex:start pause and resume Commons services by scaling the
 * Deployment named after each service, on the cluster --context names.
 */
function plexLifecycleFakes(array $services = ['postgres' => ['enabled' => true], 'redis' => ['enabled' => true]]): array
{
    return [
        '*get configmap plex-commons*' => Process::result(output: (string) json_encode(['version' => 1, 'services' => $services])),
        '*scale deployment/*' => Process::result(output: 'scaled'),
        '*' => Process::result(output: ''),
    ];
}

function plexLifecycleJson(string $command, array $arguments): array
{
    Artisan::call($command, ['--context' => 'vps-ctx', '--json' => true, ...$arguments]);
    $lines = preg_split('/\R/', trim(Artisan::output())) ?: [];

    return json_decode((string) end($lines), true);
}

test('plex:stop --service pauses that service\'s own Deployment on the given context', function (): void {
    Process::fake(plexLifecycleFakes());

    expect(plexLifecycleJson('plex:stop', ['--service' => 'postgres']))->toBe(['success' => true, 'services' => ['postgres']]);

    Process::assertRan(fn ($process) => str_contains($process->command, "--context 'vps-ctx'")
        && str_contains($process->command, 'scale deployment/postgres --replicas=0 -n larakube-plex'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'deployment/plex-'));
});

test('plex:start without --service resumes every enabled service', function (): void {
    Process::fake(plexLifecycleFakes());

    expect(plexLifecycleJson('plex:start', [])['services'])->toBe(['postgres', 'redis']);

    Process::assertRan(fn ($process) => str_contains($process->command, 'scale deployment/redis --replicas=1'));
});

test('the connection pooler pauses with Postgres', function (): void {
    Process::fake(plexLifecycleFakes(['postgres' => ['enabled' => true, 'pooler' => ['enabled' => true]]]));

    expect(plexLifecycleJson('plex:stop', ['--service' => 'postgres'])['services'])->toBe(['postgres', 'pgbouncer']);
});

test('an unknown service is refused instead of reported as paused', function (): void {
    Process::fake(plexLifecycleFakes());

    $result = plexLifecycleJson('plex:stop', ['--service' => 'mysql']);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain("'mysql' is not a service on this Commons");
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'scale '));
});

test('a scale kubectl refuses is a failure, not a success', function (): void {
    $fakes = plexLifecycleFakes();
    $fakes['*scale deployment/*'] = Process::result(errorOutput: 'forbidden', exitCode: 1);
    Process::fake($fakes);

    expect(plexLifecycleJson('plex:stop', ['--service' => 'redis']))->toMatchArray(['success' => false, 'error' => 'Could not scale: redis.']);
});

test('starting a project resumes a paused Commons service by its real Deployment name', function (): void {
    Process::fake([
        '*get deployment/postgres*' => Process::result(output: "0\n"),
        '*' => Process::result(output: ''),
    ]);

    $plex = new class
    {
        use InteractsWithPlex;
    };

    expect($plex->ensurePlexServiceRunning('postgres', 'kubectl'))->toBeTrue();
    Process::assertRan(fn ($process) => str_contains(implode(' ', (array) $process->command), 'scale deployment/postgres --replicas=1'));
});
