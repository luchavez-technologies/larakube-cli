<?php

use App\Services\PlexService;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

function supabaseClusterFakes(array $overrides = []): array
{
    return $overrides + [
        '*get nodes -o json*' => Process::result(output: json_encode(['items' => [
            ['status' => ['allocatable' => ['cpu' => '4', 'memory' => '8Gi']]],
        ]])),
        '*get pods -A -o json*' => Process::result(output: json_encode(['items' => []])),
        '*apis/metrics.k8s.io/v1beta1/nodes*' => Process::result(output: json_encode(['items' => [
            ['usage' => ['cpu' => '200m', 'memory' => '512Mi']],
        ]])),
        '*apis/metrics.k8s.io/v1beta1/pods*' => Process::result(output: json_encode(['items' => []])),
        '*get deployments -A*' => Process::result(output: json_encode(['items' => []])),
        '*create namespace*' => Process::result(output: 'namespace created'),
        // Before the catch-all '*get secret*': the first matching pattern wins.
        '*get secret plex-admin*' => Process::result(output: base64_encode('s3-secret')),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*get configmap plex-commons*' => Process::result(output: json_encode([
            'version' => 1,
            'services' => ['seaweedfs' => ['enabled' => true]],
        ])),
        '*exec -n larakube-plex*' => Process::result(output: 'bucket ok'),
        '*exec -n larakube-shared*' => Process::result(output: 'CREATE EXTENSION'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*delete *' => Process::result(output: 'deleted'),
    ];
}

test('supabase:init deploys full stack, enables pgvector, allocates S3 bucket, and never leases Commons Postgres', function (): void {
    Process::fake(supabaseClusterFakes());

    $this->artisan('tool:init --tool=supabase local --domain=supabase.example.com --admin-email=ops@example.com --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Supabase is ready at https://supabase.example.com');

    Process::assertRan(fn ($p): bool => str_contains((string) $p->command, 'create extension if not exists vector with schema extensions'));
    Process::assertNotRan(fn ($p): bool => str_contains((string) $p->command, 'deploy/postgres'));

    $plexRegistry = app(PlexService::class)->registry();
    expect($plexRegistry['tenants'] ?? [])->not->toHaveKey('supabase');
});

test('supabase:init reuses existing secrets on re-run and supports --no-plex PVC mode', function (): void {
    $existingSecret = json_encode([
        'data' => [
            'jwt-secret' => base64_encode('existing-jwt-secret'),
            'anon-key' => base64_encode('existing-anon-key'),
            'service-role-key' => base64_encode('existing-service-role'),
            'db-password' => base64_encode('existing-db-pass'),
        ],
    ]);

    Process::fake(supabaseClusterFakes([
        '*supabase-secrets-supabase-example-com*' => Process::result(output: (string) $existingSecret),
    ]));

    $this->artisan('tool:init --tool=supabase local --domain=supabase.example.com --no-plex --no-functions --no-interaction')
        ->assertExitCode(0);

    Process::assertRan(function ($job): bool {
        $secret = appliedSecret($job);

        return $secret !== null
            && $secret['name'] === 'supabase-secrets-supabase-example-com'
            && ($secret['data']['jwt-secret'] ?? null) === 'existing-jwt-secret';
    });
});

test('supabase:init refuses on a 2Gi node (never_fits) even with --force', function (): void {
    Process::fake(supabaseClusterFakes([
        '*get nodes -o json*' => Process::result(output: json_encode(['items' => [
            ['status' => ['allocatable' => ['cpu' => '1', 'memory' => '2Gi']]],
        ]])),
        '*apis/metrics.k8s.io/v1beta1/nodes*' => Process::result(output: json_encode(['items' => [
            ['usage' => ['cpu' => '200m', 'memory' => '700Mi']],
        ]])),
    ]));

    $this->artisan('tool:init --tool=supabase local --domain=supabase.example.com --force --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('does not have enough free capacity');
});

test('supabase:remove keeps db PVCs by default', function (): void {
    Process::fake([
        ...registeredToolRemoveFakes('supabase:remove', 'supabase-example-com', 'supabase.example.com'),
        '*get deployment*' => Process::result(output: 'supabase-gateway-supabase-example-com 1/1'),
        '*delete*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('supabase:remove local --force --no-interaction')->assertExitCode(0);
    Process::assertRan(fn ($p): bool => str_contains((string) $p->command, 'statefulset/supabase-db-supabase-example-com'));
    Process::assertNotRan(fn ($p): bool => str_contains((string) $p->command, 'persistentvolumeclaim/supabase-db-supabase-example-com'));
});

test('supabase:remove deletes db PVCs on --purge', function (): void {
    Process::fake([
        ...registeredToolRemoveFakes('supabase:remove', 'supabase-example-com', 'supabase.example.com'),
        '*get deployment*' => Process::result(output: 'supabase-gateway-supabase-example-com 1/1'),
        '*delete*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('supabase:remove local --force --purge --no-interaction')->assertExitCode(0);
    Process::assertRan(fn ($p): bool => str_contains((string) $p->command, 'persistentvolumeclaim/supabase-db-supabase-example-com'));
});

test('supabase:init pushes its keys to OpenBao when the secrets backend is available', function (): void {
    Process::fake(supabaseClusterFakes([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'openbao', 'instance' => 'secrets-example-com', 'host' => 'secrets.example.com'],
        ]))),
        '*--no-headers*' => Process::result(output: 'openbao-secrets Opaque 1 1d'),
        '*root-token*' => Process::result(output: base64_encode('s.root-token')),
        '*port-forward*' => Process::result(),
    ]));
    $openBao = Saloon::fake([
        '*' => MockResponse::make(['data' => ['created_time' => '2026-11-19T00:00:00Z']]),
    ]);

    $this->artisan('tool:init --tool=supabase local --domain=supabase.example.com --admin-email=ops@example.com --no-interaction')
        ->assertExitCode(0);

    foreach (['SUPABASE_JWT_SECRET', 'SUPABASE_ANON_KEY', 'SUPABASE_SERVICE_ROLE_KEY', 'SUPABASE_DB_PASSWORD'] as $key) {
        $openBao->assertSent(fn ($request): bool => str_ends_with($request->resolveEndpoint(), "/v1/secret/data/dev/{$key}"));
    }

    MockClient::destroyGlobal();
});

test('supabase:init supports two --domain instances with isolated resources', function (): void {
    Process::fake(supabaseClusterFakes());

    $this->artisan('tool:init --tool=supabase local --domain=sb-one.example.com --admin-email=ops@example.com --no-interaction')
        ->assertExitCode(0);
    $this->artisan('tool:init --tool=supabase local --domain=sb-two.example.com --admin-email=ops@example.com --no-interaction')
        ->assertExitCode(0);

    Process::assertRan(fn ($p): bool => str_contains((string) $p->command, 'statefulset/supabase-db-sb-one-example-com'));
    Process::assertRan(fn ($p): bool => str_contains((string) $p->command, 'statefulset/supabase-db-sb-two-example-com'));
});

test('supabase:init stores the Commons S3 login in its Secret', function (): void {
    Process::fake(supabaseClusterFakes());

    $this->artisan('tool:init --tool=supabase local --domain=supabase.example.com --admin-email=ops@example.com --no-interaction')
        ->assertExitCode(0);

    Process::assertRan(function ($job): bool {
        $secret = appliedSecret($job);

        return $secret !== null
            && $secret['name'] === 'supabase-secrets-supabase-example-com'
            && ($secret['data']['s3-access-key'] ?? null) === 's3-secret'
            && ($secret['data']['s3-secret-key'] ?? null) === 's3-secret';
    });
});

test('supabase:init stops when the Commons S3 login cannot be read, instead of deploying with made-up keys', function (): void {
    Process::fake(supabaseClusterFakes([
        '*get secret plex-admin*' => Process::result(output: ''),
    ]));

    $this->artisan('tool:init --tool=supabase local --domain=supabase.example.com --admin-email=ops@example.com --no-interaction')
        ->expectsOutputToContain('Could not read the Plex Commons S3 credentials')
        ->assertExitCode(1);

    Process::assertNotRan(fn ($p): bool => str_contains((string) $p->command, 'supabase.yaml'));
});

test('supabase:init removes an optional part that a re-run turns off', function (): void {
    Process::fake(supabaseClusterFakes());

    $this->artisan('tool:init --tool=supabase local --domain=supabase.example.com --admin-email=ops@example.com --no-functions --no-interaction')
        ->assertExitCode(0);

    Process::assertRan(fn ($p): bool => str_contains((string) $p->command, 'delete deployment/supabase-functions-supabase-example-com service/supabase-functions-supabase-example-com'));
    Process::assertNotRan(fn ($p): bool => str_contains((string) $p->command, 'delete deployment/supabase-realtime-'));
});

test('supabase:init waits for the database before pgvector, and fails when it never becomes ready', function (): void {
    $registry = Tests\Support\FakeToolRegistry::install();
    Process::fake(supabaseClusterFakes([
        '*rollout status statefulset/supabase-db-*' => Process::result(errorOutput: 'timed out', exitCode: 1),
    ]));

    $this->artisan('tool:init --tool=supabase local --domain=supabase.example.com --admin-email=ops@example.com --no-interaction')
        ->expectsOutputToContain('did not become ready')
        ->assertExitCode(1);

    Process::assertNotRan(fn ($p): bool => str_contains((string) $p->command, 'create extension'));
    // Registered anyway, so supabase:remove can clean up an install that failed partway.
    expect(collect($registry->stored)->pluck('tool'))->toContain('supabase');
});

test('supabase:init fails when pgvector cannot be enabled', function (): void {
    Process::fake(supabaseClusterFakes([
        '*create extension*' => Process::result(errorOutput: 'permission denied', exitCode: 1),
    ]));

    $this->artisan('tool:init --tool=supabase local --domain=supabase.example.com --admin-email=ops@example.com --no-interaction')
        ->expectsOutputToContain('Could not enable the pgvector extension')
        ->assertExitCode(1);
});

test('supabase:init --no-plex keeps files on its own volume', function (): void {
    Process::fake(supabaseClusterFakes());

    $this->artisan('tool:init --tool=supabase local --domain=supabase.example.com --no-plex --no-interaction')
        ->assertExitCode(0);

    Process::assertNotRan(fn ($p): bool => str_contains((string) $p->command, 'get secret plex-admin'));
    Process::assertRan(fn ($p): bool => str_contains((string) $p->command, 'rollout status deployment/supabase-storage-supabase-example-com'));
});

function supabaseRemoveFakes(bool $bundledStorage): array
{
    return [
        ...registeredToolRemoveFakes('supabase:remove', 'supabase-example-com', 'supabase.example.com'),
        '*get persistentvolumeclaim/supabase-storage-supabase-example-com*' => Process::result(output: $bundledStorage ? 'persistentvolumeclaim/supabase-storage-supabase-example-com' : ''),
        '*get configmap plex-commons*' => Process::result(output: json_encode(['version' => 1, 'services' => ['seaweedfs' => ['enabled' => true]]])),
        '*get deployment*' => Process::result(output: 'supabase-gateway-supabase-example-com 1/1'),
        '*delete*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ];
}

test('supabase:remove --purge drops the Commons bucket of a Commons install', function (): void {
    Process::fake(supabaseRemoveFakes(bundledStorage: false));

    $this->artisan('supabase:remove local --force --purge --no-interaction')->assertExitCode(0);

    Process::assertRan(fn ($p): bool => str_contains((string) $p->command, 'deploy/seaweedfs'));
});

test('supabase:remove --purge leaves the Commons alone for a --no-plex install', function (): void {
    Process::fake(supabaseRemoveFakes(bundledStorage: true));

    $this->artisan('supabase:remove local --force --purge --no-interaction')->assertExitCode(0);

    Process::assertNotRan(fn ($p): bool => str_contains((string) $p->command, 'deploy/seaweedfs'));
    Process::assertRan(fn ($p): bool => str_contains((string) $p->command, 'persistentvolumeclaim/supabase-storage-supabase-example-com'));
});

test('supabase:init offers to create the Commons when the cluster has none', function (): void {
    Process::fake(supabaseClusterFakes([
        '*get configmap plex-commons*' => Process::result(output: '', exitCode: 1),
    ]));

    $this->artisan('tool:init --tool=supabase local --domain=supabase.example.com --admin-email=ops@example.com')
        ->expectsConfirmation('No Commons on this cluster yet. Create one now?', 'no')
        ->expectsOutputToContain('A Commons is required')
        ->assertExitCode(1);

    Process::assertNotRan(fn ($p): bool => str_contains((string) $p->command, 'supabase.yaml'));
});

test('supabase:init uses the MinIO a Commons already runs, not SeaweedFS', function (): void {
    $applied = '';
    Process::fake(supabaseClusterFakes([
        '*get configmap plex-commons*' => Process::result(output: json_encode([
            'version' => 1,
            'services' => ['minio' => ['enabled' => true]],
        ])),
        '*supabase.yaml*' => function ($process) use (&$applied) {
            preg_match("/apply -f '([^']+supabase\\.yaml)'/", (string) $process->command, $match);
            $applied = isset($match[1]) ? (string) file_get_contents($match[1]) : '';

            return Process::result(output: 'applied');
        },
    ]));

    $this->artisan('tool:init --tool=supabase local --domain=supabase.example.com --admin-email=ops@example.com --no-interaction')
        ->assertExitCode(0);

    expect($applied)->toContain('http://minio.larakube-plex.svc.cluster.local:9000')
        ->not->toContain('seaweedfs');
});

test('supabase:init makes the admin email Studio\'s login on a first install', function (): void {
    Process::fake(supabaseClusterFakes());

    $this->artisan('tool:init --tool=supabase local --domain=supabase.example.com --admin-email=ops@example.com --no-interaction')
        ->assertExitCode(0);

    Process::assertRan(fn ($job): bool => (appliedSecret($job)['data']['dashboard-username'] ?? null) === 'ops@example.com');
});

test('supabase:init keeps a Studio login already stored', function (): void {
    Process::fake(supabaseClusterFakes([
        '*get secret supabase-secrets-supabase-example-com*' => Process::result(output: (string) json_encode(['data' => ['dashboard-username' => base64_encode('supabase')]])),
    ]));

    $this->artisan('tool:init --tool=supabase local --domain=supabase.example.com --admin-email=ops@example.com --no-interaction')
        ->assertExitCode(0);

    Process::assertRan(fn ($job): bool => (appliedSecret($job)['data']['dashboard-username'] ?? null) === 'supabase');
});

test('supabase:show --json reports Studio\'s basic-auth login', function (): void {
    Tests\Support\FakeToolRegistry::install([['tool' => 'supabase', 'instance' => 'supabase-example-com', 'host' => 'supabase.example.com']]);
    Process::fake([
        '*get secret supabase-secrets-supabase-example-com*' => Process::result(output: (string) json_encode(['data' => [
            'dashboard-username' => base64_encode('ops@example.com'),
            'dashboard-password' => base64_encode('s3cret'),
        ]])),
        '*' => Process::result(output: ''),
    ]);

    Illuminate\Support\Facades\Artisan::call('supabase:show', ['environment' => 'local', '--domain' => 'supabase.example.com', '--json' => true]);
    $payload = json_decode(Illuminate\Support\Facades\Artisan::output(), true);

    expect($payload['credentials'] ?? null)->toBe(['admin_username' => 'ops@example.com', 'admin_password' => 's3cret']);
});
