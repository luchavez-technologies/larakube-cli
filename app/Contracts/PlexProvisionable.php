<?php

namespace App\Contracts;

/**
 * A backing-service driver that can (or one day will) be offered as a shared
 * "Commons" service in the Plex tier. The plex commands enumerate every driver
 * across DatabaseDriver/CacheDriver/SearchDriver/StorageDriver and ask the enum
 * itself — so adding a new Commons service is "implement this contract", not
 * another hardcoded 'postgres'/'redis' string in the plex code.
 */
interface PlexProvisionable
{
    /**
     * Whether this driver is supported as a shared Commons service TODAY. A
     * driver can map to a Commons service (commonsServiceName()) but not yet be
     * provisionable — those are shown but not selectable, so the catalog stays
     * honest about what's actually wired up.
     */
    public function isPlexReady(): bool;

    /**
     * The Commons service key this driver maps to. This is just the driver's own
     * enum value (e.g. 'postgres', 'redis', 'meilisearch', 'seaweedfs') — no
     * remapping — so distinct backends stay distinct services and can coexist
     * (a SeaweedFS tenant and a MinIO tenant get different Commons services).
     * Returns null when the driver is never a shareable service (SQLite is a
     * local file; the database-backed cache/scout drivers run in the app's own DB).
     */
    public function commonsServiceName(): ?string;
}
