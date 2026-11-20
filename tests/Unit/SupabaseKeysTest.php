<?php

use App\Support\SupabaseKeys;

test('SupabaseKeys generates valid HS256 anon and service_role JWTs and required secrets', function (): void {
    $keys = SupabaseKeys::generate(1_700_000_000);

    expect($keys)->toHaveKeys([
        'jwt-secret',
        'anon-key',
        'service-role-key',
        'secret-key-base',
        'realtime-db-enc-key',
        'vault-enc-key',
        'pg-meta-crypto-key',
        'db-password',
        'dashboard-username',
        'dashboard-password',
        'publishable-key',
        'secret-key',
        's3-protocol-access-key-id',
        's3-protocol-access-key-secret',
    ]);

    foreach (['anon-key' => 'anon', 'service-role-key' => 'service_role'] as $key => $role) {
        $parts = explode('.', $keys[$key]);
        expect($parts)->toHaveCount(3);

        $header = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true);
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);

        expect($header)->toBe(['alg' => 'HS256', 'typ' => 'JWT'])
            ->and($payload['role'])->toBe($role)
            ->and($payload['iss'])->toBe('supabase')
            ->and($payload['iat'])->toBe(1_700_000_000)
            ->and($payload['exp'])->toBe(1_700_000_000 + 5 * 3600 * 24 * 365);

        $expectedSig = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$parts[0]}.{$parts[1]}", $keys['jwt-secret'], true)), '+/', '-_'), '=');
        expect($parts[2])->toBe($expectedSig);
    }

    expect($keys['publishable-key'])->toStartWith('sb_publishable_')
        ->and($keys['secret-key'])->toStartWith('sb_secret_')
        ->and($keys['realtime-db-enc-key'])->toHaveLength(16)
        ->and($keys['vault-enc-key'])->toHaveLength(32);
});

test('SupabaseKeys makes the sb_ key pair: ES256 tokens the public JWKS verifies, with the HS256 secret kept alongside', function (): void {
    $keys = SupabaseKeys::asymmetricKeys('the-jwt-secret', 1_700_000_000);

    $jwks = json_decode($keys['jwt-jwks'], true);
    $private = json_decode($keys['jwt-keys'], true);
    [$ec, $oct] = $jwks['keys'];

    expect($ec)->toMatchArray(['kty' => 'EC', 'crv' => 'P-256', 'alg' => 'ES256', 'key_ops' => ['verify']])->not->toHaveKey('d')
        ->and($oct)->toBe(['kty' => 'oct', 'k' => rtrim(strtr(base64_encode('the-jwt-secret'), '+/', '-_'), '='), 'alg' => 'HS256'])
        ->and($private[0])->toHaveKey('d')
        ->and($private[0]['kid'])->toBe($ec['kid']);

    // Verify the ES256 signature from the public JWK alone, as PostgREST would.
    $b64 = fn (string $v): string => (string) base64_decode(strtr($v, '-_', '+/').str_repeat('=', (4 - strlen($v) % 4) % 4));
    $point = "\x04".$b64($ec['x']).$b64($ec['y']);
    $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$point;
    $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64)."-----END PUBLIC KEY-----\n";

    foreach (['anon-key-asymmetric' => 'anon', 'service-role-key-asymmetric' => 'service_role'] as $name => $role) {
        [$header, $body, $signature] = explode('.', $keys[$name]);
        $raw = $b64($signature);
        $der = fn (string $int): string => "\x02".chr(strlen($int = (ord(ltrim($int, "\x00")[0] ?? "\x00") & 0x80 ? "\x00" : '').ltrim($int, "\x00"))).$int;
        $sequence = $der(substr($raw, 0, 32)).$der(substr($raw, 32));

        expect(json_decode($b64($header), true))->toBe(['alg' => 'ES256', 'typ' => 'JWT', 'kid' => $ec['kid']])
            ->and(json_decode($b64($body), true)['role'])->toBe($role)
            ->and(openssl_verify("{$header}.{$body}", "\x30".chr(strlen($sequence)).$sequence, $pem, OPENSSL_ALGO_SHA256))->toBe(1);
    }
});

test('SupabaseKeys makes sb_ keys in upstream\'s shape, checksum included', function (): void {
    $keys = SupabaseKeys::generate();

    foreach (['publishable-key' => 'sb_publishable_', 'secret-key' => 'sb_secret_'] as $name => $prefix) {
        expect($keys[$name])->toMatch('/^'.$prefix.'[A-Za-z0-9_-]{22}_[A-Za-z0-9_-]{8}$/');
        [$intermediate, $checksum] = [substr($keys[$name], 0, -9), substr($keys[$name], -8)];
        expect($checksum)->toBe(substr(rtrim(strtr(base64_encode(hash('sha256', 'supabase-self-hosted|'.$intermediate, true)), '+/', '-_'), '='), 0, 8));
    }
});
