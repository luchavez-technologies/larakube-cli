<?php

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\ResourceNaming;
use App\Enums\SecretKind;

test('Chat resolves every resource name from ToolInstance with the category gone', function (): void {
    $names = ToolInstance::forInstance(ClusterTool::CHAT, 'chat-luchtech-dev');

    expect($names->deployment())->toBe('synapse-chat-luchtech-dev')
        ->and($names->deployment('web'))->toBe('element-web-chat-luchtech-dev')
        ->and($names->deployment('admin'))->toBe('element-admin-chat-luchtech-dev')
        ->and($names->deployment('coturn'))->toBe('coturn-chat-luchtech-dev')
        ->and($names->deployment('mas'))->toBe('mas-chat-luchtech-dev')
        ->and($names->secret())->toBe('synapse-secrets-chat-luchtech-dev')
        ->and($names->secret(SecretKind::CONFIG))->toBe('synapse-config-chat-luchtech-dev')
        ->and($names->secret(SecretKind::SMTP))->toBe('synapse-smtp-chat-luchtech-dev')
        ->and($names->secret(SecretKind::OIDC))->toBe('synapse-oidc-chat-luchtech-dev')
        ->and($names->secret(SecretKind::SSO_APP))->toBe('synapse-sso-chat-luchtech-dev')
        ->and($names->secret(SecretKind::SSO_APP, 'mas'))->toBe('mas-sso-chat-luchtech-dev')
        ->and($names->secret(SecretKind::CREDENTIALS, 'mas'))->toBe('mas-secrets-chat-luchtech-dev')
        ->and($names->secret(SecretKind::CONFIG, 'mas'))->toBe('mas-config-chat-luchtech-dev')
        ->and($names->secret(SecretKind::CONFIG, 'coturn'))->toBe('coturn-config-chat-luchtech-dev')
        ->and($names->volume('storage', 'synapse'))->toBe('synapse-storage-chat-luchtech-dev')
        ->and($names->configMap('auth-mode', 'synapse'))->toBe('synapse-auth-mode-chat-luchtech-dev')
        ->and($names->name('media-prune', 'synapse'))->toBe('synapse-media-prune-chat-luchtech-dev')
        ->and($names->name('meet', 'synapse'))->toBe('synapse-meet-chat-luchtech-dev')
        ->and($names->vpnMiddleware()->name)->toBe('synapse-vpn-only-chat-luchtech-dev')
        ->and(ClusterTool::MATRIX->resourceNaming())->toBe(ResourceNaming::CANONICAL);
});

test('Synapse and MAS each get their own database, and the bucket carries the instance', function (): void {
    $names = ToolInstance::forInstance(ClusterTool::CHAT, 'chat-luchtech-dev');

    expect($names->commonsDatabases())->toBe(['synapse_chat_luchtech_dev', 'mas_chat_luchtech_dev'])
        ->and($names->database())->toBe('synapse_chat_luchtech_dev')
        ->and($names->bucket())->toBe('synapse-media-chat-luchtech-dev');
});

test('a purge reaches both databases, which the single-name list used to miss', function (): void {
    expect(ClusterTool::CHAT->commonsDatabases('chat-luchtech-dev'))->toContain('mas_chat_luchtech_dev');
});

test('the rotation Secret and the OpenBao sync Secret are the credentials Secret the manifest writes', function (): void {
    expect(ClusterTool::CHAT->dbSecretRef('chat-luchtech-dev')['secret'])->toBe('synapse-secrets-chat-luchtech-dev')
        ->and(ClusterTool::CHAT->openbaoSyncConfig('chat-luchtech-dev')['secret'])->toBe('synapse-secrets-chat-luchtech-dev');
});

/**
 * Backup discovery resolves a Deployment to a component by name, so Synapse's
 * signing-key volume is only archived while the name resolves.
 */
test('the canonical Synapse Deployment resolves to a component whose signing key is backed up', function (): void {
    $hit = ClusterTool::forInstancedDeployment('synapse-chat-luchtech-dev');

    expect($hit)->not->toBeNull()
        ->and($hit['instance'])->toBe('chat-luchtech-dev')
        ->and($hit['component']->key)->toBe('synapse')
        ->and($hit['component']->backupVolume)->toBeTrue()
        ->and($hit['component']->container)->toBe('synapse');
});

test('every Chat component Deployment resolves back to Chat with its instance', function (): void {
    foreach (['synapse', 'element-web', 'element-admin', 'coturn', 'mas'] as $stem) {
        $hit = ClusterTool::forInstancedDeployment("{$stem}-chat-luchtech-dev");

        expect($hit)->not->toBeNull("{$stem}")
            ->and($hit['tool'])->toBeIn([ClusterTool::CHAT, ClusterTool::MATRIX])
            ->and($hit['instance'])->toBe('chat-luchtech-dev');
    }
});

test('the SSO wiring Secrets for Synapse and MAS are named for their component', function (): void {
    $names = ToolInstance::forInstance(ClusterTool::CHAT, 'chat-luchtech-dev');

    expect($names->secret(SecretKind::SSO_APP))->not->toBe($names->secret(SecretKind::SSO_APP, 'mas'));
});
