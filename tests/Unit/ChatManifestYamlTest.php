<?php

use Symfony\Component\Yaml\Yaml;

function chatManifest(array $overrides = []): string
{
    return view('k8s.chat.matrix', array_merge([
        'host' => 'chat.example.com',
        'appName' => 'Chat',
        'logoUrl' => '',
        'plexNamespace' => 'larakube-plex',
        'noPlex' => false,
        'vpnOnly' => false,
        'isLocal' => false,
        'proxied' => false,
        's3Endpoint' => 'http://seaweedfs.larakube-plex.svc.cluster.local:8333',
        's3Bucket' => 'chat-media',
        's3AccessKey' => 'seaweedfs',
        's3SecretKey' => 'seaweedfs',
        'dbName' => 'chat_matrix',
        'dbUser' => 'chat_matrix',
        'dbPassword' => 'db-secret',
        'registrationSecret' => 'reg-secret',
        'turnSecret' => 'turn-secret',
        'meetJwtUrl' => 'https://meet.example.com/jwt',
        'mediaPruneTimezone' => 'Asia/Manila',
        'hostPort' => true,
        'externalIp' => '203.0.113.10',
        'smtp' => null,
        'oidc' => null,
    ], $overrides))->render();
}

/** @return array<int, array<string, mixed>> */
function chatDocuments(string $rendered): array
{
    return array_map(
        fn (string $doc) => Yaml::parse($doc),
        array_values(array_filter(
            array_map('trim', preg_split('/^---$/m', $rendered)),
            fn (string $doc) => $doc !== '',
        )),
    );
}

test('chat manifest renders as valid multi-document YAML', function (): void {
    $documents = chatDocuments(chatManifest());

    expect($documents)->not->toBeEmpty();

    foreach ($documents as $document) {
        expect($document)->toBeArray()->and($document['kind'] ?? null)->not->toBeNull();
    }
});

test('every chat image is pinned to an explicit tag — never :latest', function (): void {
    $rendered = chatManifest();

    expect($rendered)
        ->toContain('image: matrixdotorg/synapse:v1.158.0')
        ->toContain('image: coturn/coturn:4.6.3-alpine')
        ->toContain('image: ghcr.io/cinnyapp/cinny:v4.12.3')
        ->not->toContain(':latest');
});

test('chat no longer ships an SFU — that belongs to the meet tool', function (): void {
    $rendered = chatManifest();

    // Both stacks hostPort 7881/7882, so on a single node they can never
    // coexist: leaving either here would make meet:init unschedulable.
    expect($rendered)
        ->not->toContain('chat-livekit')
        ->not->toContain('chat-lk-jwt')
        ->not->toContain('stripprefix')
        ->not->toContain('livekit-server')
        // Coturn stays: it backs Synapse's legacy 1:1 turn_uris, not the SFU.
        ->toContain('chat-coturn');
});

test('the synapse init container and runtime container run the same image', function (): void {
    $synapse = collect(chatDocuments(chatManifest()))
        ->first(fn (array $doc) => ($doc['kind'] ?? null) === 'Deployment'
            && ($doc['metadata']['name'] ?? null) === 'chat-synapse');

    $init = $synapse['spec']['template']['spec']['initContainers'][0]['image'];
    $runtime = $synapse['spec']['template']['spec']['containers'][0]['image'];

    expect($init)->toBe($runtime);
});

test('synapse enables the MSCs Element Call needs, with a delay ceiling and raised rate limits', function (): void {
    $config = collect(chatDocuments(chatManifest()))
        ->first(fn (array $doc) => ($doc['kind'] ?? null) === 'Secret'
            && ($doc['metadata']['name'] ?? null) === 'chat-synapse-config');

    $homeserver = Yaml::parse($config['stringData']['homeserver.yaml']);

    // Delayed events are what keep m.call.member alive; msc4140_enabled without
    // max_event_delay_duration makes Synapse reject every one of them.
    expect($homeserver['experimental_features']['msc4140_enabled'])->toBeTrue()
        ->and($homeserver['experimental_features']['msc3401_enabled'])->toBeTrue()
        ->and($homeserver['experimental_features']['msc3266_enabled'])->toBeTrue()
        ->and($homeserver['max_event_delay_duration'])->toBe('24h')
        ->and($homeserver['rc_message']['burst_count'])->toBe(30)
        ->and($homeserver['rc_delayed_event_mgmt']['burst_count'])->toBe(20)
        // `extra_well_known_client_content` is the option Synapse actually
        // reads. A `well_known:` block is silently ignored and serves a
        // focus-less well-known — "homeserver does not support calling".
        ->and($homeserver)->not->toHaveKey('well_known')
        ->and($homeserver['extra_well_known_client_content']['org.matrix.msc4143.rtc_foci'][0]['livekit_service_url'])
        ->toBe('https://meet.example.com/jwt');
});

test('the RTC experimental block is skipped entirely when Meet is not wired', function (): void {
    $config = collect(chatDocuments(chatManifest(['meetJwtUrl' => null])))
        ->first(fn (array $doc) => ($doc['kind'] ?? null) === 'Secret'
            && ($doc['metadata']['name'] ?? null) === 'chat-synapse-config');

    $homeserver = Yaml::parse($config['stringData']['homeserver.yaml']);

    expect($homeserver)->not->toHaveKey('experimental_features')
        ->and($homeserver)->not->toHaveKey('max_event_delay_duration');
});

test('every chat container declares a memory limit', function (): void {
    $deployments = collect(chatDocuments(chatManifest()))
        ->filter(fn (array $doc) => ($doc['kind'] ?? null) === 'Deployment'
            && str_starts_with($doc['metadata']['name'], 'chat-'));

    expect($deployments)->not->toBeEmpty();

    foreach ($deployments as $deployment) {
        foreach ($deployment['spec']['template']['spec']['containers'] as $container) {
            expect($container['resources']['limits']['memory'] ?? null)
                ->not->toBeNull("{$deployment['metadata']['name']}/{$container['name']} has no memory limit");
        }
    }
});

test('the S3 prefix ends in a slash — the provider concatenates it without one', function (): void {
    // s3_storage_provider composes keys as `prefix + path`, so "media" produces
    // medialocal_content/… instead of a media/ folder. Dropping the slash is a
    // silent rename of every key, orphaning objects already in the bucket.
    $config = collect(chatDocuments(chatManifest()))
        ->first(fn (array $doc) => ($doc['kind'] ?? null) === 'Secret'
            && ($doc['metadata']['name'] ?? null) === 'chat-synapse-config');

    $prefix = Yaml::parse($config['stringData']['homeserver.yaml'])['media_storage_providers'][0]['config']['prefix'];

    expect($prefix)->toEndWith('/');
});

test('a media prune CronJob ships whenever S3 offload is on', function (): void {
    // Without pruning the offload COSTS storage: Synapse writes every file to
    // its own PVC and the provider writes a second copy to SeaweedFS, and both
    // PVCs are directories on the same block device. Two copies, one disk.
    $cron = collect(chatDocuments(chatManifest()))
        ->first(fn (array $doc) => ($doc['kind'] ?? null) === 'CronJob'
            && ($doc['metadata']['name'] ?? null) === 'chat-media-prune');

    expect($cron)->not->toBeNull();

    $spec = $cron['spec'];
    $script = $spec['jobTemplate']['spec']['template']['spec']['containers'][0]['command'][2];

    // --delete is the whole point; without it this job is a no-op that burns CPU.
    expect($script)->toContain('--delete')
        ->and($script)->toContain('update-db 30d')
        // Two prunes sharing one sqlite cache corrupt each other's upload view.
        ->and($spec['concurrencyPolicy'])->toBe('Forbid');
});

test('the prune retention window is configurable', function (): void {
    $script = collect(chatDocuments(chatManifest(['mediaRetention' => '7d'])))
        ->first(fn (array $doc) => ($doc['kind'] ?? null) === 'CronJob')['spec']['jobTemplate']['spec']['template']['spec']['containers'][0]['command'][2];

    expect($script)->toContain('update-db 7d');
});

test('no prune job when S3 offload is off — there is nothing to prune to', function (): void {
    $cron = collect(chatDocuments(chatManifest(['s3Bucket' => ''])))
        ->first(fn (array $doc) => ($doc['kind'] ?? null) === 'CronJob');

    expect($cron)->toBeNull();
});

test('synapse media offload uses the credentials it is handed, not a hardcoded literal', function (): void {
    $config = collect(chatDocuments(chatManifest([
        's3AccessKey' => 'larakube',
        's3SecretKey' => 'real-commons-secret',
    ])))->first(fn (array $doc) => ($doc['kind'] ?? null) === 'Secret'
        && ($doc['metadata']['name'] ?? null) === 'chat-synapse-config');

    $s3 = Yaml::parse($config['stringData']['homeserver.yaml'])['media_storage_providers'][0]['config'];

    expect($s3['access_key_id'])->toBe('larakube')
        ->and($s3['secret_access_key'])->toBe('real-commons-secret');
});

test('rotating the Commons S3 secret changes the synapse config-checksum', function (): void {
    $checksum = function (string $secret): string {
        $synapse = collect(chatDocuments(chatManifest(['s3SecretKey' => $secret])))
            ->first(fn (array $doc) => ($doc['kind'] ?? null) === 'Deployment'
                && ($doc['metadata']['name'] ?? null) === 'chat-synapse');

        return $synapse['spec']['template']['metadata']['annotations']['larakube.io/config-checksum'];
    };

    // Without this the Secret would be rewritten but the pod never restarted,
    // so the new credentials would sit unused and media offload stay broken.
    expect($checksum('secret-before'))->not->toBe($checksum('secret-after'));
});

test('the media path pods carry no CPU limit — throttling a relay drops calls', function (): void {
    $documents = chatDocuments(chatManifest());

    foreach (['chat-coturn'] as $name) {
        $container = collect($documents)
            ->first(fn (array $doc) => ($doc['kind'] ?? null) === 'Deployment'
                && ($doc['metadata']['name'] ?? null) === $name)['spec']['template']['spec']['containers'][0];

        expect($container['resources']['limits'] ?? [])->not->toHaveKey('cpu')
            ->and($container['resources']['requests']['cpu'] ?? null)->not->toBeNull();
    }
});

test('the media prune CronJob pins a timezone, like every other scheduled job', function (): void {
    // Without timeZone, Kubernetes reads 02:41 in the controller-manager's zone
    // — UTC almost everywhere — which is 10:41 in Manila. A maintenance job
    // that deletes local media has no business running mid-morning.
    $cron = collect(chatDocuments(chatManifest()))
        ->first(fn (array $doc) => ($doc['kind'] ?? null) === 'CronJob');

    expect($cron['spec']['timeZone'])->toBe('Asia/Manila')
        // And it must not collide with the backup, which writes the same
        // SeaweedFS volume this job uploads into.
        ->and($cron['spec']['schedule'])->toBe('41 2 * * *');
});
