<?php

namespace App\Contracts;

/** Whitelabeling specification for a vendor that supports custom branding (app name / logo) via environment variables or Nginx sub_filter injection. */
interface HasWhiteLabel
{
    /** @return array{app_name_key?: string, logo_url_key?: string, sub_filter?: bool} */
    public function whiteLabel(): array;
}
