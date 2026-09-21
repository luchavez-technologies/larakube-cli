<?php

namespace App\Contracts;

interface HasCommonsBuckets
{
    /** This vendor's own Commons S3 bucket(s) — bare, un-instance-suffixed. @return list<string> */
    public function commonsBucketList(): array;

    /**
     * The same buckets named `{component}-{token}` (ADR 0021), for a tool that
     * has migrated. A bucket is one of the tool's resources like any other, so
     * it follows the convention; the data it holds is copied across as part of
     * the tool's migration, which is what makes the rename safe.
     *
     * @return list<string>
     */
    public function canonicalBucketList(): array;
}
