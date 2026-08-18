<?php

use App\Enums\ClusterTool;

test('forCommonsResource resolves a tool from its Commons DB name', function () {
    expect(ClusterTool::forCommonsResource('record_sendrec'))->toBe(ClusterTool::RECORD)
        ->and(ClusterTool::forCommonsResource('sign_documenso'))->toBe(ClusterTool::SIGN)
        ->and(ClusterTool::forCommonsResource('zitadel'))->toBe(ClusterTool::SSO);
});

test('forCommonsResource resolves a tool from its Commons bucket name', function () {
    expect(ClusterTool::forCommonsResource('sign-storage'))->toBe(ClusterTool::SIGN)
        ->and(ClusterTool::forCommonsResource('forgejo-lfs'))->toBe(ClusterTool::GIT)
        ->and(ClusterTool::forCommonsResource('drive-ocis'))->toBe(ClusterTool::DRIVE);
});

test('forCommonsResource returns null for a genuine Application Tenant', function () {
    expect(ClusterTool::forCommonsResource('luchtech_local'))->toBeNull()
        ->and(ClusterTool::forCommonsResource('demo-production'))->toBeNull();
});

test('PASSWORDS is wired into openbaoSyncConfig so secrets:init actually maintains vault-secrets', function () {
    // Regression guard, redesigned 2026-08-18: DATABASE_URL now lives in
    // vault-secrets — the Secret passwords:init itself creates and controls
    // (alongside admin-token/plain-token), not the never-created
    // 'vaultwarden-secrets'. secrets:wire's dynamic ExternalSecret merges
    // (creationPolicy: Merge) a rotated value into this same Secret instead
    // of depending on a separate one that only existed if secrets:init's
    // sweep happened to run first — see PasswordTool::dbSecretRef().
    $config = ClusterTool::PASSWORDS->openbaoSyncConfig();

    expect($config)->not->toBeNull()
        ->and($config['secret'])->toBe('vault-secrets')
        ->and($config['keys'])->toContain('VAULTWARDEN_DATABASE_URL');
});
