<?php

use App\Enums\ClusterTool;
use Symfony\Component\Yaml\Yaml;

function renderOcisManifest(array $overrides = []): string
{
    return view('k8s.drive.ocis', array_merge([
        'engine' => 'ocis',
        'host' => 'drive.example.com',
        'dbPassword' => null,
        'redisIndex' => null,
        's3Creds' => ['access' => 'aaa', 'secret' => 'sss'],
        'plexNamespace' => 'larakube-plex',
        'noPlex' => false,
        'vpnOnly' => false,
        'isLocal' => false,
    ], $overrides))->render();
}

test('ocis manifest renders as valid multi-document YAML', function (): void {
    $rendered = renderOcisManifest();

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

    expect($kinds)->toContain('Deployment/drive-ocis')
        ->toContain('ConfigMap/drive-ocis-csp')
        ->toContain('Service/drive-ocis')
        ->toContain('PersistentVolumeClaim/drive-ocis-storage')
        ->toContain('Ingress/drive-ocis');
});

test('ocis mounts a csp.yaml that lets the browser reach whichever OIDC issuer is wired', function (): void {
    // oCIS web auto-discovers the external IdP by fetching
    // {issuer}/.well-known/openid-configuration and exchanges tokens at
    // {issuer}/oauth/v2/token — both cross-origin fetches the proxy's default
    // connect-src blocks, killing every SSO login with a CSP Network Error
    // (confirmed live 2026-07-31). The ConfigMap mirrors the built-in default
    // CSP and interpolates the WIRED issuer (${OCIS_OIDC_ISSUER}) into
    // connect-src — oCIS env-expands config files, exactly like the official
    // Keycloak example interpolates ${KEYCLOAK_DOMAIN}. This must NOT be a
    // hardcoded sso.<domain> origin, because customers wire whatever IdP they
    // already have (Okta, Entra, their own Zitadel) into OCIS_OIDC_ISSUER.
    $documents = fn (string $rendered): array => array_map(
        fn (string $doc) => Yaml::parse($doc),
        array_values(array_filter(
            array_map('trim', preg_split('/^---$/m', $rendered)),
            fn (string $doc) => $doc !== '',
        )),
    );

    $configMap = collect($documents(renderOcisManifest()))
        ->first(fn (array $doc) => ($doc['kind'] ?? null) === 'ConfigMap' && ($doc['metadata']['name'] ?? null) === 'drive-ocis-csp');

    expect($configMap['data']['csp.yaml'] ?? null)
        ->toContain('connect-src:')
        // The origin follows the wired issuer — no hardcoded Zitadel host.
        ->toContain('\'${OCIS_OIDC_ISSUER}\'')
        ->not->toContain('sso.example.com/')
        ->toContain('https://raw.githubusercontent.com/owncloud/awesome-ocis/');

    $deployment = collect($documents(renderOcisManifest()))
        ->first(fn (array $doc) => ($doc['kind'] ?? null) === 'Deployment' && ($doc['metadata']['name'] ?? null) === 'drive-ocis');

    $container = $deployment['spec']['template']['spec']['containers'][0];
    $cspEnv = collect($container['env'])->first(fn (array $env) => $env['name'] === 'PROXY_CSP_CONFIG_FILE_LOCATION');

    expect($cspEnv['value'])->toBe('/etc/ocis/csp.yaml')
        ->and($container['volumeMounts'])->toContain([
            'name' => 'drive-ocis-csp',
            'mountPath' => '/etc/ocis/csp.yaml',
            'subPath' => 'csp.yaml',
            'readOnly' => true,
        ])
        ->and($deployment['spec']['template']['spec']['volumes'])->toContain([
            'name' => 'drive-ocis-csp',
            'configMap' => ['name' => 'drive-ocis-csp'],
        ]);
});

test('ocis CSP is identical across hosts — the wired issuer, not the drive subdomain, decides the origin', function (): void {
    // The csp.yaml must not bake in the tool's own host (a prior version
    // derived "sso.<domain>" by swapping the drive subdomain). That breaks the
    // core product promise: sso:wire points the tool at WHATEVER IdP the
    // customer already has, so the CSP has to follow OCIS_OIDC_ISSUER rather
    // than any host-derived assumption.
    $documents = fn (string $rendered): array => array_map(
        fn (string $doc) => Yaml::parse($doc),
        array_values(array_filter(
            array_map('trim', preg_split('/^---$/m', $rendered)),
            fn (string $doc) => $doc !== '',
        )),
    );

    $cspYaml = fn (string $rendered): string => collect($documents($rendered))
        ->first(fn (array $doc) => ($doc['kind'] ?? null) === 'ConfigMap' && ($doc['metadata']['name'] ?? null) === 'drive-ocis-csp')['data']['csp.yaml'];

    $a = $cspYaml(renderOcisManifest(['host' => 'drive.example.com']));
    $b = $cspYaml(renderOcisManifest(['host' => 'drive.luchtech.dev']));

    expect($a)->toBe($b)
        ->and($a)->toContain('\'${OCIS_OIDC_ISSUER}\'')
        ->and($a)->not->toContain('sso.example.com/')
        ->and($a)->not->toContain('sso.luchtech.dev/');
});

test('ocis S3 mode drives user blobs through the Plex SeaweedFS bucket via the s3ng driver', function (): void {
    $rendered = renderOcisManifest();

    expect($rendered)->toContain('name: STORAGE_USERS_DRIVER')
        ->and($rendered)->toContain('value: "s3ng"')
        ->and($rendered)->toContain('http://seaweedfs.larakube-plex.svc.cluster.local:8333')
        ->and($rendered)->toContain('value: "drive-ocis"')
        // The old vars silently kept the default `ocis` driver — remove them so
        // nobody mistakes them for a working S3 setup again.
        ->and($rendered)->not->toContain('OCIS_DEFAULT_STORAGE_SYSTEM')
        ->and($rendered)->not->toContain('STORAGE_SYSTEM_S3_ENDPOINT');
});

test('ocis mounts the storage PVC even in Plex S3 mode so metadata survives restarts', function (): void {
    $deployment = collect(
        array_map(
            fn (string $doc) => Yaml::parse($doc),
            array_values(array_filter(
                array_map('trim', preg_split('/^---$/m', renderOcisManifest())),
                fn (string $doc) => $doc !== '',
            )),
        ),
    )->first(fn (array $doc) => ($doc['kind'] ?? null) === 'Deployment' && ($doc['metadata']['name'] ?? null) === 'drive-ocis');

    expect($deployment['spec']['template']['spec']['containers'][0]['volumeMounts'])->toContain([
        'name' => 'drive-ocis-data',
        'mountPath' => '/var/lib/ocis',
    ])->and($deployment['spec']['template']['spec']['volumes'][0]['persistentVolumeClaim']['claimName'])->toBe('drive-ocis-storage');
});

test('ocis cloud ingress requests a real ACME certificate via Traefik\'s certresolver', function (): void {
    $documents = fn (string $rendered): array => array_map(
        fn (string $doc) => Yaml::parse($doc),
        array_values(array_filter(
            array_map('trim', preg_split('/^---$/m', $rendered)),
            fn (string $doc) => $doc !== '',
        )),
    );

    $ingress = fn (string $rendered): array => collect($documents($rendered))
        ->first(fn (array $doc) => ($doc['kind'] ?? null) === 'Ingress' && ($doc['metadata']['name'] ?? null) === 'drive-ocis');

    $cloud = $ingress(renderOcisManifest(['isLocal' => false]));
    $local = $ingress(renderOcisManifest(['isLocal' => true]));

    expect($cloud['metadata']['annotations']['traefik.ingress.kubernetes.io/router.tls.certresolver'])->toBe('letsencrypt')
        // cert-manager is not installed on LaraKube clusters; this stale
        // annotation was silently ignored and Traefik served its default
        // *.dev.test cert on production.
        ->and($cloud['metadata']['annotations'])->not->toHaveKey('cert-manager.io/cluster-issuer')
        // TLS is managed by Traefik's certresolver, not a hand-rolled Secret.
        ->and($cloud['spec']['tls'][0])->toHaveKey('hosts')->not->toHaveKey('secretName')
        ->and($local['metadata']['annotations'])->not->toHaveKey('traefik.ingress.kubernetes.io/router.tls.certresolver');
});

test('ocis manifest enables Basic auth and app auth for WebDAV clients', function (): void {
    // oCIS 8 ships with PROXY_ENABLE_BASIC_AUTH=false: username/password requests
    // to every protected route silently 401 (confirmed live 2026-07-31 against the
    // proxy config: EnableBasicAuth=false, AllowAppAuth=false). WebDAV clients and
    // curl need Basic auth; app auth adds scoped app passwords for 3rd-party tools.
    $rendered = renderOcisManifest();

    expect($rendered)->toContain('name: PROXY_ENABLE_BASIC_AUTH')
        ->and($rendered)->toContain('value: "true"')
        ->and($rendered)->toContain('name: PROXY_ENABLE_APP_AUTH');
});

test('ocis manifest pins a stable OCIS_ADMIN_USER_ID so the IDM bootstraps the admin user', function (): void {
    // Without this the IDM only creates the system users (libregraph/idp/reva)
    // and every admin login fails with "Logon failed" — the idm server.go
    // bootstrap appends uid=admin ONLY when AdminUserID is non-empty.
    $rendered = renderOcisManifest();

    expect($rendered)->toContain('name: OCIS_ADMIN_USER_ID')
        ->and($rendered)->toContain('value: "e4f2a7c9-6d3b-4c1a-9f8e-2b5d7a1c3f90"');
});

test('ocis manifest wires the admin password from the drive-secrets Secret', function (): void {
    $deployment = collect(
        array_map(
            fn (string $doc) => Yaml::parse($doc),
            array_values(array_filter(
                array_map('trim', preg_split('/^---$/m', renderOcisManifest())),
                fn (string $doc) => $doc !== '',
            )),
        ),
    )->first(fn (array $doc) => ($doc['kind'] ?? null) === 'Deployment' && ($doc['metadata']['name'] ?? null) === 'drive-ocis');

    $ocisAdminPassword = collect($deployment['spec']['template']['spec']['containers'][0]['env'])
        ->first(fn (array $env) => $env['name'] === 'OCIS_ADMIN_PASSWORD');

    expect($ocisAdminPassword['valueFrom']['secretKeyRef'])->toMatchArray([
        'name' => 'drive-secrets',
        'key' => 'admin-password',
    ]);
});

test('ocis proxy verifies opaque access tokens against the IdP userinfo endpoint, not as JWTs', function (): void {
    // Zitadel issues opaque access tokens; oCIS's default jwt verify rejects
    // them with "token contains an invalid number of segments" on every API
    // call -> 401 -> the "Not logged in" page (confirmed live 2026-08-01 in the
    // proxy log after a successful Zitadel login). oCIS 8.0.6 only supports
    // 'none' and 'jwt' for PROXY_OIDC_ACCESS_TOKEN_VERIFY_METHOD; 'none' is the
    // documented method for opaque tokens (validated at the IdP's userinfo
    // endpoint server-side) and forbids PROXY_OIDC_SKIP_USER_INFO.
    $static = ClusterTool::DRIVE->oidcEnv()['static'];

    expect($static['PROXY_OIDC_ACCESS_TOKEN_VERIFY_METHOD'] ?? null)->toBe('none')
        // The token-verification method relies on a userinfo lookup; skipping
        // it is documented as incompatible with 'none'.
        ->and($static)->not->toHaveKey('PROXY_OIDC_SKIP_USER_INFO');
});
