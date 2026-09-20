<?php

namespace App\Enums;

/**
 * How a Cluster Tool's deployed resources are named. Three generations exist
 * on real clusters, and a tool's rotation and OpenBao sync must use the one
 * its own manifests write — a Merge-policy ExternalSecret can't create a
 * Secret, so a name nothing deploys silently reaches nothing.
 *
 * The goal is every tool on CANONICAL (ADR 0021); the other two cases
 * disappear as each tool's manifests and its live resources are renamed
 * together (`plans/active/tool-instance-naming.md`).
 */
enum ResourceNaming
{
    /** `{component}-{instance}`, with `-{token}` only when a component owns several of a kind. */
    case CANONICAL;

    /** The tool appends the instance itself: `{name}-{instance}`. */
    case INSTANCE_SUFFIXED;

    /** A fixed name, from before instances existed. */
    case AS_SHIPPED;
}
