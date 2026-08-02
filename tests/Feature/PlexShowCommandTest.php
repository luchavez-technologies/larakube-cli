<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

function cleanupPlexShowTestDir(string $dir): void
{
    foreach (array_merge(glob($dir.'/*') ?: [], glob($dir.'/.*') ?: []) as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($dir);
}

/**
 * A Commons spec with Postgres enabled and one Application Tenant allocated.
 * The '*' catch-all MUST stay last in the resulting array — Process::fake()
 * matches in array order, and array_merge() appends new override keys AFTER
 * existing default keys, so putting '*' inside the defaults would let it
 * shadow any override (e.g. a specific 'openbao-bootstrap' pattern) before
 * that override is even reached.
 */
function plexShowFakes(array $overrides = []): array
{
    return array_merge([
        '*get configmap plex-commons*' => Process::result(
            output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]]),
        ),
        '*get configmap plex-registry*' => Process::result(
            output: (string) json_encode(['tenants' => ['demo_production' => ['db' => 'demo_production', 'db_service' => 'postgres']]]),
        ),
        '*get deployments -A -o json*' => Process::result(output: (string) json_encode(['items' => []])),
        '*cluster-info*' => Process::result(output: 'reachable'),
    ], $overrides, [
        '*' => Process::result(output: ''),
    ]);
}

test('plex:show surfaces an OpenBao-wired tenant\'s rotation schedule, never the password', function () {
    // Regression guard: staticRoleRotationInfo() reads password+username off
    // the same API response too — this proves plex:show's output never
    // contains either, only the schedule.
    Process::fake(plexShowFakes([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]));

    Http::fake(function ($request) {
        if (str_contains($request->url(), '/database/static-roles/tenant-demo_production')) {
            return Http::response(['data' => ['db_name' => 'plex-postgres']], 200);
        }

        if (str_contains($request->url(), '/database/static-creds/tenant-demo_production')) {
            return Http::response([
                'data' => [
                    'password' => 'super-secret-should-never-print',
                    'username' => 'demo_production',
                    'rotation_period' => 604800,
                    'ttl' => 604000,
                    'last_vault_rotation' => '2026-07-31T23:16:38Z',
                ],
            ], 200);
        }

        return Http::response([], 204);
    });

    $this->artisan('plex:show local --context=test-ctx')
        ->assertExitCode(0)
        // ONE check for the whole line: expectsOutputToContain's Mockery
        // matching lets only the first-registered expectation claim a given
        // line, so a second, separate check against the SAME line (e.g.
        // "every") would never get a chance to match — see PlexJoinDbSecretTest's
        // "OpenBao:  https://" note.
        ->expectsOutputToContain('OpenBao-managed')
        ->doesntExpectOutputToContain('super-secret-should-never-print');
});

test('plex:show marks a tenant with no OpenBao static role as manual (.env)', function () {
    Process::fake(plexShowFakes([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]));

    Http::fake(function ($request) {
        if (str_contains($request->url(), '/database/static-roles/tenant-demo_production')) {
            return Http::response(['errors' => ['no role found']], 404);
        }

        return Http::response([], 204);
    });

    $this->artisan('plex:show local --context=test-ctx')
        ->assertExitCode(0)
        ->expectsOutputToContain('manual (.env) — run');
});

test('plex:show explains a missing DB password instead of leaving a silent gap, for an OpenBao-managed self tenant', function () {
    // Regression guard for the confusion a user hit live 2026-08-02: a
    // password WAS showing here, but it was stale — writeTenantConfig now
    // strips it from .env once OpenBao owns it, so this asserts plex:show
    // explains the omission rather than either leaving a gap or (the old
    // bug) printing a value that no longer matches the real one.
    $dir = sys_get_temp_dir().'/larakube-plexshow-test-'.uniqid();
    mkdir($dir);
    $cwd = getcwd();

    try {
        file_put_contents($dir.'/.larakube.json', json_encode([
            'name' => 'demo',
            'environments' => ['local' => ['plex' => ['postgres']]],
        ]));
        file_put_contents($dir.'/.env', "DB_HOST=postgres.larakube-plex.svc.cluster.local\nDB_DATABASE=demo_local\nDB_USERNAME=demo_local\n");

        chdir($dir);

        Process::fake(plexShowFakes([
            '*get configmap plex-registry*' => Process::result(
                output: (string) json_encode(['tenants' => ['demo_local' => ['db' => 'demo_local', 'db_service' => 'postgres']]]),
            ),
            '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
            '*port-forward*' => Process::result(output: ''),
        ]));

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/database/static-roles/tenant-demo_local')) {
                return Http::response(['data' => ['db_name' => 'plex-postgres']], 200);
            }
            if (str_contains($request->url(), '/database/static-creds/tenant-demo_local')) {
                return Http::response(['data' => [
                    'password' => 'live-password-should-never-print',
                    'rotation_period' => 604800,
                    'ttl' => 604000,
                    'last_vault_rotation' => '2026-08-02T00:00:00Z',
                ]], 200);
            }

            return Http::response([], 204);
        });

        $this->artisan('plex:show --context=test-ctx')
            ->assertExitCode(0)
            ->expectsOutputToContain('database Password: OpenBao-managed')
            ->doesntExpectOutputToContain('live-password-should-never-print');
    } finally {
        chdir($cwd);
        cleanupPlexShowTestDir($dir);
    }
});

test('plex:show never touches OpenBao when it is not installed', function () {
    // Perf/correctness: no bootstrap secret means no port-forward should be
    // attempted at all for the rotation line — the readiness check happens
    // once, up front, not per tenant.
    Process::fake(plexShowFakes([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
    ]));

    $this->artisan('plex:show local --context=test-ctx')
        ->assertExitCode(0)
        ->expectsOutputToContain('manual (.env) — OpenBao not installed');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'port-forward'));
});
