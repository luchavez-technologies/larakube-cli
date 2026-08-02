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

test('PASSWORDS is wired into openbaoSyncConfig so secrets:init actually maintains vaultwarden-secrets', function () {
    // Regression guard: PasswordsInitCommand's manifest sources DATABASE_URL
    // from vaultwarden-secrets via a required (non-optional) secretKeyRef,
    // but nothing ever put PASSWORDS in the sweep that maintains that
    // Secret — confirmed live 2026-08-02 investigating the Zitadel masterkey
    // incident. Without this, the next passwords:init would have re-created
    // the same class of broken, self-wiping ExternalSecret that took down SSO.
    $config = ClusterTool::PASSWORDS->openbaoSyncConfig();

    expect($config)->not->toBeNull()
        ->and($config['secret'])->toBe('vaultwarden-secrets')
        ->and($config['keys'])->toContain('VAULTWARDEN_DATABASE_URL');
});
