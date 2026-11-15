<?php

namespace App\Support;

use App\Enums\ClusterTool;

/**
 * Every Kubernetes label and annotation key LaraKube writes or reads. Keys
 * share one prefix (a domain the project controls, so no other vendor's keys
 * can collide with ours), and nothing else spells a key out — renaming the
 * prefix is a one-line change plus re-applying each workload. See ADR 0021.
 *
 * Label keys select (discovery, teardown, backup); annotation keys only
 * describe. Values never belong here.
 */
final class Labels
{
    public const string PREFIX = 'luchtech.dev';

    // Cluster Tool identity (ToolInstance::labels()).
    public const string MANAGED_BY = self::PREFIX.'/managed-by';

    public const string TOOL = self::PREFIX.'/tool';

    public const string COMPONENT = self::PREFIX.'/component';

    public const string INSTANCE = self::PREFIX.'/instance';

    /** `bundled` on a `--no-plex` install, which leased no Commons tenant. */
    public const string STORAGE = self::PREFIX.'/storage';

    // Projects and workspaces.
    public const string PROJECT = self::PREFIX.'/project';

    public const string WORKSPACE = self::PREFIX.'/workspace';

    /** `true` on a preview environment's workloads, which `up` leaves alone. */
    public const string PREVIEW = self::PREFIX.'/preview';

    public const string APP = self::PREFIX.'/app';

    public const string ENV = self::PREFIX.'/env';

    public const string ROLE = self::PREFIX.'/role';

    public const string PROVIDER = self::PREFIX.'/provider';

    public const string WORKSPACE_FRAMEWORK = self::PREFIX.'/workspace-framework';

    public const string WORKSPACE_RUNTIME = self::PREFIX.'/workspace-runtime';

    public const string WORKSPACE_RUNTIME_VERSION = self::PREFIX.'/workspace-runtime-version';

    public const string WORKSPACE_SIZE = self::PREFIX.'/workspace-size';

    public const string WORKSPACE_REPO = self::PREFIX.'/workspace-repo';

    public const string WORKSPACE_BRANCH = self::PREFIX.'/workspace-branch';

    // Teammate access grants (cluster:grant).
    public const string ACCESS_USER = self::PREFIX.'/access-user';

    public const string ACCESS_SCOPE = self::PREFIX.'/access-scope';

    public const string PERSON = self::PREFIX.'/person';

    // ExternalDNS zone groups.
    public const string DNS_ZONE = self::PREFIX.'/dns-zone';

    public const string DNS_DOMAIN = self::PREFIX.'/dns-domain';

    public const string DNS_OWNER_ID = self::PREFIX.'/dns-owner-id';

    public const string DNS_PROVIDER = self::PREFIX.'/dns-provider';

    // Annotations.
    public const string CONFIG_CHECKSUM = self::PREFIX.'/config-checksum';

    public const string SECRET_CHECKSUM = self::PREFIX.'/secret-checksum';

    public const string CSP_CHECKSUM = self::PREFIX.'/csp-checksum';

    public const string CONFIG = self::PREFIX.'/config';

    public const string CURRENT_HOST = self::PREFIX.'/current-host';

    /**
     * The in-cluster NFS StorageClass's provisioner name. Not a label, but it
     * shares the prefix. Kubernetes never lets it change once NFS volumes
     * exist (each PV records the provisioner that made it).
     */
    public const string NFS_PROVISIONER = self::PREFIX.'/nfs';

    /** `key=value[,key=value…]`, the form `kubectl -l` takes. */
    public static function selector(array $pairs): string
    {
        return implode(',', array_map(fn (string $k, string $v): string => "{$k}={$v}", array_keys($pairs), $pairs));
    }

    /** Selects every workload of a tool, optionally narrowed to one component (its name stem). */
    public static function toolSelector(ClusterTool $tool, ?string $component = null): string
    {
        return self::selector([self::TOOL => $tool->value] + ($component !== null ? [self::COMPONENT => $component] : []));
    }
}
