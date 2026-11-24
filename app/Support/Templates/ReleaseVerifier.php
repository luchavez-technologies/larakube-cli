<?php

namespace App\Support\Templates;

class ReleaseVerifier
{
    /**
     * Verify an Ed25519 detached signature over the canonical JSON encoding of a release manifest.
     *
     * @param  array<string, mixed>  $manifest
     */
    public static function verify(array $manifest, string $signatureB64, string $publicKeyB64): bool
    {
        $signature = base64_decode($signatureB64, true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        $publicKey = base64_decode($publicKeyB64, true);
        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        $message = CanonicalJson::encode($manifest);

        return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
    }
}
