<?php

use App\Enums\CommonsSecret;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/** A Commons spec with Postgres enabled and one tenant allocated. */
function rotateFakes(array $overrides = []): array
{
    return array_merge([
        '*get configmap plex-commons*' => Process::result(
            output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]]),
        ),
        '*get configmap plex-registry*' => Process::result(
            output: (string) json_encode(['tenants' => ['demo-production' => ['db' => 'demo-production', 'db_service' => 'postgres']]]),
        ),
        // No openbao-bootstrap Secret → literal fallback mode.
        '*openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*exec *' => Process::result(output: 'ALTER ROLE'),
        '*apply -f*' => Process::result(output: 'configured'),
        '*' => Process::result(output: ''),
    ], $overrides);
}

test('plex:rotate refuses when there is no Commons', function () {
    Process::fake([
        '*get configmap plex-commons*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('plex:rotate local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('No Plex Commons found');
});

test('plex:rotate rejects an unknown credential kind by name', function () {
    Process::fake(rotateFakes());

    $this->artisan('plex:rotate local --only=nope --force')
        ->assertExitCode(1)
        ->expectsOutputToContain("Unknown credential kind 'nope'")
        ->expectsOutputToContain('db, s3, admin, tools');
});

test('without the secrets backend it says plainly that a redeploy is required', function () {
    // The whole point of the feature is that the weak mode is never mistaken
    // for the strong one.
    Process::fake(rotateFakes());

    $this->artisan('plex:rotate local --only=db --force')
        ->expectsOutputToContain('Literal rotation')
        ->expectsOutputToContain('redeploy IS required');
});

test('--tenant rejects a name that is not actually a tenant', function () {
    Process::fake(rotateFakes());

    $this->artisan('plex:rotate local --only=db --tenant=ghost --force')
        ->assertExitCode(1)
        ->expectsOutputToContain("'ghost' is not a tenant");
});

test('credentials that cannot be rotated yet are reported, never silently skipped', function () {
    Process::fake(rotateFakes());

    $this->artisan('plex:rotate local --only=s3,admin --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('not rotatable yet')
        ->expectsOutputToContain('not rotated automatically yet');
});

test('plex:rotate routes an OpenBao-wired tenant through rotateStaticRole, never ALTER ROLE', function () {
    // Regression guard for the real danger found live 2026-08-01: a tenant
    // already wired through OpenBao's static-role mechanism must NEVER be
    // rotated via the legacy ALTER ROLE path — doing so would desync
    // Postgres's actual password from OpenBao's cached static-creds,
    // breaking auth until OpenBao's own 7-day rotation_period catches up.
    Process::fake([
        '*get configmap plex-commons*' => Process::result(
            output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]]),
        ),
        '*get configmap plex-registry*' => Process::result(
            // No 'namespace' recorded — this tenant joined before that field
            // existed. Rotation must still succeed via OpenBao; it just can't
            // self-restart the consumer.
            output: (string) json_encode(['tenants' => ['demo-production' => ['db' => 'demo-production', 'db_service' => 'postgres']]]),
        ),
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
        '*exec *' => Process::result(output: 'ALTER ROLE'),
        '*' => Process::result(output: ''),
    ]);

    $requests = [];
    Http::fake(function ($request) use (&$requests) {
        $requests[] = [$request->method(), $request->url()];

        if (str_contains($request->url(), '/database/static-roles/tenant-demo-production')) {
            return Http::response(['data' => ['db_name' => 'plex-postgres']], 200);
        }

        return Http::response([], 204);
    });

    $this->artisan('plex:rotate local --only=db --tenant=demo-production --force')
        ->assertExitCode(0)
        // Same-line substrings: a single check, since expectsOutputToContain's
        // Mockery matching lets only the first-registered expectation claim a
        // given line — see PlexJoinDbSecretTest's "OpenBao:  https://" note.
        ->expectsOutputToContain('rotated via OpenBao. Namespace unknown');

    $methodsAndUrls = collect($requests)->map(fn ($r) => $r[0].' '.$r[1]);

    expect($methodsAndUrls->contains(fn ($s) => str_contains($s, 'GET') && str_contains($s, '/database/static-roles/tenant-demo-production')))->toBeTrue()
        ->and($methodsAndUrls->contains(fn ($s) => str_contains($s, 'POST') && str_contains($s, '/database/rotate-role/tenant-demo-production')))->toBeTrue();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'exec ')
        && str_contains($process->command, 'ALTER ROLE'));
});

test('plex:rotate falls back to ALTER ROLE for a tenant with no OpenBao static role', function () {
    // The other half of the same guard: a tenant that genuinely predates
    // OpenBao (or joined while it was unreachable) must keep using the
    // legacy path — staticRoleExists() returning false must not be treated
    // as "OpenBao unavailable, skip it" and silently do nothing.
    Process::fake([
        '*get configmap plex-commons*' => Process::result(
            output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]]),
        ),
        '*get configmap plex-registry*' => Process::result(
            output: (string) json_encode(['tenants' => ['demo-production' => ['db' => 'demo-production', 'db_service' => 'postgres']]]),
        ),
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
        '*exec *' => Process::result(output: 'ALTER ROLE'),
        '*' => Process::result(output: ''),
    ]);

    Http::fake(function ($request) {
        // Neither naming convention has this role — confirmed absent under
        // both, not just the "tenant-" prefixed one.
        if (str_contains($request->url(), '/database/static-roles/tenant-demo-production')
            || str_contains($request->url(), '/database/static-roles/demo-production')) {
            return Http::response(['errors' => ['no role found']], 404);
        }

        return Http::response([], 204);
    });

    $this->artisan('plex:rotate local --only=db --tenant=demo-production --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('rotated → OpenBao');

    Process::assertRan(fn ($process) => str_contains($process->command, 'exec -i')
        && str_contains($process->command, 'deploy/postgres'));

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/database/rotate-role/tenant-demo-production'));
});

test('plex:rotate finds a cluster-tool tenant under the BARE role name, never the "tenant-" prefix', function () {
    // Regression guard for a real bug found live 2026-08-02 checking
    // production: cluster tools (secrets:wire, RecordInit, SignInit, …)
    // register their static role under the bare tenant name, not "tenant-"
    // prefixed like plex:join's Application Tenants. Confirmed on the actual
    // droplet: GET .../static-roles/tenant-record_sendrec → 404, GET
    // .../static-roles/record_sendrec → 200. Checking only the prefixed name
    // and treating a miss as "not wired" would send an ALREADY OpenBao-wired
    // cluster tool through the legacy ALTER ROLE path — corrupting it.
    //
    // record_sendrec also has no 'namespace' recorded on the registry (it
    // predates that field), but resolves via ClusterTool::forCommonsResource()
    // to RECORD regardless — namespace/deployment/ExternalSecret name are all
    // derived from the enum, not left "unknown".
    Process::fake([
        '*get configmap plex-commons*' => Process::result(
            output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]]),
        ),
        '*get configmap plex-registry*' => Process::result(
            output: (string) json_encode(['tenants' => ['record_sendrec' => ['db' => 'record_sendrec', 'db_service' => 'postgres']]]),
        ),
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
        '*exec *' => Process::result(output: 'ALTER ROLE'),
        '*get deployment record-sendrec*' => Process::result(output: 'record-sendrec'),
        '*rollout restart*' => Process::result(output: 'restarted'),
        '*].status}*' => Process::result(output: 'True'),
        '*].reason}*' => Process::result(output: 'SecretSynced'),
        '*refreshTime}*' => Process::sequence([
            Process::result(output: ''),
            Process::result(output: '2026-08-02T00:00:00Z'),
        ]),
        '*' => Process::result(output: ''),
    ]);

    $requests = [];
    Http::fake(function ($request) use (&$requests) {
        $requests[] = [$request->method(), $request->url()];

        if (str_contains($request->url(), '/database/static-roles/tenant-record_sendrec')) {
            return Http::response(['errors' => ['no role found']], 404);
        }
        if (str_contains($request->url(), '/database/static-roles/record_sendrec')) {
            return Http::response(['data' => ['db_name' => 'plex-postgres']], 200);
        }

        return Http::response([], 204);
    });

    $this->artisan('plex:rotate local --only=db --tenant=record_sendrec --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('rotated via OpenBao and restarted in larakube-shared');

    $methodsAndUrls = collect($requests)->map(fn ($r) => $r[0].' '.$r[1]);

    expect($methodsAndUrls->contains(fn ($s) => str_contains($s, 'GET') && str_contains($s, '/database/static-roles/tenant-record_sendrec')))->toBeTrue()
        ->and($methodsAndUrls->contains(fn ($s) => str_contains($s, 'GET') && str_contains($s, '/database/static-roles/record_sendrec') && ! str_contains($s, 'tenant-')))->toBeTrue()
        ->and($methodsAndUrls->contains(fn ($s) => str_contains($s, 'POST') && str_contains($s, '/database/rotate-role/record_sendrec') && ! str_contains($s, 'tenant-')))->toBeTrue();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'exec ')
        && str_contains($process->command, 'ALTER ROLE'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/record-sendrec -n larakube-shared'));
});

test('plex:rotate re-pushes the URL-shaped cluster secret so the synced value follows the rotated password', function () {
    // The vaultwarden 2026-08-10 outage, fixed at the source: rotating the
    // static role supersedes Postgres's password, but VAULTWARDEN_DATABASE_URL
    // is a full URL with the password baked in — it must be rebuilt from
    // static-creds and re-pushed BEFORE ESO syncs, and the delivered Secret
    // verified to already embed the new password before the consumer restarts.
    Process::fake([
        '*get configmap plex-commons*' => Process::result(
            output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]]),
        ),
        '*get configmap plex-registry*' => Process::result(
            output: (string) json_encode(['tenants' => ['vaultwarden' => ['db' => 'vaultwarden', 'db_service' => 'postgres']]]),
        ),
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
        '*get deployment vaultwarden*' => Process::result(output: 'vaultwarden   1/1   1   1   10d'),
        '*rollout restart*' => Process::result(output: 'restarted'),
        '*].status}*' => Process::result(output: 'True'),
        '*].reason}*' => Process::result(output: 'SecretSynced'),
        '*refreshTime}*' => Process::sequence([
            Process::result(output: ''),
            Process::result(output: '2026-08-10T00:06:22Z'),
        ]),
        '*get secret vaultwarden-secrets*' => Process::result(
            output: base64_encode('postgresql://vaultwarden:rotated-pw-123@postgres.larakube-plex.svc.cluster.local:5432/vaultwarden'),
        ),
        '*' => Process::result(output: ''),
    ]);

    $requests = [];
    Http::fake(function ($request) use (&$requests) {
        $requests[] = [$request->method(), $request->url(), $request->data()];

        if (str_contains($request->url(), '/database/static-roles/tenant-vaultwarden')) {
            return Http::response(['errors' => ['no role found']], 404);
        }
        if (str_contains($request->url(), '/database/static-roles/vaultwarden')) {
            return Http::response(['data' => ['db_name' => 'plex-postgres']], 200);
        }
        if (str_contains($request->url(), '/database/static-creds/vaultwarden')) {
            return Http::response(['data' => ['password' => 'rotated-pw-123']], 200);
        }

        return Http::response([], 204);
    });

    $this->artisan('plex:rotate local --only=db --tenant=vaultwarden --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('re-pushed VAULTWARDEN_DATABASE_URL')
        ->expectsOutputToContain('rotated via OpenBao and restarted in larakube-vault');

    // The rebuilt URL — with the rotated password — reached OpenBao's KV.
    $pushedValues = collect($requests)
        ->filter(fn ($r) => str_contains($r[1], '/secret/data/production/VAULTWARDEN_DATABASE_URL'))
        ->pluck('2.data.value')
        ->filter()
        ->all();

    expect($pushedValues)->toContain('postgresql://vaultwarden:rotated-pw-123@postgres.larakube-plex.svc.cluster.local:5432/vaultwarden');

    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/vaultwarden -n larakube-vault'));
});

test('plex:rotate refuses to restart a consumer when the synced secret does not embed the rotated password', function () {
    // Devsecops fail-closed guard: the re-push + ESO Ready/refreshTime don't
    // prove convergence — the delivered Secret must actually carry the new
    // password before a consumer is restarted against it. Here ESO "synced"
    // the stale value; rotation must fail, not restart vaultwarden onto it.
    Process::fake([
        '*get configmap plex-commons*' => Process::result(
            output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]]),
        ),
        '*get configmap plex-registry*' => Process::result(
            output: (string) json_encode(['tenants' => ['vaultwarden' => ['db' => 'vaultwarden', 'db_service' => 'postgres']]]),
        ),
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
        '*].status}*' => Process::result(output: 'True'),
        '*].reason}*' => Process::result(output: 'SecretSynced'),
        '*refreshTime}*' => Process::sequence([
            Process::result(output: ''),
            Process::result(output: '2026-08-10T00:06:22Z'),
        ]),
        // Stale value: ESO reconciled but delivered the OLD password.
        '*get secret vaultwarden-secrets*' => Process::result(
            output: base64_encode('postgresql://vaultwarden:old-pw@postgres.larakube-plex.svc.cluster.local:5432/vaultwarden'),
        ),
        '*' => Process::result(output: ''),
    ]);

    Http::fake(function ($request) {
        if (str_contains($request->url(), '/database/static-roles/tenant-vaultwarden')) {
            return Http::response(['errors' => ['no role found']], 404);
        }
        if (str_contains($request->url(), '/database/static-roles/vaultwarden')) {
            return Http::response(['data' => ['db_name' => 'plex-postgres']], 200);
        }
        if (str_contains($request->url(), '/database/static-creds/vaultwarden')) {
            return Http::response(['data' => ['password' => 'rotated-pw-123']], 200);
        }

        return Http::response([], 204);
    });

    $this->artisan('plex:rotate local --only=db --tenant=vaultwarden --force')
        ->assertExitCode(1)
        ->expectsOutputToContain("doesn't embed the new password");

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'rollout restart'));
});

test('the per-tenant cluster secret key is namespaced so two tenants never collide', function () {
    $a = CommonsSecret::TENANT_DB->clusterSecretKey('shop-production');
    $b = CommonsSecret::TENANT_DB->clusterSecretKey('blog-production');

    expect($a)->toBe('SHOP_PRODUCTION_DB_PASSWORD')
        ->and($b)->toBe('BLOG_PRODUCTION_DB_PASSWORD')
        ->and($a)->not->toBe($b);
});

test('only the tenant database is per-tenant; the rest are cluster-wide', function () {
    expect(CommonsSecret::TENANT_DB->isPerTenant())->toBeTrue()
        ->and(CommonsSecret::COMMONS_S3->isPerTenant())->toBeFalse()
        ->and(CommonsSecret::COMMONS_ADMIN->isPerTenant())->toBeFalse()
        ->and(CommonsSecret::TOOL_STORE->isPerTenant())->toBeFalse();
});

test('warningLines previews exactly which tenants db rotation will touch, before the confirm prompt', function () {
    // Regression guard: production got "too many, I'm scared" 2026-08-02 —
    // a bare plex:rotate rotated 11 tools in one run with nothing shown
    // beforehand except the credential KIND ("Tenant database"), not who.
    $command = new class extends App\Commands\Plex\PlexRotateCommand
    {
        public function callWarningLines(array $kinds, ?array $tenants): array
        {
            return $this->warningLines('production', $kinds, true, $tenants);
        }
    };

    $single = $command->callWarningLines([CommonsSecret::TENANT_DB], ['demo-production']);
    $many = $command->callWarningLines([CommonsSecret::TENANT_DB], ['a', 'b', 'c']);
    $none = $command->callWarningLines([CommonsSecret::TENANT_DB], []);

    expect(implode("\n", $single))->toContain('1 tenant: demo-production')
        ->and(implode("\n", $many))->toContain('3 tenants: a, b, c')
        ->and(implode("\n", $none))->not->toContain('tenants:')->not->toContain('tenant:');
});

test('the admin password never leaks into an application env', function () {
    // It is an operator credential for the Commons superuser — an app that
    // received it would be able to read every other tenant's data.
    expect(CommonsSecret::COMMONS_ADMIN->envKeys())->toBe([]);
});
