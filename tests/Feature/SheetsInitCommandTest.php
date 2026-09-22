<?php

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;
use Illuminate\Support\Facades\Process;

test('sheets manifest carries canonical resource naming and identity labels', function (): void {
    $manifest = view('k8s.sheet.teable', [
        'host' => 'sheet.example.test',
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 2,
        'vpnOnly' => false,
        'isLocal' => true,
        's3PublicEndpoint' => 'http://seaweedfs.larakube-plex.svc.cluster.local:8333',
        's3InternalEndpoint' => 'http://seaweedfs.larakube-plex.svc.cluster.local:8333',
        's3PublicBucket' => 'teable-public-sheet-example-test',
        's3PrivateBucket' => 'teable-private-sheet-example-test',
    ])->render();

    expect($manifest)
        ->toContain('name: teable-sheet-example-test')
        ->toContain('larakube.io/tool: sheets')
        ->toContain('larakube.io/component: teable')
        ->toContain('larakube.io/instance: sheet-example-test')
        ->toContain('name: teable-secrets-sheet-example-test')
        ->toContain('value: teable-public-sheet-example-test')
        ->toContain('value: teable-private-sheet-example-test');
});

test('sheets ingress carries canonical naming and proxy annotations', function (): void {
    $cloud = view('k8s.sheet.teable', [
        'host' => 'sheet.luchtech.dev',
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 2,
        'vpnOnly' => false,
        'isLocal' => false,
        'proxied' => true,
        's3PublicEndpoint' => 'https://files.luchtech.dev',
        's3InternalEndpoint' => 'http://seaweedfs.larakube-plex.svc.cluster.local:8333',
        's3PublicBucket' => 'teable-public-sheet-luchtech-dev',
        's3PrivateBucket' => 'teable-private-sheet-luchtech-dev',
    ])->render();

    expect($cloud)
        ->toContain('name: teable-sheet-luchtech-dev')
        ->toContain('external-dns.alpha.kubernetes.io/cloudflare-proxied: "true"');
});

test('sheets ToolInstance resolves canonical database, buckets, redis and secrets', function (): void {
    $instance = ToolInstance::forHost(ClusterTool::SHEETS, 'sheet.example.test');

    expect($instance->deployment())->toBe('teable-sheet-example-test')
        ->and($instance->secret())->toBe('teable-secrets-sheet-example-test')
        ->and($instance->secret(SecretKind::SMTP))->toBe('teable-smtp-sheet-example-test')
        ->and($instance->secret(SecretKind::OIDC))->toBe('teable-oidc-sheet-example-test')
        ->and($instance->database())->toBe('teable_sheet_example_test')
        ->and($instance->redisTenant())->toBe('teable_sheet-example-test')
        ->and($instance->bucket('teable-public'))->toBe('teable-public-sheet-example-test')
        ->and($instance->bucket('teable-private'))->toBe('teable-private-sheet-example-test');
});

test('sheets:init provisions canonical resources and secret', function (): void {
    Process::fake([
        '*plex-commons*' => Process::result(output: '{"services":{"postgres":{"enabled":true},"redis":{"enabled":true},"seaweedfs":{"enabled":true,"host":"files.example.com"}}}'),
        '*plex-registry*' => Process::result(output: '{"tenants":{}}'),
        '*plex-admin*' => Process::result(output: base64_encode('s3-access-key')),
        '*create namespace*' => Process::result(output: 'created'),
        '*get secret*' => Process::result(output: base64_encode('secret-val')),
        '*exec*' => Process::result(output: 'success'),
        '*apply*' => Process::result(output: 'created'),
        '*rollout status*' => Process::result(output: 'deployment "teable-sheet-dev-test" successfully rolled out'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('sheets:init local --force')->assertExitCode(0);
    Process::assertRan(fn ($process) => str_contains((string) $process->command, 'rollout status deployment/teable-'));
});
