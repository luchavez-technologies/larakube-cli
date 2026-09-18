<?php

test('paste manifest wires Yopass to the Commons Redis at the allocated index, one-time-read, one-day default expiry, no PVC', function (): void {
    $manifest = view('k8s.paste.shared', [
        'host' => 'paste.example.test',
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 7,
        'fileStorage' => null,
        'vpnOnly' => false,
        'isLocal' => true,
        'proxied' => false,
    ])->render();

    expect($manifest)
        ->toContain('image: jhaals/yopass:14.8.0')
        ->toContain('--database=redis')
        ->toContain('--redis=redis://redis.larakube-plex.svc.cluster.local:6379/7')
        ->toContain('--default-expiry=1d')
        ->toContain('containerPort: 1337')
        // Redis is the whole point — no bundled filesystem storage.
        ->not->toContain('PersistentVolumeClaim')
        ->not->toContain('--file-store=s3');
});

test('paste manifest wires optional S3 file storage to Commons SeaweedFS only when given a fileStorage array', function (): void {
    $manifest = view('k8s.paste.shared', [
        'host' => 'paste.example.test',
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 7,
        'fileStorage' => [
            'bucket' => 'paste-yopass',
            'endpoint' => 'http://seaweedfs.larakube-plex.svc.cluster.local:8333',
            'region' => 'us-east-1',
        ],
        'vpnOnly' => false,
        'isLocal' => true,
        'proxied' => false,
    ])->render();

    expect($manifest)
        ->toContain('--file-store=s3')
        ->toContain('--file-store-s3-bucket=paste-yopass')
        ->toContain('--file-store-s3-endpoint=http://seaweedfs.larakube-plex.svc.cluster.local:8333')
        ->toContain('--file-store-s3-region=us-east-1')
        ->toContain('name: AWS_ACCESS_KEY_ID')
        ->toContain('name: paste-yopass-secrets');
});

test('paste ingress applies the vpn-only middleware referencing the exact name PasteTool declares', function (): void {
    $manifest = view('k8s.paste.shared', [
        'host' => 'paste.example.test',
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 7,
        'fileStorage' => null,
        'vpnOnly' => true,
        'isLocal' => true,
        'proxied' => false,
    ])->render();

    expect($manifest)->toContain('larakube-shared-paste-yopass-vpn-only@kubernetescrd');
});

/**
 * Every registry save paste:init makes, in order. `plex-registry` is re-read
 * from $current on each call, so later saves see earlier allocations.
 */
function pasteInitRegistryFakes(array &$saved): array
{
    $saved = [];
    $current = ['tenants' => []];

    return [
        '*plex-commons*' => Process::result(output: (string) json_encode(['version' => 1, 'services' => [
            'redis' => ['enabled' => true],
            'seaweedfs' => ['enabled' => true],
        ]])),
        '*create configmap plex-registry*' => function (Illuminate\Process\PendingProcess $process) use (&$saved, &$current) {
            preg_match("/registry\\.json='([^']+)'/", $process->command, $m);
            $current = json_decode((string) file_get_contents($m[1]), true);
            $saved[] = $current;

            return Process::result(output: 'configmap/plex-registry configured');
        },
        '*get configmap plex-registry*' => function () use (&$current) {
            return Process::result(output: (string) json_encode($current));
        },
        '*get secret plex-admin*' => Process::result(output: base64_encode('key')),
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ];
}

test('paste:init gives each instance its own Commons Redis tenant and bucket', function (): void {
    $saved = [];
    Process::fake(pasteInitRegistryFakes($saved));

    $this->artisan('paste:init local --domain=paste.check.example.com --force --no-interaction')->run();

    $tenants = end($saved)['tenants'] ?? [];

    // The same names `paste:remove --purge` derives, so a purge frees them.
    expect($tenants)->toHaveKey(App\Enums\ClusterTool::PASTE->commonsRedisTenants('paste-check-example-com')[0])
        ->and($tenants)->toHaveKey(App\Enums\ClusterTool::PASTE->commonsBuckets('paste-check-example-com')[0])
        ->and($tenants)->not->toHaveKey('paste_yopass')
        ->and($tenants)->not->toHaveKey('paste-yopass');
});
