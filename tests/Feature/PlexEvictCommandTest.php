<?php

use App\Exceptions\MissingFlagException;
use Illuminate\Support\Facades\Process;

/**
 * A Commons with two tenants: `gone_local` (its namespace no longer exists —
 * the orphan this command is for) and `live_local` (still deployed).
 */
function plexEvictFakes(array $overrides = []): array
{
    return array_merge([
        '*cluster-info*' => Process::result(output: 'Kubernetes control plane is running'),
        '*get configmap plex-registry*' => Process::result(
            output: (string) json_encode(['tenants' => [
                'gone_local' => [
                    'db' => 'gone_local',
                    'db_service' => 'postgres',
                    'redis_index' => 3,
                    's3_bucket' => 'gone-local',
                    's3_service' => 'seaweedfs',
                    'namespace' => 'gone-local',
                ],
                'live_local' => [
                    'db' => 'live_local',
                    'db_service' => 'postgres',
                    'redis_index' => 4,
                    'namespace' => 'live-local',
                ],
            ]]),
        ),
        // The orphan's namespace is gone; the live tenant's still has a Deployment.
        '*get deploy -n \'gone-local\'*' => Process::result(output: '', exitCode: 1),
        '*get deploy -n \'live-local\'*' => Process::result(output: 'deployment.apps/web'),
        // The Postgres catalogue query — must be matched BEFORE the generic
        // exec pattern, which Laravel would otherwise claim first.
        '*pg_database*' => Process::result(output: "gone_local\nlive_local"),
        '*exec *' => Process::result(output: 'DROP DATABASE'),
        '*create configmap plex-registry*' => Process::result(output: 'configured'),
        '*' => Process::result(output: ''),
    ], $overrides);
}

test('plex:evict refuses when the target context is unreachable', function (): void {
    Process::fake([
        '*cluster-info*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('plex:evict local --tenant=gone_local --context=nope --force')
        ->assertExitCode(1)
        ->expectsOutputToContain("context 'nope' is unreachable");
});

test('plex:evict reports an empty Commons instead of failing', function (): void {
    Process::fake(plexEvictFakes([
        '*get configmap plex-registry*' => Process::result(output: (string) json_encode(['tenants' => []])),
    ]));

    $this->artisan('plex:evict local --tenant=gone_local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('no registered tenants');
});

test('plex:evict rejects a tenant that is not in the registry', function (): void {
    Process::fake(plexEvictFakes());

    $this->artisan('plex:evict local --tenant=typo_local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain("'typo_local' is not a tenant of this Commons");

    Process::assertDidntRun(fn ($process) => str_contains((string) $process->command, 'DROP'));
});

test('plex:evict refuses a tenant whose namespace still has workloads', function (): void {
    Process::fake(plexEvictFakes());

    $this->artisan('plex:evict local --tenant=live_local --no-backup')
        ->assertExitCode(1)
        ->expectsOutputToContain("still has workloads running in 'live-local'")
        ->expectsOutputToContain('plex:leave');

    // Nothing destructive, and the registry is left exactly as it was.
    Process::assertDidntRun(fn ($process) => str_contains((string) $process->command, 'FLUSHDB'));
    Process::assertDidntRun(fn ($process) => str_contains((string) $process->command, 'create configmap plex-registry'));
});

test('plex:evict --force overrides the still-deployed guard', function (): void {
    Process::fake(plexEvictFakes());

    $this->artisan('plex:evict local --tenant=live_local --force --no-backup')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removed');

    Process::assertRan(fn ($process) => str_contains((string) $process->command, 'FLUSHDB'));
});

test('plex:evict drops the database, flushes redis, deletes the bucket and frees the slot', function (): void {
    Process::fake(plexEvictFakes());

    $this->artisan('plex:evict local --tenant=gone_local --force --no-backup')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removed')
        // Two tenants held an index; evicting one leaves 15 of 16 free.
        ->expectsOutputToContain('Redis slots free: 15/16');

    Process::assertRan(fn ($process) => str_contains((string) $process->command, 'redis-cli -n 3 FLUSHDB'));
    Process::assertRan(fn ($process) => str_contains((string) $process->command, 'deploy/seaweedfs'));
    Process::assertRan(fn ($process) => str_contains((string) $process->command, 'create configmap plex-registry'));
});

test('plex:evict aborts before destroying anything when the backup fails', function (): void {
    // The distinction that makes the drift skip above safe: the database IS in
    // the catalogue, so a failed dump must keep blocking — otherwise
    // --no-backup stops meaning anything. (The dump redirects to a file the
    // fake never writes, which is how it fails here.)
    Process::fake(plexEvictFakes());

    $this->artisan('plex:evict local --tenant=gone_local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('Backup failed');

    Process::assertDidntRun(fn ($process) => str_contains((string) $process->command, 'FLUSHDB'));
    Process::assertDidntRun(fn ($process) => str_contains((string) $process->command, 'create configmap plex-registry'));
});

test('plex:evict without --tenant fails loudly rather than guessing', function (): void {
    Process::fake(plexEvictFakes());

    $this->artisan('plex:evict local --force');
})->throws(MissingFlagException::class, 'Missing required --tenant');

test('plex:evict asks for confirmation when --force is absent', function (): void {
    Process::fake(plexEvictFakes());

    $this->artisan('plex:evict local --tenant=gone_local --no-backup')
        ->expectsQuestion('Type confirm to proceed', 'nope')
        ->assertExitCode(0)
        ->expectsOutputToContain('Aborted');

    Process::assertDidntRun(fn ($process) => str_contains((string) $process->command, 'FLUSHDB'));
});

test('plex:evict says so plainly when the registry outlived its database', function (): void {
    // A registry entry can outlive its database; say so rather than imply
    // the eviction destroyed it.
    Process::fake(plexEvictFakes([
        '*pg_database*' => Process::result(output: 'live_local'),
    ]));

    $this->artisan('plex:evict local --tenant=gone_local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('not deleting one here')
        ->expectsOutputToContain('Removed');

    // No dump was attempted, but the login drop and the rest still ran.
    Process::assertDidntRun(fn ($process) => str_contains((string) $process->command, 'pg_dump'));
    Process::assertRan(fn ($process) => str_contains((string) $process->command, 'FLUSHDB'));
    Process::assertRan(fn ($process) => str_contains((string) $process->command, 'create configmap plex-registry'));
});

test('plex:evict backs up anyway when it cannot read the catalogue', function (): void {
    // "Cannot tell" must behave like "it is there" — an unreadable catalogue is
    // not evidence of an empty one.
    Process::fake(plexEvictFakes([
        '*pg_database*' => Process::result(output: '', exitCode: 1),
    ]));

    $this->artisan('plex:evict local --tenant=gone_local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('Backup failed');

    Process::assertRan(fn ($process) => str_contains((string) $process->command, 'pg_dump'));
});

test('plex:evict refuses a tenant an installed Cluster Tool still owns', function (): void {
    // A Cluster Tool's tenant carries no `namespace`, so the namespace guard
    // answers "cannot tell" and lets the eviction through — which is how a
    // live tool's database could be dropped with the safety check never
    // firing. Ownership is read from the tool registry instead.
    Process::fake(plexEvictFakes([
        '*get configmap plex-registry*' => Process::result(
            output: (string) json_encode(['tenants' => [
                'outline_notes_luchtech_dev' => ['db' => 'outline_notes_luchtech_dev', 'db_service' => 'postgres'],
            ]]),
        ),
    ]));
    Tests\Support\FakeToolRegistry::install([
        ['tool' => 'notes', 'instance' => 'notes-luchtech-dev', 'host' => 'notes.luchtech.dev'],
    ]);

    $this->artisan('plex:evict local --tenant=outline_notes_luchtech_dev')
        ->assertExitCode(1)
        ->expectsOutputToContain('is installed on this cluster');

    Process::assertNotRan(fn ($p) => str_contains($p->command, 'DROP DATABASE'));
});

test('plex:evict still removes a tenant no installed instance claims', function (): void {
    // The leftovers of a naming migration: a pre-rename tenant name that the
    // tool's CURRENT instance no longer uses. These must stay evictable, or
    // the guard above would strand every corpse a rename leaves behind.
    Process::fake(plexEvictFakes([
        '*get configmap plex-registry*' => Process::result(
            output: (string) json_encode(['tenants' => [
                'outline_main' => ['db' => 'outline_main', 'db_service' => 'postgres', 'redis_index' => 9],
            ]]),
        ),
    ]));
    Tests\Support\FakeToolRegistry::install([
        ['tool' => 'notes', 'instance' => 'notes-luchtech-dev', 'host' => 'notes.luchtech.dev'],
    ]);

    $this->artisan('plex:evict local --tenant=outline_main --force --no-backup')
        ->assertExitCode(0);
});
