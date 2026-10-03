<?php

use App\Enums\ClusterTool;

test('forCommonsResource resolves a tool from its Commons DB name', function (): void {
    expect(ClusterTool::forCommonsResource('sendrec'))->toBe(ClusterTool::RECORD)
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

test('PASSWORDS is wired into openbaoSyncConfig so tool:init --tool=openbao actually maintains its credentials Secret', function (): void {
    // DATABASE_URL lives in the Secret tool:init --tool=vaultwarden itself creates and
    // controls (alongside admin-token/plain-token). secrets:wire's dynamic
    // ExternalSecret merges (creationPolicy: Merge) a rotated value into that
    // same Secret, so the name has to be the one the manifest writes.
    $config = ClusterTool::PASSWORDS->openbaoSyncConfig();

    expect($config)->not->toBeNull()
        ->and($config['secret'])->toBe('vaultwarden-secrets')
        ->and(ClusterTool::PASSWORDS->openbaoSyncConfig('vault-example-com')['secret'])->toBe('vaultwarden-secrets-vault-example-com')
        ->and($config['keys'])->toContain('VAULTWARDEN_DATABASE_URL');
});

test('resolveCommonsResource names the tool and the instance an instanced tenant or bucket belongs to', function (): void {
    $cases = [
        'sendrec_record_example_com' => [ClusterTool::RECORD, 'record-example-com'],
        'forgejo_git_luchtech_dev' => [ClusterTool::GIT, 'git-luchtech-dev'],
        'yopass-storage-paste-example-com' => [ClusterTool::PASTE, 'paste-example-com'],
        'forgejo-lfs-git-luchtech-dev' => [ClusterTool::GIT, 'git-luchtech-dev'],
        'stalwart-storage-send-luchtech-dev' => [ClusterTool::MAIL, 'send-luchtech-dev'],
    ];

    foreach ($cases as $name => [$tool, $instance]) {
        $resolved = ClusterTool::resolveCommonsResource($name);

        expect($resolved['tool'] ?? null)->toBe($tool, $name)
            ->and($resolved['instance'] ?? null)->toBe($instance, $name);
    }

    // A bare name is the tool itself, and an application tenant is nobody's.
    expect(ClusterTool::resolveCommonsResource('sendrec'))->toBe(['tool' => ClusterTool::RECORD, 'instance' => null])
        ->and(ClusterTool::resolveCommonsResource('luchtech_local'))->toBeNull();
});
