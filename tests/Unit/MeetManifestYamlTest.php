<?php

use App\Traits\InteractsWithMeet;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

/** @param array<string, array<string, mixed>> $consumers */
function meetManifest(array $consumers = [], array $overrides = []): string
{
    return view('k8s.meet.livekit', array_merge([
        'host' => 'meet.example.com',
        'consumers' => $consumers,
        'hostPort' => true,
    ], $overrides))->render();
}

function meetConsumer(string $key, string $secret, string $prefix, ?string $webhook = null): array
{
    return ['key' => $key, 'secret' => $secret, 'roomPrefix' => $prefix, 'webhookUrl' => $webhook];
}

/** @return array<int, array<string, mixed>> */
function meetDocuments(string $rendered): array
{
    return array_map(
        fn (string $doc) => Yaml::parse($doc),
        array_values(array_filter(
            array_map('trim', preg_split('/^---$/m', $rendered)),
            fn (string $doc) => $doc !== '',
        )),
    );
}

function meetLivekitConfig(string $rendered): array
{
    $secret = collect(meetDocuments($rendered))
        ->first(fn (array $doc) => ($doc['kind'] ?? null) === 'Secret'
            && ($doc['metadata']['name'] ?? null) === 'meet-livekit-config');

    return Yaml::parse($secret['stringData']['livekit.yaml']);
}

function meetChecksum(string $rendered): string
{
    $deployment = collect(meetDocuments($rendered))
        ->first(fn (array $doc) => ($doc['kind'] ?? null) === 'Deployment'
            && ($doc['metadata']['name'] ?? null) === 'meet-livekit');

    return $deployment['spec']['template']['metadata']['annotations']['larakube.io/config-checksum'];
}

test('meet manifest renders as valid multi-document YAML with no consumers', function () {
    $documents = meetDocuments(meetManifest());

    expect($documents)->not->toBeEmpty();

    foreach ($documents as $document) {
        expect($document)->toBeArray()->and($document['kind'] ?? null)->not->toBeNull();
    }

    expect(meetLivekitConfig(meetManifest())['keys'])->toBe([]);
});

test('livekit-server refuses to boot on an empty keys map, so a persisted registry always has one', function () {
    // Verified against livekit/livekit-server:v1.13.5, which exits with
    // "one of key-file or keys must be provided". A registry emptied by the
    // last meet:unwire would otherwise CrashLoopBackOff the SFU.
    $command = new class
    {
        use InteractsWithMeet;

        /** @return array<string, mixed> */
        public function seed(array $registry): array
        {
            Process::fake();

            return $this->writeMeetKeys('kubectl', 'larakube-shared', $registry);
        }
    };

    $seeded = $command->seed([]);

    expect($seeded)->toHaveKey('_system')
        ->and($seeded['_system']['key'])->toStartWith('LK_')
        ->and($seeded['_system']['secret'])->not->toBeEmpty();
});

test('every registered consumer gets its own key in the LiveKit config', function () {
    $config = meetLivekitConfig(meetManifest([
        'chat' => meetConsumer('LK_chat', 'chatsecret', 'matrix-'),
        'speeddating' => meetConsumer('LK_dating', 'datingsecret', 'speeddating-'),
    ]));

    expect($config['keys'])->toBe([
        'LK_chat' => 'chatsecret',
        'LK_dating' => 'datingsecret',
    ]);
});

test('adding a consumer changes the config-checksum', function () {
    // Without this the Secret is rewritten but the pod never restarts, so
    // LiveKit keeps serving the old key set and rejects the new credentials.
    $before = meetChecksum(meetManifest(['chat' => meetConsumer('LK_chat', 'chatsecret', 'matrix-')]));
    $after = meetChecksum(meetManifest([
        'chat' => meetConsumer('LK_chat', 'chatsecret', 'matrix-'),
        'app' => meetConsumer('LK_app', 'appsecret', 'app-'),
    ]));

    expect($before)->not->toBe($after);
});

test('revoking a consumer changes the config-checksum', function () {
    $both = meetChecksum(meetManifest([
        'chat' => meetConsumer('LK_chat', 'chatsecret', 'matrix-'),
        'app' => meetConsumer('LK_app', 'appsecret', 'app-'),
    ]));
    $one = meetChecksum(meetManifest(['chat' => meetConsumer('LK_chat', 'chatsecret', 'matrix-')]));

    expect($both)->not->toBe($one);
});

test('registry ordering does not affect the checksum — an unrelated re-run must not roll the SFU', function () {
    $a = meetConsumer('LK_a', 'asecret', 'a-');
    $b = meetConsumer('LK_b', 'bsecret', 'b-');

    // Restarting LiveKit drops every live call, so the same set of consumers in
    // a different map order has to hash identically.
    expect(meetChecksum(meetManifest(['alpha' => $a, 'beta' => $b])))
        ->toBe(meetChecksum(meetManifest(['beta' => $b, 'alpha' => $a])));
});

test('the SFU has a memory limit but no CPU limit', function () {
    $container = collect(meetDocuments(meetManifest()))
        ->first(fn (array $doc) => ($doc['kind'] ?? null) === 'Deployment'
            && ($doc['metadata']['name'] ?? null) === 'meet-livekit')['spec']['template']['spec']['containers'][0];

    expect($container['resources']['limits']['memory'])->not->toBeNull()
        ->and($container['resources']['limits'])->not->toHaveKey('cpu')
        ->and($container['resources']['requests']['cpu'])->not->toBeNull();
});

test('webhooks are wired for a single subscriber and signed with that consumer key', function () {
    $config = meetLivekitConfig(meetManifest([
        'chat' => meetConsumer('LK_chat', 'chatsecret', 'matrix-'),
        'app' => meetConsumer('LK_app', 'appsecret', 'app-', 'https://app.example.com/livekit/webhook'),
    ]));

    expect($config['webhook']['api_key'])->toBe('LK_app')
        ->and($config['webhook']['urls'])->toBe(['https://app.example.com/livekit/webhook']);
});

test('webhooks are omitted when two consumers want them — only one signing key exists', function () {
    // LiveKit signs with a single api_key, so a second subscriber could not
    // verify the payloads. Wiring both would ship silently unverifiable events.
    $config = meetLivekitConfig(meetManifest([
        'one' => meetConsumer('LK_one', 'onesecret', 'one-', 'https://one.example.com/hook'),
        'two' => meetConsumer('LK_two', 'twosecret', 'two-', 'https://two.example.com/hook'),
    ]));

    expect($config)->not->toHaveKey('webhook');
});

test('the ingress exposes the Matrix bridge only once it is wired', function () {
    $paths = function (bool $wired): array {
        $ingress = Yaml::parse(view('k8s.meet.ingress', [
            'host' => 'meet.example.com',
            'isLocal' => false,
            'jwtWired' => $wired,
        ])->render());

        return array_column($ingress['spec']['rules'][0]['http']['paths'], 'path');
    };

    expect($paths(false))->toBe(['/'])
        ->and($paths(true))->toBe(['/jwt', '/']);
});

test('a cloud meet ingress requests a real ACME cert, a local one never does', function () {
    $render = fn (bool $isLocal) => view('k8s.meet.ingress', [
        'host' => 'meet.example.com',
        'isLocal' => $isLocal,
        'jwtWired' => false,
    ])->render();

    expect($render(false))->toContain('router.tls.certresolver: letsencrypt')
        ->and($render(true))->not->toContain('certresolver');
});
