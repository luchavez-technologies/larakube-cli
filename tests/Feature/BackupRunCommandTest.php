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
        '*get deployment -n larakube-shared -o jsonpath*' => Process::result(output: 'git-forgejo-git-luchtech-dev git-forgejo-runner-git-luchtech-dev drive-ocis stalwart chat-synapse chat-cinny chat-coturn chat-synapse-db webmail-bulwark monitor-grafana-monitor-luchtech-dev prometheus-server loki'),
        '*get deployment -n larakube-vault -o jsonpath*' => Process::result(output: 'passwords-vaultwarden-vault-luchtech-dev'),
        '*get deployment -n larakube-secrets -o jsonpath*' => Process::result(output: 'openbao-backend'),
        '*get deployment -n larakube-sso -o jsonpath*' => Process::result(output: 'sso-zitadel'),
        '*get deployment -n larakube-vpn -o jsonpath*' => Process::result(output: 'vpn-management'),
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
    // Flattened: a component can now archive several files from one mount.
    $paths = array_merge(...array_column($cmd->targets(), 'paths'));

    // Prometheus is the largest volume on the cluster and the least valuable —
    // metrics history, rebuildable by waiting. Backing it up would quadruple
    // every archive for nothing.
    expect($names)->not->toContain('prometheus')
        // 59 bytes, and losing it permanently breaks federation and every
        // existing device session.
        ->and($names)->toContain('chat-synapse')
        ->and($paths)->toContain('/data/chat.luchtech.dev.signing.key')
        // The object store holds chat media, git LFS, notes, signed documents.
        // Plex Commons infrastructure, so it keeps its explicit name.
        ->and($names)->toContain('seaweedfs')
        // The Deployment's own name, which already IS {category}-{app}-{instance}
        // under ADR 0021. The hand-maintained legacy-name map is gone — archives
        // predating it were disposable and keeping a fallback would be exactly
        // the temporary compatibility code this repo refuses elsewhere.
        ->and($names)->toContain('drive-ocis')
        ->and($names)->toContain('passwords-vaultwarden-vault-luchtech-dev')
        ->and($names)->toContain('git-forgejo-git-luchtech-dev')
        // Still the unmigrated Deployment name; it becomes
        // secrets-openbao-{instance} the moment SecretTool adopts the
        // convention, with no separate rename here.
        ->and($names)->toContain('openbao-backend');

    // Only Synapse's own component is covered — its sibling Deployments
    // (Cinny, Coturn, the bundled --no-plex Postgres) never opted in, so
    // their bulk/rebuildable data stays excluded even though they're live
    // Deployments discovery sees just as clearly as Synapse itself.
    expect($names)->not->toContain('chat-cinny')
        ->and($names)->not->toContain('chat-coturn')
        ->and($names)->not->toContain('chat-db');
});

test('archive names are the Deployment name, so they cannot collide', function (): void {
    // Two instances of one tool used to derive the SAME archive name, and
    // backup:run writes every target to "{work}/vol-{name}.tar.gz" — so the
    // second silently overwrote the first inside the same backup and one
    // instance's data never reached the archive at all.
    Process::fake(backupRunFakes());

    $cmd = new class
    {
        use InteractsWithBackup;

        public function targets(): array
        {
            return $this->backupVolumeTargets('kubectl');
        }
    };

    $names = array_column($cmd->targets(), 'name');

    // Derived from the workload, never a hand-maintained alias.
    expect($names)->toContain('openbao-backend')
        ->and($names)->not->toContain('openbao')
        // A Deployment carrying an instance carries it into the archive name.
        ->and($names)->toContain('git-forgejo-git-luchtech-dev')
        ->and($names)->not->toContain('forgejo');

    // The whole point: no two targets can collide on a name.
    expect($names)->toHaveSameSize(array_unique($names));
});
