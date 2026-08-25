<?php

use App\Traits\InteractsWithChat;
use Symfony\Component\Yaml\Yaml;

function masConfigRenderer(): object
{
    return new class
    {
        use InteractsWithChat;

        public function render(string $baseYaml, array $database, array $matrixTrust, array $upstream, string $masHost = 'mas.chat.example.com'): string
        {
            return $this->renderMasConfig($baseYaml, $database, $matrixTrust, $upstream, $masHost);
        }
    };
}

// Matches the real shape `mas-cli config generate` produces — including its
// own `http.issuer` key (nested INSIDE http:, a sibling of listeners/
// trusted_proxies, NOT a top-level key), hardcoded to the internal listener
// bind address. Confirmed live 2026-08-24 this exact nested field is what
// MAS's discovery document actually serves, independent of http.public_base.
const MAS_BASE_CONFIG = <<<'YAML'
http:
  listeners:
    - name: web
  issuer: http://[::]:8080/
secrets:
  encryption: "deadbeef"
  keys:
    - kid: abc123
YAML;

function masDatabaseFixture(array $overrides = []): array
{
    return array_merge([
        'host' => 'postgres.larakube-plex.svc.cluster.local',
        'user' => 'chat_mas',
        'password' => 'plainpassword',
        'database' => 'chat_mas',
    ], $overrides);
}

const MAS_MATRIX_TRUST_FIXTURE = ['homeserver' => 'chat.example.com', 'secret' => 'trust-secret'];
const MAS_UPSTREAM_FIXTURE = ['id' => 'provider-1', 'issuer' => 'https://sso.example.com', 'client_id' => 'cid', 'client_secret' => 'csecret'];

test('database uri includes the explicit :5432 port', function (): void {
    // Confirmed live 2026-08-24: a real Commons Postgres deploy hit
    // "could not parse database connection string ... invalid port
    // number" partly because this URI never included one — every other
    // postgresql:// URI in this repo does.
    $rendered = masConfigRenderer()->render(MAS_BASE_CONFIG, masDatabaseFixture(), MAS_MATRIX_TRUST_FIXTURE, MAS_UPSTREAM_FIXTURE);
    $parsed = Yaml::parse($rendered);

    expect($parsed['database']['uri'])->toBe('postgresql://chat_mas:plainpassword@postgres.larakube-plex.svc.cluster.local:5432/chat_mas');
});

test('a password with URI-special characters is percent-encoded, not glued in raw', function (): void {
    // Confirmed live 2026-08-24: an OpenBao-managed Commons password with
    // an unescaped special character (@, :, or similar) shifted where the
    // parser thought the host/port segment started, producing the exact
    // "invalid port number" error above. rawurlencode() on user/password
    // is the actual fix, not defensive styling.
    $nasty = 'p@ss:word/with#chars';
    $rendered = masConfigRenderer()->render(MAS_BASE_CONFIG, masDatabaseFixture(['password' => $nasty]), MAS_MATRIX_TRUST_FIXTURE, MAS_UPSTREAM_FIXTURE);
    $parsed = Yaml::parse($rendered);

    expect($parsed['database']['uri'])
        ->toContain(rawurlencode($nasty))
        ->not->toContain('p@ss:word/with#chars');

    // And the round trip actually recovers the real password — the whole
    // point of encoding it correctly rather than just obscuring it.
    $uriParts = parse_url($parsed['database']['uri']);
    expect(urldecode($uriParts['pass']))->toBe($nasty);
});

test('preserves secrets/http from the generated base config untouched', function (): void {
    // mas-cli config generate's own crypto material (secrets:) and
    // whatever it decided for http: must never be regenerated or
    // clobbered — regenerating them on a re-run would invalidate every
    // existing session/cookie.
    $rendered = masConfigRenderer()->render(MAS_BASE_CONFIG, masDatabaseFixture(), MAS_MATRIX_TRUST_FIXTURE, MAS_UPSTREAM_FIXTURE);
    $parsed = Yaml::parse($rendered);

    expect($parsed['secrets']['encryption'])->toBe('deadbeef')
        ->and($parsed['secrets']['keys'][0]['kid'])->toBe('abc123')
        ->and($parsed['http']['listeners'][0]['name'])->toBe('web');
});

test('matrix and upstream_oauth2 sections render correctly and matrix.endpoint targets Synapse', function (): void {
    $rendered = masConfigRenderer()->render(MAS_BASE_CONFIG, masDatabaseFixture(), MAS_MATRIX_TRUST_FIXTURE, MAS_UPSTREAM_FIXTURE);
    $parsed = Yaml::parse($rendered);

    expect($parsed['matrix']['homeserver'])->toBe('chat.example.com')
        ->and($parsed['matrix']['secret'])->toBe('trust-secret')
        // Cluster-internal Synapse Service — never the public host.
        ->and($parsed['matrix']['endpoint'])->toBe('http://chat-synapse:8008')
        ->and($parsed['upstream_oauth2']['providers'][0]['issuer'])->toBe('https://sso.example.com')
        ->and($parsed['upstream_oauth2']['providers'][0]['client_secret'])->toBe('csecret')
        ->and($parsed['upstream_oauth2']['providers'][0]['token_endpoint_auth_method'])->toBe('client_secret_basic');
});

test('http.public_base is injected so MAS builds correct absolute endpoint URLs, not its internal bind address', function (): void {
    // Confirmed live 2026-08-24: without this, MAS's authorization/token/
    // jwks/registration endpoint URLs all defaulted to MAS's internal
    // listener address (http://[::]:8080/...) instead of the real public
    // host.
    $rendered = masConfigRenderer()->render(MAS_BASE_CONFIG, masDatabaseFixture(), MAS_MATRIX_TRUST_FIXTURE, MAS_UPSTREAM_FIXTURE, 'mas.chat.luchtech.dev');
    $parsed = Yaml::parse($rendered);

    expect($parsed['http']['public_base'])->toBe('https://mas.chat.luchtech.dev/')
        ->and($parsed['http']['listeners'][0]['name'])->toBe('web');
});

test('re-rendering with a different masHost replaces the stale public_base, not duplicates it', function (): void {
    $once = masConfigRenderer()->render(MAS_BASE_CONFIG, masDatabaseFixture(), MAS_MATRIX_TRUST_FIXTURE, MAS_UPSTREAM_FIXTURE, 'mas.old.example.com');
    $twice = masConfigRenderer()->render($once, masDatabaseFixture(), MAS_MATRIX_TRUST_FIXTURE, MAS_UPSTREAM_FIXTURE, 'mas.new.example.com');

    expect(substr_count($twice, 'public_base:'))->toBe(1);

    $parsed = Yaml::parse($twice);
    expect($parsed['http']['public_base'])->toBe('https://mas.new.example.com/');
});

test('http.issuer is overridden to the real public host, not left at the generated internal bind address', function (): void {
    // Confirmed live 2026-08-24: this nested field (inside http:, NOT a
    // top-level key) is what MAS's OIDC discovery document and
    // org.matrix.msc2965.authentication (fed into Synapse's
    // .well-known/matrix/client) actually serve as `issuer` — setting
    // http.public_base alone was NOT enough, MAS kept serving this
    // untouched nested field verbatim. Element X reads it to discover MAS
    // and silently falls back to legacy password login when it resolves
    // to an unreachable address like http://[::]:8080/.
    $rendered = masConfigRenderer()->render(MAS_BASE_CONFIG, masDatabaseFixture(), MAS_MATRIX_TRUST_FIXTURE, MAS_UPSTREAM_FIXTURE, 'mas.chat.luchtech.dev');
    $parsed = Yaml::parse($rendered);

    expect($parsed['http']['issuer'])->toBe('https://mas.chat.luchtech.dev/')
        ->and($parsed)->not->toHaveKey('issuer');
});

test('re-rendering with a different masHost replaces the stale http.issuer, not duplicates it', function (): void {
    $once = masConfigRenderer()->render(MAS_BASE_CONFIG, masDatabaseFixture(), MAS_MATRIX_TRUST_FIXTURE, MAS_UPSTREAM_FIXTURE, 'mas.old.example.com');
    $twice = masConfigRenderer()->render($once, masDatabaseFixture(), MAS_MATRIX_TRUST_FIXTURE, MAS_UPSTREAM_FIXTURE, 'mas.new.example.com');

    // Exactly 2-space indent to isolate http.issuer from the unrelated,
    // 6-space-indented upstream_oauth2.providers[].issuer field, which also
    // legitimately contains the substring "issuer:".
    expect(substr_count($twice, "\n  issuer:"))->toBe(1);

    $parsed = Yaml::parse($twice);
    expect($parsed['http']['issuer'])->toBe('https://mas.new.example.com/');
});

test('re-rendering against the previously-broken live config cleans up the stray top-level issuer key too', function (): void {
    // Simulates the actual live production secret state after the first
    // (buggy) fix attempt: http.issuer still stale, plus a stray unused
    // top-level `issuer:` key from that attempt. A re-render must recover
    // to a clean state, not accumulate garbage keys forever.
    $brokenLiveConfig = MAS_BASE_CONFIG."\n".'issuer: "https://mas.chat.luchtech.dev/"'."\n";

    $rendered = masConfigRenderer()->render($brokenLiveConfig, masDatabaseFixture(), MAS_MATRIX_TRUST_FIXTURE, MAS_UPSTREAM_FIXTURE, 'mas.chat.luchtech.dev');
    $parsed = Yaml::parse($rendered);

    expect($parsed['http']['issuer'])->toBe('https://mas.chat.luchtech.dev/')
        ->and($parsed)->not->toHaveKey('issuer');
});

test('upstream provider carries the "oidc-" prefixed synapse_idp_id so syn2mas can link existing Zitadel-authenticated users', function (): void {
    // Confirmed live 2026-08-24, two attempts: `syn2mas check` refused to
    // proceed without this field at all, and then AGAIN refused when it
    // was set to the bare 'zitadel' value (matching oidc-providers-block
    // .blade.php's idp_id literally) — Synapse internally prefixes every
    // OIDC-type auth provider with "oidc-" when storing it as
    // `auth_provider` in user_external_ids, confirmed via `mas-cli config
    // generate --synapse-config <path>`, which reads Synapse's own config
    // and produces this exact prefixed value.
    $parsed = Yaml::parse(masConfigRenderer()->render(MAS_BASE_CONFIG, masDatabaseFixture(), MAS_MATRIX_TRUST_FIXTURE, MAS_UPSTREAM_FIXTURE));

    expect($parsed['upstream_oauth2']['providers'][0]['synapse_idp_id'])->toBe('oidc-zitadel');
});

test('passwords section is forced to bcrypt with unicode normalization for imported Synapse hashes', function (): void {
    // Confirmed live 2026-08-24: mas-cli config generate's own default
    // password scheme is NOT bcrypt, which syn2mas requires to import
    // Synapse's existing local-password account hashes (chat:user creates
    // real local-password accounts, not just SSO-linked ones) — leaving it
    // as generated would have silently locked out every non-SSO account.
    $parsed = Yaml::parse(masConfigRenderer()->render(MAS_BASE_CONFIG, masDatabaseFixture(), MAS_MATRIX_TRUST_FIXTURE, MAS_UPSTREAM_FIXTURE));

    expect($parsed['passwords']['enabled'])->toBeTrue()
        ->and($parsed['passwords']['schemes'][0])->toBe([
            'version' => 1,
            'algorithm' => 'bcrypt',
            'unicode_normalization' => true,
        ]);
});

test('re-rendering against an already-patched config replaces the stale database/matrix/upstream_oauth2/passwords blocks, not duplicates them', function (): void {
    $once = masConfigRenderer()->render(MAS_BASE_CONFIG, masDatabaseFixture(), MAS_MATRIX_TRUST_FIXTURE, MAS_UPSTREAM_FIXTURE);
    $twice = masConfigRenderer()->render($once, masDatabaseFixture(['password' => 'rotated']), MAS_MATRIX_TRUST_FIXTURE, MAS_UPSTREAM_FIXTURE);

    expect(substr_count($twice, 'database:'))->toBe(1)
        ->and(substr_count($twice, 'upstream_oauth2:'))->toBe(1)
        ->and(substr_count($twice, 'passwords:'))->toBe(1);

    $parsed = Yaml::parse($twice);
    expect($parsed['database']['uri'])->toContain('rotated')
        ->and($parsed['secrets']['encryption'])->toBe('deadbeef');
});

test('adminapi resource is enabled on the web listener for Element Admin integration', function (): void {
    $baseWithAssets = <<<'YAML'
http:
  listeners:
    - name: web
      resources:
        - name: discovery
        - name: assets
YAML;

    $rendered = masConfigRenderer()->render($baseWithAssets, masDatabaseFixture(), MAS_MATRIX_TRUST_FIXTURE, MAS_UPSTREAM_FIXTURE);
    $parsed = Yaml::parse($rendered);

    $resourceNames = array_column($parsed['http']['listeners'][0]['resources'], 'name');
    expect($resourceNames)->toContain('adminapi');
});
