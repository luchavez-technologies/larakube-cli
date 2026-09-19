<?php

namespace App\Data;

/**
 * The outcome of one kubectl call.
 */
final readonly class KubectlResult
{
    public function __construct(
        public bool $ok,
        public string $output,
        public string $error = '',
    ) {}

    /** The output decoded as JSON, or null when it isn't JSON. */
    public function json(): ?array
    {
        $decoded = json_decode($this->output, true);

        return is_array($decoded) ? $decoded : null;
    }
}
