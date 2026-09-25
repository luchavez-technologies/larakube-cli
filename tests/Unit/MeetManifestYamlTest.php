<?php

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Traits\InteractsWithMeet;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

/** Every name the manifest renders, for the host the fixtures deploy at. */
function meetNames(): ToolInstance
{
    return ToolInstance::forHost(ClusterTool::MEET, 'meet.example.com');
}

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
            && ($doc['metadata']['name'] ?? null) === meetNames()->secret(App\Enums\SecretKind::CONFIG));

    return Yaml::parse($secret['stringData']['livekit.yaml']);
}

function meetChecksum(string $rendered): string
{
    $deployment = collect(meetDocuments($rendered))
        ->first(fn (array $doc) => ($doc['kind'] ?? null) === 'Deployment'
            && ($doc['metadata']['name'] ?? null) === meetNames()->deployment());

    return $deployment['spec']['template']['metadata']['annotations']['larakube.io/config-checksum'];
}

test('meet manifest renders as valid multi-document YAML with no consumers', function (): void {
    $documents = meetDocuments(meetManifest());

    expect($documents)->not->toBeEmpty();

    foreach ($documents as $document) {
        expect($document)->toBeArray()->and($document['kind'] ?? null)->not->toBeNull();
    }

    expect(meetLivekitConfig(meetManifest())['keys'])->toBe([]);
});

test('livekit-server refuses to boot on an empty keys map, so a persisted registry always has one', function (): void {
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

            return $this->writeMeetKeys('kubectl', meetNames(), $registry);
        }
    };

    $seeded = $command->seed([]);

    expect($seeded)->toHaveKey('_system')
        ->and($seeded['_system']['key'])->toStartWith('LK_')
        ->and($seeded['_system']['secret'])->not->toBeEmpty();
});

test('every registered consumer gets its own key in the LiveKit config', function (): void {
    $config = meetLivekitConfig(meetManifest([
        'chat' => meetConsumer('LK_chat', 'chatsecret', 'matrix-'),
        'speeddating' => meetConsumer('LK_dating', 'datingsecret', 'speeddating-'),
    ]));

    expect($config['keys'])->toBe([
        'LK_chat' => 'chatsecret',
        'LK_dating' => 'datingsecret',
    ]);
});

test('adding a consumer changes the config-checksum', function (): void {
    // Without this the Secret is rewritten but the pod never restarts, so
    // LiveKit keeps serving the old key set and rejects the new credentials.
    $before = meetChecksum(meetManifest(['chat' => meetConsumer('LK_chat', 'chatsecret', 'matrix-')]));
    $after = meetChecksum(meetManifest([
        'chat' => meetConsumer('LK_chat', 'chatsecret', 'matrix-'),
        'app' => meetConsumer('LK_app', 'appsecret', 'app-'),
    ]));

    expect($before)->not->toBe($after);
});

test('revoking a consumer changes the config-checksum', function (): void {
    $both = meetChecksum(meetManifest([
        'chat' => meetConsumer('LK_chat', 'chatsecret', 'matrix-'),
        'app' => meetConsumer('LK_app', 'appsecret', 'app-'),
    ]));
    $one = meetChecksum(meetManifest(['chat' => meetConsumer('LK_chat', 'chatsecret', 'matrix-')]));

    expect($both)->not->toBe($one);
});

test('registry ordering does not affect the checksum — an unrelated re-run must not roll the SFU', function (): void {
    $a = meetConsumer('LK_a', 'asecret', 'a-');
    $b = meetConsumer('LK_b', 'bsecret', 'b-');

    // Restarting LiveKit drops every live call, so the same set of consumers in
    // a different map order has to hash identically.
    expect(meetChecksum(meetManifest(['alpha' => $a, 'beta' => $b])))
        ->toBe(meetChecksum(meetManifest(['beta' => $b, 'alpha' => $a])));
});

test('the SFU has a memory limit but no CPU limit', function (): void {
    $container = collect(meetDocuments(meetManifest()))
        ->first(fn (array $doc) => ($doc['kind'] ?? null) === 'Deployment'
            && ($doc['metadata']['name'] ?? null) === meetNames()->deployment())['spec']['template']['spec']['containers'][0];

    expect($container['resources']['limits']['memory'])->not->toBeNull()
        ->and($container['resources']['limits'])->not->toHaveKey('cpu')
        ->and($container['resources']['requests']['cpu'])->not->toBeNull();
});

test('webhooks are wired for a single subscriber and signed with that consumer key', function (): void {
    $config = meetLivekitConfig(meetManifest([
        'chat' => meetConsumer('LK_chat', 'chatsecret', 'matrix-'),
        'app' => meetConsumer('LK_app', 'appsecret', 'app-', 'https://app.example.com/livekit/webhook'),
    ]));

    expect($config['webhook']['api_key'])->toBe('LK_app')
        ->and($config['webhook']['urls'])->toBe(['https://app.example.com/livekit/webhook']);
});

test('webhooks are omitted when two consumers want them — only one signing key exists', function (): void {
    // LiveKit signs with a single api_key, so a second subscriber could not
    // verify the payloads. Wiring both would ship silently unverifiable events.
    $config = meetLivekitConfig(meetManifest([
        'one' => meetConsumer('LK_one', 'onesecret', 'one-', 'https://one.example.com/hook'),
        'two' => meetConsumer('LK_two', 'twosecret', 'two-', 'https://two.example.com/hook'),
    ]));

    expect($config)->not->toHaveKey('webhook');
});

test('the ingress exposes the Matrix bridge only once it is wired', function (): void {
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

test('a cloud meet ingress requests a real ACME cert, a local one never does', function (): void {
    $render = fn (bool $isLocal) => view('k8s.meet.ingress', [
        'host' => 'meet.example.com',
        'isLocal' => $isLocal,
        'jwtWired' => false,
    ])->render();

    expect($render(false))->toContain('router.tls.certresolver: letsencrypt')
        ->and($render(true))->not->toContain('certresolver');
});

test('every Meet resource is named from its component, never the category', function (): void {
    $rendered = meetManifest()
        ."\n---\n".view('k8s.meet.ingress', [
            'host' => 'meet.example.com',
            'isLocal' => false,
            'jwtWired' => true,
        ])->render()
        ."\n---\n".view('k8s.meet.lk-jwt', [
            'meetHost' => 'meet.example.com',
            'chatHost' => 'chat.example.com',
            'livekitApiKey' => 'k',
            'livekitApiSecret' => 's',
        ])->render();

    $declared = array_map(fn (array $doc) => (string) $doc['metadata']['name'], meetDocuments($rendered));

    expect($declared)->toBe([
        'livekit-config-meet-example-com',
        'livekit-meet-example-com',
        'livekit-meet-example-com',
        'livekit-rtc-meet-example-com',
        'livekit-meet-example-com',
        'lk-jwt-stripprefix-meet-example-com',
        'lk-jwt-meet-example-com',
        'lk-jwt-meet-example-com',
    ]);
});

test('the Ingress references the bridge Middleware Traefik actually has', function (): void {
    // The annotation is namespace-qualified and instance-scoped; a literal here
    // would break the whole router the moment the Middleware's name moved.
    $ingress = collect(meetDocuments(view('k8s.meet.ingress', [
        'host' => 'meet.example.com',
        'isLocal' => false,
        'jwtWired' => true,
    ])->render()))->first();

    $middleware = collect(meetDocuments(view('k8s.meet.lk-jwt', [
        'meetHost' => 'meet.example.com',
        'chatHost' => 'chat.example.com',
        'livekitApiKey' => 'k',
        'livekitApiSecret' => 's',
    ])->render()))->first(fn (array $doc) => ($doc['kind'] ?? null) === 'Middleware');

    expect($ingress['metadata']['annotations']['traefik.ingress.kubernetes.io/router.middlewares'])
        ->toBe("{$middleware['metadata']['namespace']}-{$middleware['metadata']['name']}@kubernetescrd");
});
