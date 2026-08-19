<?php

use App\Traits\LaraKubeOutput;

function masker(): object
{
    return new class
    {
        use LaraKubeOutput;
    };
}

test('maskSecrets redacts high-confidence token shapes without touching ordinary text', function (): void {
    $m = masker();

    // Laravel APP_KEY
    expect($m->maskSecrets('APP_KEY=base64:'.str_repeat('A', 40)))
        ->not->toContain(str_repeat('A', 40))
        ->toContain('••••••');

    // GitHub token
    expect($m->maskSecrets('use ghp_'.str_repeat('a', 36).' to auth'))
        ->not->toContain('ghp_'.str_repeat('a', 36))
        ->toContain('••••••');

    // JWT / k8s ServiceAccount token
    $jwt = 'eyJhbGciOi.eyJzdWIiOi.SflKxwRJSM';
    expect($m->maskSecrets("token: {$jwt}"))->not->toContain($jwt);

    // Ordinary output is left exactly as-is.
    expect($m->maskSecrets('deploying app-one to production'))->toBe('deploying app-one to production');
});

test('registered secrets are redacted by exact match; trivial values are ignored', function (): void {
    $m = masker();
    $m->registerSecret('s3cr3t-Password-Value-123');

    expect($m->maskSecrets('the password is s3cr3t-Password-Value-123 ok'))
        ->not->toContain('s3cr3t-Password-Value-123')
        ->toContain('••••••');

    // Too-short values are never registered (so we don't redact everything).
    $m->registerSecret('abc');
    expect($m->maskSecrets('abc def'))->toBe('abc def');
});
