<?php

use App\Traits\InteractsWithChat;
use Symfony\Component\Yaml\Yaml;

function authConfigRenderer(): object
{
    return new class
    {
        use InteractsWithChat;

        public function render(string $yaml, ?array $smtp, ?array $oidc, ?array $mas = null): string
        {
            return $this->renderSynapseConfig($yaml, $smtp, $oidc, $mas);
        }
    };
}

const BASE_HOMESERVER_AUTH = <<<'YAML'
server_name: "chat.example.com"
report_stats: false
database:
  name: psycopg2

YAML;

const OIDC_FIXTURE = [
    'issuer' => 'https://sso.example.com',
    'client_id' => 'cid-1',
    'client_secret' => 'secret-1',
    'name' => 'Zitadel',
];

const MAS_FIXTURE = [
    'endpoint' => 'http://chat-mas:8080/',
    'secret' => 'trust-secret',
    'public_issuer' => 'https://mas.chat.example.com/',
];

test('mas mode renders matrix_authentication_service, never oidc_providers, even when both are somehow passed', function (): void {
    // The real safety invariant lives in ChatInitCommand (never computing
    // $mas when $oidc is non-null) — this pins the render-layer half: IF
    // both ever reached this method together, $mas must still win, since
    // Synapse cannot run both auth mechanisms for the same users at once.
    $parsed = Yaml::parse(authConfigRenderer()->render(BASE_HOMESERVER_AUTH, null, OIDC_FIXTURE, MAS_FIXTURE));

    expect($parsed)->toHaveKey('matrix_authentication_service')
        ->and($parsed)->not->toHaveKey('oidc_providers')
        ->and($parsed['matrix_authentication_service'])->toBe([
            'enabled' => true,
            'endpoint' => 'http://chat-mas:8080/',
            'secret' => 'trust-secret',
        ]);
});

test('classic oidc mode renders oidc_providers when mas is null', function (): void {
    $parsed = Yaml::parse(authConfigRenderer()->render(BASE_HOMESERVER_AUTH, null, OIDC_FIXTURE, null));

    expect($parsed)->toHaveKey('oidc_providers')
        ->and($parsed)->not->toHaveKey('matrix_authentication_service')
        ->and($parsed['oidc_providers'][0]['issuer'])->toBe('https://sso.example.com');
});

test('neither auth block renders when both are null', function (): void {
    $parsed = Yaml::parse(authConfigRenderer()->render(BASE_HOMESERVER_AUTH, null, null, null));

    expect($parsed)->not->toHaveKey('oidc_providers')
        ->and($parsed)->not->toHaveKey('matrix_authentication_service');
});

test('activating mas mode replaces an existing oidc_providers block, not appends alongside it', function (): void {
    $classic = authConfigRenderer()->render(BASE_HOMESERVER_AUTH, null, OIDC_FIXTURE, null);
    $parsed = Yaml::parse(authConfigRenderer()->render($classic, null, null, MAS_FIXTURE));

    expect($parsed)->toHaveKey('matrix_authentication_service')
        ->and($parsed)->not->toHaveKey('oidc_providers');
});

test('reverting from mas mode restores oidc_providers cleanly', function (): void {
    $masActive = authConfigRenderer()->render(BASE_HOMESERVER_AUTH, null, null, MAS_FIXTURE);
    $parsed = Yaml::parse(authConfigRenderer()->render($masActive, null, OIDC_FIXTURE, null));

    expect($parsed)->toHaveKey('oidc_providers')
        ->and($parsed)->not->toHaveKey('matrix_authentication_service');
});

test('rendering the auth block preserves unrelated config like database and smtp', function (): void {
    $smtp = ['host' => 'smtp.example.com', 'port' => '587', 'user' => 'u', 'password' => 'p', 'from' => 'noreply@example.com'];
    $parsed = Yaml::parse(authConfigRenderer()->render(BASE_HOMESERVER_AUTH, $smtp, null, MAS_FIXTURE));

    expect($parsed['server_name'])->toBe('chat.example.com')
        ->and($parsed['database']['name'])->toBe('psycopg2')
        ->and($parsed['email']['smtp_host'])->toBe('smtp.example.com')
        ->and($parsed['matrix_authentication_service']['enabled'])->toBeTrue();
});
