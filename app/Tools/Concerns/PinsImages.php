<?php

namespace App\Tools\Concerns;

use LogicException;

/** image() for a HasImages tool: look the key up in images(), never guess. */
trait PinsImages
{
    public function image(string $key): string
    {
        return $this->images()[$key] ?? throw new LogicException(static::class." declares no '{$key}' image.");
    }
}
