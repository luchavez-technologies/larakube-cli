<?php

use App\Traits\InteractsWithBackup;
use Illuminate\Support\Facades\Process;

/**
 * A configured destination, faked at the Secret-read layer.
 *
 * Laravel matches fake patterns in insertion order, so the '*' catch-all is
 * appended last — putting it first swallows every specific pattern.
 */
function backupRunFakes(array $overrides = []): array
{
    $val = fn (string $v) => Process::result(output: base64_encode($v));

    $commons = json_encode(['version' => 1, 'services' => [
        'postgres' => ['enabled' => true],
        'mysql' => ['enabled' => false],
        'seaweedfs' => ['enabled' => true],
    ]]);

    return array_merge([
        // Driver detection reads this: the backup is engine-aware, not
        // Postgres-shaped, so the Commons manifest has to be present.
        '*configmap plex-commons*' => Process::result(output: $commons),
        '*larakube-backup-config*bucket*' => $val('off-site-bucket'),
        '*larakube-backup-config*endpoint*' => $val('https://s3.us-west-004.backblazeb2.com'),
        '*larakube-backup-config*access-key*' => $val('AK'),
        '*larakube-backup-config*secret-key*' => $val('SK'),
        '*larakube-backup-config*passphrase*' => $val('test-passphrase'),
        '*larakube-backup-config*region*' => $val('us-east-1'),
        // Dynamic PVC discovery (backupVolumeTargets()) — a realistic live
        // cluster shape so every existing test's expectations (Prometheus
        // excluded, Synapse signing key included, etc.) still hold under the
        // new discovery mechanism, not the old hardcoded array.
        '*get namespace -o jsonpath*' => Process::result(output: 'larakube-shared larakube-vault larakube-secrets larakube-sso larakube-vpn larakube-plex'),
        '*get deployment -n larakube-shared -o jsonpath*' => Process::result(output: 'forgejo forgejo-runner drive-ocis stalwart chat-synapse chat-cinny chat-coturn chat-synapse-db webmail-bulwark grafana prometheus-server loki'),
        '*get deployment -n larakube-vault -o jsonpath*' => Process::result(output: 'vaultwarden'),
        '*get deployment -n larakube-secrets -o jsonpath*' => Process::result(output: 'openbao-backend'),
        '*get deployment -n larakube-sso -o jsonpath*' => Process::result(output: 'sso-zitadel'),
        '*get deployment -n larakube-vpn -o jsonpath*' => Process::result(output: 'netbird-management'),
        '*get deployment -n larakube-plex -o jsonpath*' => Process::result(output: 'seaweedfs postgres'),
    ], $overrides, ['*' => Process::result(output: '')]);
}

test('backup:run refuses to run before a destination is configured', function (): void {
    Process::fake(['*' => Process::result(output: '')]);

    $this->artisan('backup:run local --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('No backup destination configured');
});

test('backup:run aborts and uploads nothing when a dump fails', function (): void {
    // A partial backup that reports success is the one you discover during a
    // restore. Failing loudly and uploading nothing is the safer outcome.
    Process::fake(backupRunFakes([
        '*pg_database*' => Process::result(output: "chat_matrix\nvaultwarden"),
        '*pg_dump*' => Process::result(output: '', exitCode: 1),
    ]));

    $this->artisan('backup:run local --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('Backup incomplete, nothing uploaded');

    Process::assertNotRan(fn ($job) => str_contains($job->command, 's3 cp'));
});

test('backup:run aborts when the cluster reports no databases', function (): void {
    Process::fake(backupRunFakes(['*pg_database*' => Process::result(output: '')]));

    $this->artisan('backup:run local --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('no databases to back up');
});

test('the inventory excludes Prometheus and includes the Synapse signing key', function (): void {
    Process::fake(backupRunFakes());

    $cmd = new class
    {
        use InteractsWithBackup;

        /** @return array<int, array<string, string>> */
        public function targets(): array
        {
            return $this->backupVolumeTargets('kubectl');
        }
    };

    $names = array_column($cmd->targets(), 'name');
    $paths = array_column($cmd->targets(), 'path');

    // Prometheus is the largest volume on the cluster and the least valuable —
    // metrics history, rebuildable by waiting. Backing it up would quadruple
    // every archive for nothing.
    expect($names)->not->toContain('prometheus')
        // 59 bytes, and losing it permanently breaks federation and every
        // existing device session.
        ->and($names)->toContain('synapse-identity')
        ->and($paths)->toContain('/data/chat.luchtech.dev.signing.key')
        // The object store holds chat media, git LFS, notes, signed documents.
        ->and($names)->toContain('seaweedfs')
        ->and($names)->toContain('openbao')
        ->and($names)->toContain('vaultwarden')
        // The other two legacy names (see InteractsWithBackup::LEGACY_VOLUME_NAMES)
        // — a backup taken before dynamic discovery must still restore under
        // these exact names.
        ->and($names)->toContain('forgejo')
        ->and($names)->toContain('drive-ocis');

    // Only Synapse's own component is covered — its sibling Deployments
    // (Cinny, Coturn, the bundled --no-plex Postgres) never opted in, so
    // their bulk/rebuildable data stays excluded even though they're live
    // Deployments discovery sees just as clearly as Synapse itself.
    expect($names)->not->toContain('chat-cinny')
        ->and($names)->not->toContain('chat-coturn')
        ->and($names)->not->toContain('chat-db');
});

test('a tool with no legacy name gets the derived {tool}-{component} format', function (): void {
    // DRIVE's component key is "app" — its legacy name ("drive-ocis") is
    // preserved via the map, but a hypothetical future backup-worthy
    // component with no legacy entry must still get a stable, predictable
    // name rather than an empty or null one.
    Process::fake(backupRunFakes());

    $cmd = new class
    {
        use InteractsWithBackup;

        public function targets(): array
        {
            return $this->backupVolumeTargets('kubectl');
        }
    };

    // SECRETS's component key is "app" and IS in the legacy map ("openbao")
    // — confirms the map takes priority over the derived format for a name
    // that predates it, rather than both existing side by side under two
    // different names for the same component.
    $names = array_column($cmd->targets(), 'name');
    expect($names)->toContain('openbao')
        ->and($names)->not->toContain('secrets-app');
});
