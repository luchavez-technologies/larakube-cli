<?php

use App\Enums\ClusterTool;
use App\Services\K8s\ManifestResourceParser;
use App\Services\K8s\ToolFootprint;
use Symfony\Component\Yaml\Yaml;

test('supabase manifests render valid YAML with requests on every container and honor optional component flags', function (): void {
    $footprint = new ToolFootprint;
    $parser = new ManifestResourceParser;

    $full = $footprint->measure(ClusterTool::SUPABASE);
    $slim = $footprint->measure(ClusterTool::SUPABASE, [
        'no-realtime' => true,
        'no-functions' => true,
        'no-imgproxy' => true,
        'no-pooler' => true,
    ]);

    expect($full['memory_bytes'])->toBeGreaterThan($slim['memory_bytes'])
        ->and($full['components'])->not->toBeEmpty();

    foreach ($footprint->renderManifests(ClusterTool::SUPABASE) as $rendered) {
        foreach (preg_split('/^---\s*$/m', $rendered) ?: [] as $doc) {
            if (trim($doc) === '') {
                continue;
            }
            $parsed = Yaml::parse($doc);
            expect($parsed)->toBeArray()->and($parsed['kind'] ?? null)->not->toBeNull();
        }

        foreach ($parser->parse($rendered) as $workload) {
            foreach ($workload->containers as $container) {
                expect($container->declared)->toBeTrue()
                    ->and($container->requestsCpuMillicores)->not->toBeNull()
                    ->and($container->requestsMemoryBytes)->not->toBeNull();
            }
        }
    }
});

test('supabase optional view omits disabled components cleanly', function (): void {
    $rendered = view('k8s.supabase.optional', [
        'instance' => 'supa-example-com',
        'withRealtime' => false,
        'withFunctions' => false,
        'withImgproxy' => false,
        'withPooler' => false,
    ])->render();

    expect(trim($rendered))->toBeEmpty();
});

test('supabase storage reads the Commons S3 login from its Secret, never from literal env', function (): void {
    $rendered = view('k8s.supabase.core', [
        'instance' => 'supa-example-com',
        'host' => 'supa.example.com',
        'isLocal' => false,
        'noPlex' => false,
        's3Bucket' => 'supabase-supa-example-com',
        's3Endpoint' => 'http://seaweedfs.larakube-plex.svc.cluster.local:8333',
        's3Region' => 'us-east-1',
    ])->render();

    $storage = collect(preg_split('/^---\s*$/m', $rendered) ?: [])
        ->map(fn (string $doc): mixed => trim($doc) === '' ? null : Yaml::parse($doc))
        ->first(fn (mixed $doc): bool => is_array($doc) && ($doc['kind'] ?? null) === 'Deployment' && str_contains((string) $doc['metadata']['name'], 'storage'));
    $env = collect($storage['spec']['template']['spec']['containers'][0]['env'])->keyBy('name');

    expect($env['AWS_ACCESS_KEY_ID'])->not->toHaveKey('value')
        ->and($env['AWS_ACCESS_KEY_ID']['valueFrom']['secretKeyRef']['key'])->toBe('s3-access-key')
        ->and($env['AWS_SECRET_ACCESS_KEY'])->not->toHaveKey('value')
        ->and($env['AWS_SECRET_ACCESS_KEY']['valueFrom']['secretKeyRef']['key'])->toBe('s3-secret-key');
});

function supabaseManifestDocs(string $view, array $overrides = []): array
{
    $rendered = view("k8s.supabase.{$view}", [
        'instance' => 'supa-example-com',
        'host' => 'supa.example.com',
        'isLocal' => false,
        'noPlex' => false,
        's3Bucket' => 'b',
        's3Endpoint' => 'http://s3',
        's3Region' => 'us-east-1',
        'withRealtime' => true,
        'withFunctions' => true,
        'withImgproxy' => true,
        'withPooler' => true,
        ...$overrides,
    ])->render();

    return array_values(array_filter(array_map(
        fn (string $doc): mixed => trim($doc) === '' ? null : Yaml::parse($doc),
        preg_split('/^---\s*$/m', $rendered) ?: [],
    )));
}

test('supabase db runs upstream SQL as upstream places it: roles and webhooks as superuser init scripts', function (): void {
    $db = collect(supabaseManifestDocs('db'))->firstWhere('kind', 'StatefulSet');
    $mounts = collect($db['spec']['template']['spec']['containers'][0]['volumeMounts'])->pluck('mountPath', 'subPath');

    expect($mounts['roles.sql'])->toBe('/docker-entrypoint-initdb.d/init-scripts/99-roles.sql')
        ->and($mounts['webhooks.sql'])->toBe('/docker-entrypoint-initdb.d/init-scripts/98-webhooks.sql')
        ->and($mounts['_supabase.sql'])->toBe('/docker-entrypoint-initdb.d/migrations/97-_supabase.sql')
        ->and($mounts['pooler.sql'])->toBe('/docker-entrypoint-initdb.d/migrations/99-pooler.sql')
        ->and($mounts['pgdata'])->toBe('/var/lib/postgresql/data')
        ->and($db['spec']['template']['spec']['containers'][0]['args'])->toContain('config_file=/etc/postgresql/postgresql.conf');
});

test('supabase edge functions get upstream main router with its helper, and supavisor creates its tenant on start', function (): void {
    $docs = collect(supabaseManifestDocs('optional'));
    $router = $docs->first(fn (array $d): bool => $d['kind'] === 'ConfigMap' && str_contains($d['metadata']['name'], 'functions-main'));
    $pooler = $docs->first(fn (array $d): bool => $d['kind'] === 'Deployment' && str_contains($d['metadata']['name'], 'supavisor'));
    $realtime = $docs->first(fn (array $d): bool => $d['kind'] === 'Deployment' && str_contains($d['metadata']['name'], 'realtime'));
    $realtimeEnv = collect($realtime['spec']['template']['spec']['containers'][0]['env'])->keyBy('name');

    $functions = $docs->first(fn (array $d): bool => $d['kind'] === 'Deployment' && str_contains($d['metadata']['name'], 'functions'));
    $routerMounts = collect($functions['spec']['template']['spec']['containers'][0]['volumeMounts'])->where('name', 'functions-main');

    // Edge Runtime copies main/ before compiling it, and a ConfigMap folder mount's symlinks don't survive that.
    expect($routerMounts->pluck('subPath')->all())->toBe(['index.ts', 'memory-pressure.ts'])
        ->and($routerMounts->pluck('mountPath')->all())->toBe(['/home/deno/functions/main/index.ts', '/home/deno/functions/main/memory-pressure.ts'])
        ->and($router['data'])->toHaveKeys(['index.ts', 'memory-pressure.ts', 'deno.jsonc'])
        ->and(implode(' ', $pooler['spec']['template']['spec']['containers'][0]['command']))->toContain('/app/bin/migrate && /app/bin/supavisor eval')
        ->and($realtimeEnv['RELEASE_COOKIE']['valueFrom']['secretKeyRef']['key'])->toBe('realtime-release-cookie')
        ->and($realtimeEnv['APP_NAME']['value'])->toBe('realtime');
});

test('supabase studio only mounts the functions volume when Edge Functions is installed', function (): void {
    $studio = fn (bool $functions): array => collect(supabaseManifestDocs('core', ['withFunctions' => $functions]))
        ->first(fn (array $d): bool => $d['kind'] === 'Deployment' && str_contains($d['metadata']['name'], 'studio'));

    expect(collect($studio(true)['spec']['template']['spec']['volumes'])->pluck('name'))->toContain('functions')
        ->and(collect($studio(false)['spec']['template']['spec']['volumes'])->pluck('name'))->not->toContain('functions');
});

test('supabase db seeds its config volume from the image before Postgres starts', function (): void {
    $db = collect(supabaseManifestDocs('db'))->firstWhere('kind', 'StatefulSet');
    $init = $db['spec']['template']['spec']['initContainers'][0];

    expect(implode(' ', $init['command']))->toContain('tar -C /etc/postgresql-custom -cf - . | tar -C /seed -xf -')
        ->and($init['volumeMounts'][0])->toBe(['name' => 'pgsodium-config', 'mountPath' => '/seed']);
});

test('supabase manifests leave no Blade syntax behind, and every database URL names the db host', function (): void {
    foreach (['db', 'core', 'optional', 'gateway', 'ingress'] as $view) {
        $rendered = view("k8s.supabase.{$view}", [
            'instance' => 'supa-example-com', 'host' => 'supa.example.com', 'isLocal' => false, 'noPlex' => false,
            's3Bucket' => 'b', 's3Endpoint' => 'http://s3', 's3Region' => 'us-east-1',
            'withRealtime' => true, 'withFunctions' => true, 'withImgproxy' => true, 'withPooler' => true,
        ])->render();

        // `@{{ … }}` is Blade's escape for a literal `{{ … }}`: "$(DB_PASSWORD)@{{ $dbHost }}" once rendered as "…{{ $dbHost }}".
        expect($rendered)->not->toContain('{{')
            ->and($rendered)->not->toContain('{!!');

        preg_match_all('#(?:postgres|ecto)://[^"]+#', $rendered, $urls);
        expect($urls[0])->each->toContain('$(DB_PASSWORD)@supabase-db-supa-example-com:5432/');
    }
});

test('every Supabase part that needs the database waits for it before starting, and only those', function (): void {
    $deployments = collect([...supabaseManifestDocs('core'), ...supabaseManifestDocs('optional'), ...supabaseManifestDocs('gateway')])
        ->where('kind', 'Deployment')
        ->mapWithKeys(fn (array $d): array => [preg_replace('/^supabase-|-supa-example-com$/', '', $d['metadata']['name']) => $d]);

    $waits = $deployments->filter(fn (array $d): bool => collect($d['spec']['template']['spec']['initContainers'] ?? [])->contains('name', 'wait-for-db'));

    expect($waits->keys()->sort()->values()->all())->toBe(['auth', 'meta', 'realtime', 'rest', 'storage', 'supavisor']);

    foreach ($waits as $deployment) {
        $wait = collect($deployment['spec']['template']['spec']['initContainers'])->firstWhere('name', 'wait-for-db');
        expect(implode(' ', $wait['command']))->toContain('pg_isready -q -h supabase-db-supa-example-com -p 5432');
    }
});

test('supabase ingress names no ingress class, like every other Cluster Tool, so LaraKube\'s Traefik serves it', function (): void {
    $ingress = collect(supabaseManifestDocs('ingress'))->firstWhere('kind', 'Ingress');

    expect($ingress['spec'])->not->toHaveKey('ingressClassName');
});
