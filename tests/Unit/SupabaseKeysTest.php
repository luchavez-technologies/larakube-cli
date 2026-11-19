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
        ->and(strlen($keys['realtime-db-enc-key']))->toBe(16)
        ->and(strlen($keys['vault-enc-key']))->toBe(32);
});
