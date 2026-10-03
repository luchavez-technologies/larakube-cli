<?php

use Symfony\Component\Yaml\Yaml;

test('vpn shared manifest renders as valid multi-document YAML', function (): void {
    $rendered = view('k8s.vpn.shared', ['host' => 'vpn.example.com', 'noPlex' => false,
        'plexNamespace' => 'larakube-plex',
        'storeDb' => 'netbird',
        'ssoDomain' => 'example.com'])->render();

    $documents = array_values(array_filter(
        array_map('trim', preg_split('/^---$/m', $rendered)),
        fn (string $doc) => $doc !== '',
    ));

    expect($documents)->not->toBeEmpty();

    $kinds = [];
    foreach ($documents as $document) {
        $parsed = Yaml::parse($document);
        expect($parsed)->toBeArray()->and($parsed['kind'] ?? null)->not->toBeNull();
        $kinds[] = $parsed['kind'].'/'.$parsed['metadata']['name'];
    }

    // Instance-suffixed, because that is what ships: the templates derive
    // `vpn-example-com` from the host they are handed, exactly as tool:init --tool=netbird
    // does. A bare `netbird` here would assert a name nothing ever creates.
    expect($kinds)->toContain('Deployment/netbird-vpn-example-com')
        ->toContain('Deployment/netbird-signal-vpn-example-com')
        ->toContain('Deployment/netbird-relay-vpn-example-com')
        ->toContain('Ingress/netbird-vpn-example-com');
});

test('vpn ingress requests a real ACME cert for a cloud install, never a local one', function (): void {
    $cloud = view('k8s.vpn.shared', ['host' => 'vpn.example.com', 'isLocal' => false, 'noPlex' => false,
        'plexNamespace' => 'larakube-plex',
        'storeDb' => 'netbird',
        'ssoDomain' => 'example.com'])->render();
    $local = view('k8s.vpn.shared', ['host' => 'vpn.dev.test', 'isLocal' => true, 'noPlex' => false,
        'plexNamespace' => 'larakube-plex',
        'storeDb' => 'netbird',
        'ssoDomain' => 'dev.test'])->render();

    expect($cloud)->toContain('traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt')
        ->and($local)->not->toContain('router.tls.certresolver');
});

test('vpn management and signal Services both request h2c backend proxying — both serve gRPC', function (): void {
    $rendered = view('k8s.vpn.shared', ['host' => 'vpn.example.com', 'noPlex' => false,
        'plexNamespace' => 'larakube-plex',
        'storeDb' => 'netbird',
        'ssoDomain' => 'example.com'])->render();

    $documents = array_values(array_filter(
        array_map('trim', preg_split('/^---$/m', $rendered)),
        fn (string $doc) => $doc !== '',
    ));

    foreach (['netbird-vpn-example-com', 'netbird-signal-vpn-example-com'] as $name) {
        $service = collect($documents)
            ->map(fn (string $doc) => Yaml::parse($doc))
            ->first(fn (array $doc) => ($doc['kind'] ?? null) === 'Service' && ($doc['metadata']['name'] ?? null) === $name);

        expect($service)->not->toBeNull("Service/{$name} not found in the rendered manifest")
            ->and($service['metadata']['annotations']['traefik.ingress.kubernetes.io/service.serversscheme'] ?? null)->toBe('h2c');
    }
});

test('vpn client manifest renders as valid multi-document YAML wired to the bootstrapped setup key', function (): void {
    $rendered = view('k8s.vpn.client', ['instance' => 'vpn-example-com'])->render();

    $documents = array_values(array_filter(
        array_map('trim', preg_split('/^---$/m', $rendered)),
        fn (string $doc) => $doc !== '',
    ));

    $deployment = collect($documents)
        ->map(fn (string $doc) => Yaml::parse($doc))
        ->first(fn (array $doc) => ($doc['kind'] ?? null) === 'Deployment');

    expect($deployment['metadata']['name'])->toBe('netbird-client-vpn-example-com');

    $env = $deployment['spec']['template']['spec']['containers'][0]['env'];
    $setupKeyEnv = collect($env)->firstWhere('name', 'NB_SETUP_KEY');

    expect($setupKeyEnv['valueFrom']['secretKeyRef'])->toBe([
        'name' => 'netbird-secrets-vpn-example-com',
        'key' => 'setup-key',
    ]);
});

test('the ingress routes every management-owned path explicitly, not via the catch-all', function (): void {
    // `/` now belongs to the dashboard. Any management path that falls through
    // to it breaks peer connectivity silently — the gRPC prefixes carry the
    // client's control channel and /oauth2 is the embedded IdP's issuer.
    foreach ([true, false] as $dashboard) {
        $rendered = view('k8s.vpn.ingress', ['host' => 'vpn.example.com', 'isLocal' => false, 'dashboard' => $dashboard])->render();

        foreach ([
            '/management.ManagementService/',
            '/management.ProxyService/',
            '/api',
            '/oauth2',
        ] as $path) {
            expect($rendered)->toContain("path: {$path}");
        }
    }
});

test('the dashboard authenticates against the embedded IdP, not the external one', function (): void {
    // NetBird 0.77's supported topology: the dashboard logs in against embedded
    // Dex using its static `netbird-dashboard` public client, and Dex federates
    // to whatever /api/identity-providers has registered. Pointing the dashboard
    // straight at the external IdP is the retired standalone setup.
    $rendered = view('k8s.vpn.shared', [
        'host' => 'vpn.example.com',
        'isLocal' => false,
        'noPlex' => false,
        'plexNamespace' => 'larakube-plex',
        'storeDb' => 'netbird',
        'ssoDomain' => 'example.com',
    ])->render();

    expect($rendered)->toContain('value: "https://vpn.example.com/oauth2"')
        ->and($rendered)->toContain('value: "netbird-dashboard"')
        // A public client has no secret, and setting one makes the SPA attempt
        // client_secret_basic at the token endpoint.
        ->and($rendered)->not->toContain('AUTH_CLIENT_SECRET');
});

test('management.json always uses the embedded IdP and never an HttpConfig block', function (): void {
    // The two are mutually exclusive ("HttpConfig is ignored when EmbeddedIdP is
    // enabled"), and shipping both silently leaves login working while every
    // dashboard API call fails.
    $config = json_decode(view('k8s.vpn.management-config', [
        'host' => 'vpn.example.com',
        'relaySecret' => 'rs',
        'dataStoreEncryptionKey' => 'dk',
    ])->render(), true);

    expect($config)->toBeArray()->not->toHaveKey('HttpConfig')
        ->and($config['EmbeddedIdP']['Enabled'])->toBeTrue()
        ->and($config['EmbeddedIdP']['Issuer'])->toBe('https://vpn.example.com/oauth2');
});

test('the ingress always routes / to the dashboard', function (): void {
    $rendered = view('k8s.vpn.ingress', ['host' => 'vpn.example.com', 'isLocal' => false])->render();

    expect($rendered)->toContain('name: netbird-dashboard');
});

test('the embedded IdP registers the dashboard redirect URI it will actually receive', function (): void {
    // Dex's static netbird-dashboard client only knows the URIs configured here.
    // A mismatch with AUTH_REDIRECT_URI fails at authorize with "Unregistered
    // redirect_uri", before any login form renders.
    $config = json_decode(view('k8s.vpn.management-config', [
        'host' => 'vpn.example.com',
        'relaySecret' => 'rs',
        'dataStoreEncryptionKey' => 'dk',
    ])->render(), true);

    $manifest = view('k8s.vpn.shared', [
        'host' => 'vpn.example.com',
        'isLocal' => false,
        'noPlex' => false,
        'plexNamespace' => 'larakube-plex',
        'storeDb' => 'netbird',
        'ssoDomain' => 'example.com',
    ])->render();

    // Verbatim from upstream's dashboard.env. Dedicated callback routes, NOT
    // real app routes — /peers collides with the app's own router.
    expect($config['EmbeddedIdP']['DashboardRedirectURIs'])
        ->toBe(['https://vpn.example.com/nb-auth', 'https://vpn.example.com/nb-silent-auth'])
        ->and($manifest)->toContain('value: "/nb-auth"')
        ->and($manifest)->toContain('value: "/nb-silent-auth"')
        ->and($manifest)->not->toContain('value: "/peers"');
});
