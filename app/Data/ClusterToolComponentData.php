<?php

namespace App\Data;

use App\Enums\ClusterToolComponentRole;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

/**
 * One Deployment belonging to a ClusterTool, fully resolved for a given
 * instance/engine. ~26 of 29 tools have exactly one PRIMARY component;
 * CHAT, GIT, and DESIGN have several — this is the single source of truth
 * for their sub-deployment lists, replacing the hand-copied `kubectl delete`
 * strings that used to live independently in each {Tool}RemoveCommand and
 * could silently drift from the Blade manifest that actually deploys them.
 */
class ClusterToolComponentData extends Data
{
    public function __construct(
        /** Unique within the tool: "server", "runner", "synapse", "cinny", "coturn", "db", "backend", "frontend", "exporter". Never the full Deployment name. */
        public string $key,
        public ClusterToolComponentRole $role,
        /** Fully resolved Deployment name for the requested instance/engine — same string shape deploymentName() has always returned for the PRIMARY component. */
        public string $deployment,
        /** Container name for kubectl exec (backup, admin commands). Defaults to $deployment when null — set explicitly only when it differs (e.g. chat-synapse's container is "synapse"). */
        public ?string $container = null,
        /**
         * Other resources teardown must also delete for this component —
         * kind/name pairs in the SAME namespace as the tool, mirroring
         * RemovableWhenManaged::getManagedResources()'s shape.
         *
         * @var list<array{kind: string, name: string}>
         */
        public array $resources = [],
        /** True only for a --no-plex bundled-storage component (chat-synapse-db) — never a real Commons Postgres replacement. Informational; teardown deletes it unconditionally with --ignore-not-found either way. */
        public bool $bundledOnly = false,
        /** When true, sso:wire/mail:wire also `kubectl set env --from=secret` + rollout-restart THIS deployment using the PRIMARY component's secret — the general form of Penpot's frontend needing the same OIDC client as its backend. */
        public bool $sharesPrimarySecret = false,
        /** backup:run target — false is how a component stays excluded from backups, declaratively instead of by omission from a hardcoded list. */
        public bool $backupVolume = false,
        /**
         * In-container paths to archive when backupVolume is true.
         *
         * A list because one component can own several files worth keeping that
         * sit beside far more that is rebuildable — NetBird's mount holds idp.db
         * and events.db next to 73MB of re-downloadable GeoIP data.
         *
         * **All entries must share a directory.** backup:run archives them as
         * `tar -C <dir> base1 base2 …`, so members are stored as bare basenames
         * and restore puts them back with `tar -x -C <dir>`. That is byte-identical
         * to the single-path layout every existing archive already uses, which is
         * what keeps those archives restorable. Paths in different directories
         * would need either ambiguous multiple `-C` groups or a `-C /` layout that
         * invalidates every archive taken so far.
         *
         * @var list<string>
         */
        public array $backupPaths = [],
    ) {
        $directories = array_unique(array_map('dirname', $this->backupPaths));

        if (count($directories) > 1) {
            throw new InvalidArgumentException(
                "backupPaths for component '{$this->key}' must share a directory; got: ".implode(', ', $directories),
            );
        }
    }
}
