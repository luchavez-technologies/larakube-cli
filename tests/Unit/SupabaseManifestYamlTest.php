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
