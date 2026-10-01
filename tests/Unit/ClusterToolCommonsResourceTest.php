<?php

use App\Enums\ClusterTool;

test('forCommonsResource resolves a tool from its Commons DB name', function (): void {
    expect(ClusterTool::forCommonsResource('record_sendrec'))->toBe(ClusterTool::RECORD)
        ->and(ClusterTool::forCommonsResource('documenso'))->toBe(ClusterTool::SIGN)
        ->and(ClusterTool::forCommonsResource('zitadel'))->toBe(ClusterTool::SSO);
});

test('forCommonsResource resolves a tool from its Commons bucket name', function (): void {
    expect(ClusterTool::forCommonsResource('documenso-storage'))->toBe(ClusterTool::SIGN)
        ->and(ClusterTool::forCommonsResource('forgejo-lfs'))->toBe(ClusterTool::GIT)
        ->and(ClusterTool::forCommonsResource('ocis-storage'))->toBe(ClusterTool::DRIVE);
});

test('forCommonsResource returns null for a genuine Application Tenant', function (): void {
    expect(ClusterTool::forCommonsResource('luchtech_local'))->toBeNull()
        ->and(ClusterTool::forCommonsResource('demo-production'))->toBeNull();
});

test('PASSWORDS is wired into openbaoSyncConfig so secrets:init actually maintains its credentials Secret', function (): void {
    // DATABASE_URL lives in the Secret passwords:init itself creates and
    // controls (alongside admin-token/plain-token). secrets:wire's dynamic
    // ExternalSecret merges (creationPolicy: Merge) a rotated value into that
    // same Secret, so the name has to be the one the manifest writes.
    $config = ClusterTool::PASSWORDS->openbaoSyncConfig();

    expect($config)->not->toBeNull()
        ->and($config['secret'])->toBe('vaultwarden-secrets')
        ->and(ClusterTool::PASSWORDS->openbaoSyncConfig('vault-example-com')['secret'])->toBe('vaultwarden-secrets-vault-example-com')
        ->and($config['keys'])->toContain('VAULTWARDEN_DATABASE_URL');
});
