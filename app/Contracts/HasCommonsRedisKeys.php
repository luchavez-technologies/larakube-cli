<?php

namespace App\Contracts;

/** Commons Valkey/Redis logical indices this vendor allocates on wire and releases on teardown. */
interface HasCommonsRedisKeys
{
    /** @return list<string> */
    public function commonsRedisKeys(): array;
}
