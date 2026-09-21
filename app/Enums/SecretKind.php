<?php

namespace App\Enums;

/**
 * The kinds of Secret a Cluster Tool component owns (ADR 0021). The value is
 * the name token: `{category}-{component}-{value}-{instance}`.
 */
enum SecretKind: string
{
    /** Generated credentials (keys, passwords) the tool itself needs. */
    case CREDENTIALS = 'secrets';

    /** OIDC client written by `sso:wire`. */
    case OIDC = 'oidc';

    /** SMTP settings written by `mail:wire`. */
    case SMTP = 'smtp';

    /** Rendered configuration files. */
    case CONFIG = 'config';

    /** The Commons database password (rotated by OpenBao). */
    case STORE = 'store';

    /** The Zitadel app's ids and client credentials, written by `sso:wire`. */
    case SSO_APP = 'sso';
}
