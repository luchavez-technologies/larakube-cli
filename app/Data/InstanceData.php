<?php

namespace App\Data;

use App\Enums\ClusterTool;
use Spatie\LaravelData\Data;

/**
 * One entry in the cluster tool registry — a single deployed instance of a
 * single ClusterTool. The registry itself is a flat list of these (see
 * InteractsWithToolRegistry), self-describing via $tool rather than nested
 * under a tool-name key, so "all instances of X" is a filter, not a lookup.
 *
 * $instance is purely the Kubernetes-resource-naming slug (see
 * ClusterTool::instanceSlugFromHost()) — never operator-supplied, never a
 * CLI flag. $host is the real identity; --domain= is how every command
 * targets a specific instance.
 *
 * The registry's raw arrays use these exact camelCase keys — matching
 * ConfigData/GlobalConfigData's convention of writing/reading straight
 * through the Data class with no name translation, rather than a
 * standalone snake_case wire format.
 */
class InstanceData extends Data
{
    public function __construct(
        /** Raw ClusterTool enum value, e.g. "data". Use getTool() for the typed enum. */
        public ?string $tool = null,
        public ?string $host = null,
        /** Kubernetes-resource-naming slug, e.g. "main" or "blog-example-com". */
        public ?string $instance = null,
        /** @var list<string> */
        public array $aliases = [],
        public ?string $brandName = null,
        public ?string $logoUrl = null,
        /** The first-run admin account's email, when the tool has one (data, sso, desk, git). Never the password — that stays in the tool's own Secret. */
        public ?string $adminEmail = null,
        /**
         * Which backing engine this instance runs, for tools that have more
         * than one (currently only data: "directus" or "pocketbase").
         * Informational only — never the source of truth for a destructive
         * decision. data:remove still live-probes the cluster (deploymentExists())
         * to decide what to tear down, since a stale registry entry must never
         * cause the wrong engine's resources to be deleted or preserved.
         */
        public ?string $engine = null,
        /** ISO-8601 timestamp. */
        public ?string $installedAt = null,
        /** ISO-8601 timestamp. */
        public ?string $updatedAt = null,
    ) {}

    public function getTool(): ?ClusterTool
    {
        return ClusterTool::tryFrom((string) $this->tool);
    }
}
