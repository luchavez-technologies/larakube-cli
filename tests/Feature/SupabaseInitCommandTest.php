<?php

use App\Services\PlexService;
use Illuminate\Support\Facades\Process;

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
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*get configmap plex-commons*' => Process::result(output: json_encode([
            'version' => 1,
            'services' => ['seaweedfs' => ['enabled' => true]],
        ])),
        '*get secret plex-admin*' => Process::result(output: base64_encode('s3-secret')),
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

test('supabase:init pushes keys to OpenBao when secrets backend is available', function (): void {
    Process::fake(supabaseClusterFakes([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'openbao', 'instance' => 'secrets-example-com', 'host' => 'secrets.example.com'],
        ]))),
        '*--no-headers*' => Process::result(output: 'openbao-secrets Opaque 1 1d'),
        '*root-token*' => Process::result(output: base64_encode('s.root-token')),
    ]));

    $this->artisan('tool:init --tool=supabase local --domain=supabase.example.com --admin-email=ops@example.com --no-interaction')
        ->assertExitCode(0);

    Process::assertRan(fn ($p): bool => str_contains((string) $p->command, 'root-token'));
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
