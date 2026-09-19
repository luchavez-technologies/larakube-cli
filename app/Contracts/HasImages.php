<?php

namespace App\Contracts;

/**
 * A tool that declares every container image it runs, pinned, in one place.
 * Its templates read images from here instead of carrying their own tags, so
 * reviewing or bumping a version never means hunting through Blade files.
 */
interface HasImages
{
    /** @return array<string, string> key => fully qualified, version-pinned image */
    public function images(): array;

    /** The image registered under $key. */
    public function image(string $key): string;
}
