<?php

namespace App\Support\Templates;

use InvalidArgumentException;
use stdClass;

class CanonicalJson
{
    /**
     * Encode an array into canonical JSON matching LaraKube Cloud byte for byte.
     *
     * Lists preserve element order; associative arrays have their keys sorted
     * with SORT_STRING and encode as JSON objects; floats are rejected.
     *
     * @param  array<array-key, mixed>  $value
     */
    public static function encode(array $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new InvalidArgumentException('Floats are not allowed in canonical JSON.');
        }

        if ($value instanceof stdClass) {
            $value = (array) $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        ksort($value, SORT_STRING);

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[(string) $key] = self::normalize($item);
        }

        return (object) $normalized;
    }
}
