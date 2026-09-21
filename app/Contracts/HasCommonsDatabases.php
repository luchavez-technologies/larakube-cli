<?php

namespace App\Contracts;

interface HasCommonsDatabases
{
    /** This vendor's own Commons Postgres tenant(s) — bare, un-instance-suffixed. @return list<string> */
    public function commonsDatabaseList(): array;

    /**
     * The same tenants named `{component}` (ADR 0021), for a tool that has
     * migrated. A database is one of the tool's resources like any other; the
     * data is carried over by renaming the database and its role as part of
     * the tool's migration.
     *
     * @return list<string>
     */
    public function canonicalDatabaseList(): array;
}
