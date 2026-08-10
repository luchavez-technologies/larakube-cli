<?php

namespace App\Enums;

/**
 * The role a single Deployment plays within a ClusterTool that may have more
 * than one (e.g. Forgejo's server+runner, Matrix's synapse+cinny+coturn).
 */
enum ClusterToolComponentRole: string
{
    /** The app-logic deployment — the default oidc/smtp/db-secret wiring target, and what deploymentName() returns. Exactly one per tool. */
    case PRIMARY = 'primary';

    /** Terminates the tool's public HTTP ingress when that is NOT the primary component (e.g. chat-cinny, design-penpot-frontend). */
    case INGRESS = 'ingress';

    /** Background/ancillary deployment — never an oidc/smtp/db-secret wiring target on its own (forgejo-runner, chat-coturn, design-penpot-exporter). */
    case WORKER = 'worker';

    /** Bundled storage backend present only on a --no-plex install (e.g. chat-synapse-db). */
    case DATABASE = 'database';
}
