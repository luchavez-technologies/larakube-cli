<?php

return [

    'cloud_url' => env('LARAKUBE_CLOUD_URL'),

    'ca_bundle' => env('LARAKUBE_CLOUD_CA'),

    'trusted_keys' => [
        'production-2026-11' => 'g7nQmcMiT3Gas/+VWnJR2PU6nrU5gtPYGJUUm5VG4JE=',
    ],

    'staging_keys' => [
        'staging-2026-11' => 'KWbj/BrMS5Uk3heeUeZxpRsk5O5dc6xRkKtOYmk382g=',
    ],

    'dev_key' => env('LARAKUBE_TEMPLATE_DEV_KEY'),

    // Apps whose templates come from LaraKube Cloud instead of this binary. Only these may be
    // downloaded when a template is missing; every other app stays fully offline.
    'downloadable' => ['openbao', 'external-dns'],

];
