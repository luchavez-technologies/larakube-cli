<?php

use App\Enums\ClusterTool;

test('OpenBao declares no Commons database, cache or storage', function (): void {
    $capabilities = ClusterTool::OPENBAO->commonsCapabilities();

    expect($capabilities['databases'])->toBeEmpty()
        ->and($capabilities['cache'])->toBeEmpty()
        ->and($capabilities['storage'])->toBeEmpty()
        ->and(ClusterTool::OPENBAO->commonsDatabases())->toBeEmpty()
        ->and(ClusterTool::OPENBAO->commonsBuckets())->toBeEmpty()
        ->and(ClusterTool::OPENBAO->commonsRedisKeys())->toBeEmpty();
});

test('the OpenBao manifest stores data on its own volume and reaches no Commons service', function (): void {
    $manifest = view('k8s.openbao.openbao', ['host' => 'openbao.example.com'])->render();

    expect($manifest)->toContain('storage "file"')
        ->not->toContain('storage "postgresql"')
        ->not->toMatch('/larakube-plex|redis|seaweedfs/i');
});
