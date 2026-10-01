<?php

use Illuminate\Support\Facades\Process;

/**
 * A configured destination, faked at the Secret-read layer.
 *
 * Laravel matches fake patterns in insertion order, so the '*' catch-all is
 * appended last — putting it first swallows every specific pattern.
 */
function backupListFakes(array $overrides = []): array
{
    $val = fn (string $v) => Process::result(output: base64_encode($v));

    $commons = json_encode(['version' => 1, 'services' => [
        'postgres' => ['enabled' => true],
        'mysql' => ['enabled' => false],
        'seaweedfs' => ['enabled' => true],
    ]]);

    return array_merge([
        '*configmap plex-commons*' => Process::result(output: $commons),
        '*larakube-backup-config*bucket*' => $val('off-site-bucket'),
        '*larakube-backup-config*endpoint*' => $val('https://s3.us-west-004.backblazeb2.com'),
        '*larakube-backup-config*access-key*' => $val('AK'),
        '*larakube-backup-config*secret-key*' => $val('SK'),
        '*larakube-backup-config*passphrase*' => $val('test-passphrase'),
        '*larakube-backup-config*region*' => $val('us-east-1'),
        '*get namespace -o jsonpath*' => Process::result(output: 'larakube-shared larakube-vault larakube-secrets larakube-sso larakube-vpn larakube-plex'),
        '*get deployment -n larakube-shared -o jsonpath*' => Process::result(output: 'forgejo forgejo-runner drive-ocis stalwart synapse-chat-luchtech-dev element-web-chat-luchtech-dev coturn-chat-luchtech-dev synapse-db-chat-luchtech-dev webmail-bulwark grafana prometheus-server loki'),
        '*get deployment -n larakube-vault -o jsonpath*' => Process::result(output: 'vaultwarden'),
        '*get deployment -n larakube-secrets -o jsonpath*' => Process::result(output: 'openbao-secrets-example-com'),
        '*get deployment -n larakube-sso -o jsonpath*' => Process::result(output: 'sso-zitadel'),
        '*get deployment -n larakube-vpn -o jsonpath*' => Process::result(output: 'netbird'),
        '*get deployment -n larakube-plex -o jsonpath*' => Process::result(output: 'seaweedfs postgres'),
    ], $overrides, ['*' => Process::result(output: '')]);
}

test('backup:list reports honestly when the destination is empty', function (): void {
    Process::fake(backupListFakes(['*s3 ls*' => Process::result(output: '')]));

    $this->artisan('backup:list local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('No backups found');
});
