<?php

namespace App\Contracts;

interface HasCommonsDatabases
{
    /** This vendor's own Commons Postgres tenant(s) — bare, un-instance-suffixed. @return list<string> */
    public function commonsDatabaseList(): array;
}
