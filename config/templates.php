<?php

return [

    'cloud_url' => env('LARAKUBE_CLOUD_URL', 'https://cloud.larakube.app'),

    'ca_bundle' => env('LARAKUBE_CLOUD_CA'),

    'trusted_keys' => [
        // key_id => base64 Ed25519 public key (added by release engineering)
    ],

    'dev_key' => env('LARAKUBE_TEMPLATE_DEV_KEY'),

    // Apps whose templates come from LaraKube Cloud instead of this binary. Only these may be
    // downloaded when a template is missing; every other app stays fully offline.
    'downloadable' => ['openbao', 'external-dns'],

];
