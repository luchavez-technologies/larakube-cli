<?php

namespace App\Contracts;

/**
 * Whitelabeling specification for a vendor that supports custom branding
 * (app name / logo) via environment variables, Nginx sub_filter injection,
 * or its own Blade template variables baked directly into a rendered
 * ConfigMap/Secret (e.g. Chat's Element Web config.json, applied by
 * ChatInitCommand itself rather than any generic env/sub_filter mechanism).
 *
 * None of these keys are consumed by shared branding-application code
 * today — every :init command applies its own tool's branding directly.
 * The spec exists as a declarative, machine-readable description of HOW a
 * tool takes branding, kept accurate by ClusterToolWhitelabelTest.
 */
interface HasWhiteLabel
{
    /** @return array{app_name_key?: string, logo_url_key?: string, sub_filter?: bool, blade_variables?: bool} */
    public function whiteLabel(): array;
}
