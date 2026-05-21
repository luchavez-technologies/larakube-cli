<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class CloudData extends Data
{
    public function __construct(
        public ?string $ip = null,
        public ?string $user = 'larakube',
        public ?int $port = 22,
        public ?string $key = null,
    ) {
        $this->key = $key ?? ($_SERVER['HOME'] ?? '').'/.ssh/id_rsa';
    }
}
