<?php

use Illuminate\Support\Facades\Process;

test('paste:init refuses because Yopass is not yet shipped', function (): void {
    Process::fake([
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('paste:init local')
        ->assertExitCode(1)
        ->expectsOutputToContain('Secure Paste Sharing (Yopass) is not yet shipped');

    Process::assertNotRan(fn ($process) => true);
});

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
