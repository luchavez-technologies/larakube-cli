<?php

use App\Services\Devbox\DevBoxBundle;

test('encrypts and decrypts a devbox payload successfully', function (): void {
    $payload = [
        'stack' => [
            'name' => 'test-box',
            'provider' => 'do',
            'ip' => '192.0.2.1',
            'role' => 'dev',
        ],
        'keys' => [
            'private' => '---TEST-KEY---',
            'public' => 'ssh-ed25519 AAAAC3... test@host',
        ],
    ];

    $passphrase = 'CorrectHorseBatteryStaple123!';
    $encrypted = DevBoxBundle::encrypt($payload, $passphrase);

    expect($encrypted)->toContain('larakube_bundle')
        ->toContain('pbkdf2-sha256')
        ->toContain('aes-256-gcm');

    $decrypted = DevBoxBundle::decrypt($encrypted, $passphrase);

    expect($decrypted)->toBe($payload);
});

test('decrypt throws exception with incorrect passphrase', function (): void {
    $payload = ['stack' => ['name' => 'test-box']];
    $encrypted = DevBoxBundle::encrypt($payload, 'correct-passphrase');

    expect(fn () => DevBoxBundle::decrypt($encrypted, 'wrong-passphrase'))
        ->toThrow(RuntimeException::class, 'Incorrect passphrase or corrupted bundle.');
});

test('decrypt throws exception on corrupted or invalid json', function (): void {
    expect(fn () => DevBoxBundle::decrypt('{"invalid": true}', 'passphrase'))
        ->toThrow(RuntimeException::class, 'Invalid or unrecognized DevBox bundle format.');
});

test('encrypt throws exception on empty passphrase', function (): void {
    expect(fn () => DevBoxBundle::encrypt(['stack' => []], ''))
        ->toThrow(RuntimeException::class, 'Passphrase cannot be empty.');
});
