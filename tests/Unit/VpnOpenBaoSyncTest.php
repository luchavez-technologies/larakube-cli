<?php

/**
 * NetBird's PAT is mirrored from OpenBao KV so it can be rotated without the CLI.
 */

use App\Enums\ClusterTool;

test('the PAT is readable from OpenBao under an instance-scoped KV key', function (): void {
    $vpn = ClusterTool::VPN;
    $instance = $vpn->instanceSlugFromHost('vpn.luchtech.dev');
    $config = $vpn->openbaoSyncConfig($instance);

    // keyMap, not keys: the CLI reads `pat` from the Secret, and `production/pat`
    // as a KV name would collide with every other tool in the same store.
    expect($config['keyMap'])->toBe(['VPN_VPN_LUCHTECH_DEV_PAT' => 'pat'])
        ->and($config['secret'])->toBe('vpn-management-secrets-vpn-luchtech-dev');

    // Two instances must not read the same KV entry.
    $other = $vpn->openbaoSyncConfig($vpn->instanceSlugFromHost('vpn.other.example'));
    expect(array_key_first($other['keyMap']))->not->toBe(array_key_first($config['keyMap']));
});

test('the setup key is deliberately absent from the KV sync', function (): void {
    // The gateway reads it once at enrolment and never again, so pasting a new
    // one into OpenBao would not re-home an enrolled peer — that still needs
    // vpn:setup-key to clear the daemon's config.json. Offering it here would
    // look like a rotation path that silently does nothing.
    $config = ClusterTool::VPN->openbaoSyncConfig('vpn-luchtech-dev');

    expect($config['keyMap'])->not->toHaveKey('VPN_VPN_LUCHTECH_DEV_SETUP_KEY')
        ->and(array_values($config['keyMap']))->not->toContain('setup-key');
});

test('the PAT sync targets a different Secret than the database rotation', function (): void {
    // secrets:wire's ExternalSecret owns every key in the Secret it targets.
    // Sharing one would let a database rotation clobber the PAT.
    $vpn = ClusterTool::VPN;
    $instance = $vpn->instanceSlugFromHost('vpn.luchtech.dev');

    expect($vpn->openbaoSyncConfig($instance)['secret'])
        ->not->toBe($vpn->dbSecretRef($instance)['secret']);
});

test('the ExternalSecret maps the KV key onto the Secret key', function (): void {
    $config = ClusterTool::VPN->openbaoSyncConfig('vpn-luchtech-dev');

    $manifest = view('k8s.secrets.tool-es', [
        'namespace' => 'larakube-vpn',
        'secretName' => $config['secret'],
        'keys' => [],
        'keyMap' => $config['keyMap'],
        'environment' => 'production',
    ])->render();

    expect($manifest)
        ->toContain('secretKey: pat')
        ->toContain('key: production/VPN_VPN_LUCHTECH_DEV_PAT')
        ->and(Symfony\Component\Yaml\Yaml::parse($manifest))->toBeArray();
});

test('the plain keys form still renders, for every tool that has not moved', function (): void {
    $manifest = view('k8s.secrets.tool-es', [
        'namespace' => 'larakube-shared',
        'secretName' => 'monitor-secrets',
        'keys' => ['GRAFANA_DB_PASSWORD'],
        'environment' => 'production',
    ])->render();

    expect($manifest)
        ->toContain('secretKey: GRAFANA_DB_PASSWORD')
        ->toContain('key: production/GRAFANA_DB_PASSWORD');
});
