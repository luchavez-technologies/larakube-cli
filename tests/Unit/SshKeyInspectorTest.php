<?php

use App\Services\SshKeyInspector;
use Illuminate\Support\Facades\Process;

test('inspects unencrypted key correctly', function (): void {
    $tempKey = tempnam(sys_get_temp_dir(), 'test_key_unenc_');
    @unlink($tempKey);
    Process::run(['ssh-keygen', '-t', 'ed25519', '-N', '', '-f', $tempKey, '-q']);

    $inspector = new SshKeyInspector;
    expect($inspector->isEncrypted($tempKey))->toBeFalse()
        ->and($inspector->inspect($tempKey)['encrypted'])->toBeFalse();

    @unlink($tempKey);
    @unlink($tempKey.'.pub');
});

test('inspects encrypted key correctly and verifies passphrase', function (): void {
    $tempKey = tempnam(sys_get_temp_dir(), 'test_key_enc_');
    @unlink($tempKey);
    Process::run(['ssh-keygen', '-t', 'ed25519', '-N', 'secret123', '-f', $tempKey, '-q']);

    $inspector = new SshKeyInspector;
    expect($inspector->isEncrypted($tempKey))->toBeTrue()
        ->and($inspector->inspect($tempKey)['encrypted'])->toBeTrue()
        ->and($inspector->verifyPassphrase($tempKey, 'wrong'))->toBeFalse()
        ->and($inspector->verifyPassphrase($tempKey, 'secret123'))->toBeTrue();

    @unlink($tempKey);
    @unlink($tempKey.'.pub');
});

test('unlocks encrypted key to temporary unencrypted file', function (): void {
    $tempKey = tempnam(sys_get_temp_dir(), 'test_key_orig_');
    $unlockedKey = tempnam(sys_get_temp_dir(), 'test_key_unlocked_');
    @unlink($tempKey);
    @unlink($unlockedKey);

    Process::run(['ssh-keygen', '-t', 'ed25519', '-N', 'secret123', '-f', $tempKey, '-q']);

    $inspector = new SshKeyInspector;
    $success = $inspector->unlockToTemp($tempKey, 'secret123', $unlockedKey);

    expect($success)->toBeTrue()
        ->and($inspector->isEncrypted($unlockedKey))->toBeFalse();

    @unlink($tempKey);
    @unlink($tempKey.'.pub');
    @unlink($unlockedKey);
    @unlink($unlockedKey.'.pub');
});

test('derives public key directly from private key', function (): void {
    $tempKey = tempnam(sys_get_temp_dir(), 'test_key_pub_');
    @unlink($tempKey);
    Process::run(['ssh-keygen', '-t', 'ed25519', '-N', '', '-f', $tempKey, '-q']);

    $inspector = new SshKeyInspector;
    $pub = $inspector->derivePublicKey($tempKey);
    $diskPub = trim((string) @file_get_contents($tempKey.'.pub'));

    expect($pub)->not->toBeNull()
        ->and($pub)->toBe($diskPub);

    @unlink($tempKey);
    @unlink($tempKey.'.pub');
});
