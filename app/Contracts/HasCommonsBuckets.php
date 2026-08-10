<?php

namespace App\Contracts;

interface HasCommonsBuckets
{
    /** This vendor's own Commons S3 bucket(s) — bare, un-instance-suffixed. @return list<string> */
    public function commonsBucketList(): array;
}
