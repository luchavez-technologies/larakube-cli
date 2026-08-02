<?php

namespace App\Enums;

enum ClusterTool: string
{
    public function getLabel(): string
    {
        return match ($this) {
            self::FLOW => 'Workflow Automation (N8N or Windmill)',
            self::SHEETS => 'Spreadsheet Database (Baserow or NocoDB)',
            self::PASSWORDS => 'Password Manager (Vaultwarden)',
            self::RECORD => 'Screen Recording & Sharing (Sendrec)',
            self::MONITOR => 'Monitoring Stack (Grafana + Loki + Prometheus)',
            self::SECRETS => 'Secrets Manager (OpenBao)',
            self::ERRORS => 'Error Tracking (GlitchTip)',
            self::UPTIME => 'Status Pages (Uptime Kuma)',
            self::GIT => 'Git Forge & CI/CD (Gitea)',
            self::VPN => 'Zero-Trust VPN Mesh (NetBird)',
            self::INSIGHTS => 'Business Intelligence (Metabase)',
            self::DNS => 'Automated DNS (ExternalDNS + Cloudflare)',
            self::MAIL => 'Mail Server (Stalwart)',
            self::DESK => 'Help Desk & Shared Inbox (FreeScout)',
            self::CHAT => 'Team Chat (Mattermost)',
            self::SSO => 'Identity Provider / SSO (Zitadel)',
            self::WEBMAIL => 'Webmail UI (Bulwark)',
            self::NOTES => 'Team Wiki & Knowledge Base (Outline)',
            self::DRIVE => 'Cloud Storage & Sync (oCIS)',
            self::ANALYTICS => 'Web Analytics (Umami)',
            self::TASKS => 'Project Management (Plane or Planka)',
            self::SIGN => 'Document Signing (Documenso)',
            self::SUPPORT => 'Customer Support (Chatwoot)',
            self::LINK => 'Link Management (Kutt)',
            self::CRM => 'CRM (Twenty)',
        };
    }

    public function productName(): string
    {
        return match ($this) {
            self::ANALYTICS => 'Umami',
            self::CHAT => 'Mattermost',
            self::CRM => 'Twenty',
            self::DESK => 'FreeScout',
            self::DNS => 'ExternalDNS',
            self::DRIVE => 'oCIS',
            self::ERRORS => 'GlitchTip',
            self::FLOW => 'n8n',
            self::GIT => 'Gitea',
            self::INSIGHTS => 'Metabase',
            self::LINK => 'Kutt',
            self::MAIL => 'Stalwart',
            self::MONITOR => 'Grafana',
            self::NOTES => 'Outline',
            self::PASSWORDS => 'Vaultwarden',
            self::RECORD => 'Sendrec',
            self::SECRETS => 'OpenBao',
            self::SHEETS => 'Baserow',
            self::SIGN => 'Documenso',
            self::SSO => 'Zitadel',
            self::SUPPORT => 'Chatwoot',
            self::TASKS => 'Plane',
            self::UPTIME => 'Uptime Kuma',
            self::VPN => 'NetBird',
            self::WEBMAIL => 'Bulwark',
        };
    }

    /**
     * The SharedClusterService this tool exposes over HTTP — the single source
     * for its hostname (hostFor/hostPrefix) and human label. This is what makes
     * a generic `{tool}:show` possible: the show command resolves the host from
     * here instead of every tool hand-rolling its own `*Access()` lookup.
     * null for DNS, which deploys ExternalDNS (a controller with no ingress of
     * its own) and therefore has nothing to show a URL for.
     */
    public function service(): ?SharedClusterService
    {
        return match ($this) {
            self::ANALYTICS => SharedClusterService::ANALYTICS,
            self::CHAT => SharedClusterService::CHAT,
            self::CRM => SharedClusterService::CRM,
            self::DESK => SharedClusterService::DESK,
            self::DNS => null,
            self::DRIVE => SharedClusterService::DRIVE,
            self::ERRORS => SharedClusterService::ERRORS,
            self::FLOW => SharedClusterService::FLOW,
            self::GIT => SharedClusterService::GITEA,
            self::INSIGHTS => SharedClusterService::INSIGHTS,
            self::LINK => SharedClusterService::LINK,
            self::MAIL => SharedClusterService::MAIL,
            self::MONITOR => SharedClusterService::GRAFANA,
            self::NOTES => SharedClusterService::NOTES,
            self::PASSWORDS => SharedClusterService::VAULT,
            self::SECRETS => SharedClusterService::SECRETS,
            self::SHEETS => SharedClusterService::SHEET,
            self::SIGN => SharedClusterService::SIGN,
            self::SSO => SharedClusterService::SSO,
            self::SUPPORT => SharedClusterService::SUPPORT,
            self::TASKS => SharedClusterService::TASKS,
            self::UPTIME => SharedClusterService::UPTIME_KUMA,
            self::VPN => SharedClusterService::VPN,
            self::WEBMAIL => SharedClusterService::WEBMAIL,
        };
    }

    /**
     * The Kubernetes namespace this tool's workloads live in. Was previously
     * duplicated as a `{tool}Namespace()` method on ~24 commands and traits
     * (all returning a hard-coded string); centralised here so the remove/show
     * base commands can resolve it without the concrete command supplying it.
     * The four non-shared namespaces are the tools that own their whole
     * namespace and tear it down wholesale — see removesNamespace().
     */
    public function namespace(): string
    {
        return match ($this) {
            self::PASSWORDS => 'larakube-vault',
            self::SECRETS => 'larakube-secrets',
            self::SSO => 'larakube-sso',
            self::VPN => 'larakube-vpn',
            default => 'larakube-shared',
        };
    }

    /**
     * True when this tool's teardown deletes its entire namespace rather than
     * an enumerated resource list. Only safe because these four are the sole
     * occupants of their namespace (see namespace()) — never set this for a
     * larakube-shared tool or `{tool}:remove` would take every other tool with it.
     */
    public function removesNamespace(): bool
    {
        return $this->namespace() !== 'larakube-shared';
    }

    /**
     * The Plex Commons Postgres database(s) `{tool}:remove` must drop, keyed so
     * the tenant name and role name match what the matching `*:init` created.
     * Multi-entry lists are engine-switchable tools (flow: n8n|windmill,
     * sheets: baserow|nocodb) where either engine's database may exist — the
     * teardown drops both to guarantee a clean slate, matching the existing
     * hand-written behaviour in FlowInitCommand::removeFlow().
     * Empty for tools with no Commons tenant (they bundle storage or are
     * stateless controllers).
     *
     * @return list<string>
     */
    public function commonsDatabases(): array
    {
        return match ($this) {
            self::ANALYTICS => ['umami'],
            self::CHAT => ['mattermost'],
            self::CRM => ['crm_twenty'],
            self::DESK => ['freescout'],
            self::DRIVE => ['drive'],
            self::ERRORS => ['glitchtip'],
            self::FLOW => ['n8n', 'windmill'],
            self::GIT => ['gitea'],
            self::INSIGHTS => ['metabase'],
            self::LINK => ['kutt'],
            self::NOTES => ['outline'],
            self::MAIL => ['stalwart'],
            self::PASSWORDS => ['vaultwarden'],
            self::SECRETS => ['infisical'],
            self::SHEETS => ['baserow', 'nocodb'],
            self::SIGN => ['documenso'],
            self::SSO => ['zitadel'],
            self::SUPPORT => ['chatwoot'],
            self::TASKS => ['planka'],
            default => [],
        };
    }

    /**
     * Tools that allocate a logical index on the Commons Valkey/Redis and must
     * release it on teardown, so a later re-install doesn't leak indices.
     * Mirrors the existing releaseCommonsRedisIndex() calls.
     *
     * @return list<string>
     */
    public function commonsRedisKeys(): array
    {
        return match ($this) {
            self::DRIVE => ['drive'],
            self::NOTES => ['outline'],
            self::SHEETS => ['baserow'],
            default => [],
        };
    }

    /**
     * Selectable engines for tools that ship more than one implementation, as
     * engine-slug => label. The FIRST entry is the default. Drives both the
     * `--engine=` validation and the `{tool}:init` engine prompt, so adding an
     * engine no longer means editing a hard-coded match in the command.
     *
     * @return array<string, string>
     */
    public function engines(): array
    {
        return match ($this) {
            self::DRIVE => ['ocis' => 'oCIS', 'nextcloud' => 'Nextcloud'],
            self::FLOW => ['n8n' => 'n8n', 'windmill' => 'Windmill'],
            self::SHEETS => ['baserow' => 'Baserow', 'nocodb' => 'NocoDB'],
            self::TASKS => ['planka' => 'Planka', 'plane' => 'Plane'],
            self::DESK => ['freescout' => 'FreeScout'],
            default => [],
        };
    }

    /** The default engine slug, or null for single-implementation tools. */
    public function defaultEngine(): ?string
    {
        return array_key_first($this->engines());
    }

    /**
     * True when `{tool}:init --no-plex` is meaningful — i.e. the tool can
     * bundle its own storage instead of leasing a Plex Commons tenant. Used to
     * reject `--no-plex` on tools that never supported it, which previously
     * accepted-and-ignored the flag.
     */
    public function supportsNoPlex(): bool
    {
        return match ($this) {
            self::CHAT, self::DESK, self::DRIVE, self::ERRORS,
            self::FLOW, self::INSIGHTS, self::SECRETS, self::SHEETS, self::SSO => true,
            default => false,
        };
    }

    /** Canonical command names — the one place the `{tool}:{action}` shape is spelled out. */
    public function initCommand(): string
    {
        return "{$this->value}:init";
    }

    public function removeCommand(): string
    {
        return "{$this->value}:remove";
    }

    public function showCommand(): string
    {
        return "{$this->value}:show";
    }

    /**
     * Get an associative array of value => label for prompts.
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $tool) {
            $options[$tool->value] = $tool->getLabel();
        }

        return $options;
    }

    /**
     * SMTP-consumer wiring schema for tools that send email: the Deployment (and
     * its namespace) to patch, the Secret that holds the credentials (its keys
     * ARE the target env var names, so `kubectl set env --from=secret` maps them
     * 1:1), any static env, and a logical => env-var-name map the wirer fills
     * from the Stalwart endpoint. null when the tool doesn't send email. This is
     * the single hook a new tool implements to become wireable by `mail:wire` /
     * `tool:add` — no per-tool wiring code anywhere else.
     *
     * @return array{deployment: string, namespace: string, secret: string, static?: array<string, string>, vars: array<string, string>}|null
     */
    public function smtpEnv(): ?array
    {
        return match ($this) {
            self::SHEETS => [
                // Baserow is the default Sheet engine. mail:wire patches the
                // Baserow Deployment; EMAIL_SMTP enables SMTP, EMAIL_SMTP_USE_SSL
                // matches Stalwart's implicit-TLS submission on 465 (its default).
                'deployment' => 'sheet-baserow',
                'namespace' => 'larakube-shared',
                'secret' => 'sheet-baserow-smtp',
                'static' => [
                    'EMAIL_SMTP' => 'yes',
                    'EMAIL_SMTP_USE_SSL' => 'yes',
                ],
                'vars' => [
                    'host' => 'EMAIL_SMTP_HOST',
                    'port' => 'EMAIL_SMTP_PORT',
                    'user' => 'EMAIL_SMTP_USER',
                    'password' => 'EMAIL_SMTP_PASSWORD',
                    'from' => 'FROM_EMAIL',
                ],
            ],
            self::FLOW => [
                'deployment' => 'flow-n8n',
                'namespace' => 'larakube-shared',
                'secret' => 'flow-n8n-smtp',
                'static' => [
                    'N8N_EMAIL_MODE' => 'smtp',
                    'N8N_SMTP_SSL' => 'true',
                    'N8N_SMTP_STARTTLS' => 'false',
                ],
                'vars' => [
                    'host' => 'N8N_SMTP_HOST',
                    'port' => 'N8N_SMTP_PORT',
                    'user' => 'N8N_SMTP_USER',
                    'password' => 'N8N_SMTP_PASS',
                    'from' => 'N8N_SMTP_SENDER',
                ],
            ],
            self::PASSWORDS => [
                'deployment' => 'vaultwarden',
                'namespace' => 'larakube-vault',
                'secret' => 'vaultwarden-smtp',
                'static' => [
                    'SMTP_SECURITY' => 'force_tls',
                ],
                'vars' => [
                    'host' => 'SMTP_HOST',
                    'port' => 'SMTP_PORT',
                    'user' => 'SMTP_USERNAME',
                    'password' => 'SMTP_PASSWORD',
                    'from' => 'SMTP_FROM',
                ],
            ],
            self::CHAT => [
                'deployment' => 'chat-mattermost',
                'namespace' => 'larakube-shared',
                'secret' => 'chat-mattermost-smtp',
                'static' => [
                    'MM_EMAILSETTINGS_ENABLESMTPAUTH' => 'true',
                    'MM_EMAILSETTINGS_CONNECTIONSECURITY' => 'TLS',
                    'MM_EMAILSETTINGS_SENDEMAILNOTIFICATIONS' => 'true',
                ],
                'vars' => [
                    'host' => 'MM_EMAILSETTINGS_SMTPSERVER',
                    'port' => 'MM_EMAILSETTINGS_SMTPPORT',
                    'user' => 'MM_EMAILSETTINGS_SMTPUSERNAME',
                    'password' => 'MM_EMAILSETTINGS_SMTPPASSWORD',
                    'from' => 'MM_EMAILSETTINGS_FEEDBACKEMAIL',
                ],
            ],
            self::NOTES => [
                'deployment' => 'notes-outline',
                'namespace' => 'larakube-shared',
                'secret' => 'notes-outline-smtp',
                'static' => [],
                'vars' => [
                    'host' => 'SMTP_HOST',
                    'port' => 'SMTP_PORT',
                    'user' => 'SMTP_USERNAME',
                    'password' => 'SMTP_PASSWORD',
                    'from' => 'SMTP_FROM_EMAIL',
                ],
            ],
            self::DRIVE => [
                // mail:wire patches the Nextcloud Deployment; oCIS has native SMTP too but uses different vars.
                // We'll map Nextcloud vars here as the default, similar to how Baserow is default for Sheets.
                'deployment' => 'drive-nextcloud',
                'namespace' => 'larakube-shared',
                'secret' => 'drive-nextcloud-smtp',
                'static' => [
                    'MAIL_FROM_ADDRESS' => 'drive',
                    'MAIL_SENDMAILMODE' => 'smtp',
                    'MAIL_SMTPSECURE' => 'ssl',
                    'MAIL_SMTPPORT' => '465',
                ],
                'vars' => [
                    'host' => 'MAIL_SMTPHOST',
                    'port' => 'MAIL_SMTPPORT', // Optional override
                    'user' => 'MAIL_SMTPNAME',
                    'password' => 'MAIL_SMTPPASSWORD',
                    'from' => 'MAIL_DOMAIN',
                ],
            ],
            self::TASKS => [
                'deployment' => 'tasks-planka',
                'namespace' => 'larakube-shared',
                'secret' => 'tasks-planka-smtp',
                'static' => [
                    'SMTP_SECURE' => 'true',
                ],
                'vars' => [
                    'host' => 'SMTP_HOST',
                    'port' => 'SMTP_PORT',
                    'user' => 'SMTP_USER',
                    'password' => 'SMTP_PASSWORD',
                    'from' => 'SMTP_FROM',
                ],
            ],
            self::SIGN => [
                'deployment' => 'sign-documenso',
                'namespace' => 'larakube-shared',
                'secret' => 'sign-documenso-smtp',
                'static' => [
                    'NEXT_PRIVATE_SMTP_TRANSPORT' => 'smtp-auth',
                    'NEXT_PRIVATE_SMTP_SECURE' => 'false',
                ],
                'vars' => [
                    'host' => 'NEXT_PRIVATE_SMTP_HOST',
                    'port' => 'NEXT_PRIVATE_SMTP_PORT',
                    'user' => 'NEXT_PRIVATE_SMTP_USERNAME',
                    'password' => 'NEXT_PRIVATE_SMTP_PASSWORD',
                    'from' => 'NEXT_PRIVATE_SMTP_FROM_ADDRESS',
                ],
            ],
            self::SUPPORT => [
                'deployment' => 'support-chatwoot',
                'namespace' => 'larakube-shared',
                'secret' => 'support-chatwoot-smtp',
                'static' => [
                    'SMTP_ENABLE_STARTTLS_AUTO' => 'true',
                ],
                'vars' => [
                    'host' => 'SMTP_ADDRESS',
                    'port' => 'SMTP_PORT',
                    'user' => 'SMTP_USERNAME',
                    'password' => 'SMTP_PASSWORD',
                    'from' => 'MAILER_SENDER_EMAIL',
                ],
            ],
            self::LINK => [
                'deployment' => 'link-kutt',
                'namespace' => 'larakube-shared',
                'secret' => 'link-kutt-smtp',
                'static' => [
                    'MAIL_ENABLED' => 'true',
                    'MAIL_SECURE' => 'true',
                ],
                'vars' => [
                    'host' => 'MAIL_HOST',
                    'port' => 'MAIL_PORT',
                    'user' => 'MAIL_USER',
                    'password' => 'MAIL_PASSWORD',
                    'from' => 'MAIL_FROM',
                ],
            ],
            self::CRM => [
                'deployment' => 'crm-twenty',
                'namespace' => 'larakube-shared',
                'secret' => 'crm-twenty-smtp',
                'static' => [
                    'EMAIL_DRIVER' => 'smtp',
                ],
                'vars' => [
                    'host' => 'EMAIL_SMTP_HOST',
                    'port' => 'EMAIL_SMTP_PORT',
                    'user' => 'EMAIL_SMTP_USER',
                    'password' => 'EMAIL_SMTP_PASSWORD',
                    'from' => 'EMAIL_FROM_ADDRESS',
                ],
            ],
            default => null,
        };
    }

    /**
     * Whether `sso:wire` will register this tool as a Zitadel OIDC client.
     *
     * The policy layer over oidcEnv() (the wiring mechanism): a tool can carry
     * the mechanism yet still be withheld. Bulwark (WEBMAIL) is exactly that —
     * wiring it activates a server-wide OIDC directory on Stalwart that breaks
     * the mail admin console, so mail stays on passwords (docs/decisions/0001).
     *
     * Sibling to a future hasVpnWire() for NetBird — same per-tool-policy shape.
     */
    public function hasSsoWire(): bool
    {
        return match ($this) {
            self::WEBMAIL => false,
            default => $this->oidcEnv() !== null,
        };
    }

    /**
     * OIDC-consumer wiring schema for tools that support logging in via an
     * external identity provider — same shape as smtpEnv() (deployment +
     * namespace to patch, secret whose keys ARE the target env-var names,
     * static env, and a logical => env-var-name map sso:wire fills from the
     * Zitadel app it registers). null when the tool has no OIDC support.
     * Covers the tools that take OIDC config via plain env vars — Grafana and
     * Vaultwarden. Gitea/NetBird/GlitchTip need CLI- or API-driven OIDC
     * registration instead of env vars and aren't wired by this mechanism yet
     * (see plans/active/sso-identity-provider.md).
     * Field names verified against each project's own docs, not a live
     * instance — treat as one notch less certain than smtpEnv().
     *
     * @return array{deployment: string, namespace: string, secret: string, static?: array<string, string>, vars: array<string, string>, redirect_path: string}|null
     */
    public function oidcEnv(): ?array
    {
        return match ($this) {
            self::MONITOR => [
                'deployment' => 'grafana',
                'namespace' => 'larakube-shared',
                'secret' => 'grafana-oidc',
                'static' => [
                    'GF_AUTH_GENERIC_OAUTH_ENABLED' => 'true',
                    'GF_AUTH_GENERIC_OAUTH_NAME' => 'Zitadel',
                    'GF_AUTH_GENERIC_OAUTH_SCOPES' => 'openid profile email',
                    'GF_AUTH_GENERIC_OAUTH_USE_PKCE' => 'true',
                ],
                'vars' => [
                    'client_id' => 'GF_AUTH_GENERIC_OAUTH_CLIENT_ID',
                    'client_secret' => 'GF_AUTH_GENERIC_OAUTH_CLIENT_SECRET',
                    'auth_url' => 'GF_AUTH_GENERIC_OAUTH_AUTH_URL',
                    'token_url' => 'GF_AUTH_GENERIC_OAUTH_TOKEN_URL',
                    'userinfo_url' => 'GF_AUTH_GENERIC_OAUTH_API_URL',
                ],
                // Grafana derives its own callback from GF_SERVER_ROOT_URL — this
                // is the fixed suffix sso:wire appends to the tool's own host when
                // registering the redirect URI with Zitadel.
                'redirect_path' => '/login/generic_oauth',
            ],
            self::PASSWORDS => [
                'deployment' => 'vaultwarden',
                'namespace' => 'larakube-vault',
                'secret' => 'vaultwarden-oidc',
                'static' => [
                    'SSO_ENABLED' => 'true',
                    'SSO_PKCE' => 'true',
                    'SSO_SCOPES' => 'email profile',
                ],
                'vars' => [
                    'client_id' => 'SSO_CLIENT_ID',
                    'client_secret' => 'SSO_CLIENT_SECRET',
                    // Vaultwarden's SSO_AUTHORITY is the OIDC issuer (its own
                    // .well-known/openid-configuration is discovered from this),
                    // not a raw host — Zitadel's issuer IS its external host.
                    'issuer' => 'SSO_AUTHORITY',
                ],
                'redirect_path' => '/identity/connect/oidc-signin',
            ],
            self::NOTES => [
                'deployment' => 'notes-outline',
                'namespace' => 'larakube-shared',
                'secret' => 'notes-outline-oidc',
                'static' => [
                    'FORCE_HTTPS' => 'true',
                ],
                'vars' => [
                    'client_id' => 'OIDC_CLIENT_ID',
                    'client_secret' => 'OIDC_CLIENT_SECRET',
                    'auth_url' => 'OIDC_AUTH_URI',
                    'token_url' => 'OIDC_TOKEN_URI',
                    'userinfo_url' => 'OIDC_USERINFO_URI',
                ],
                'redirect_path' => '/auth/oidc.callback',
            ],
            self::DRIVE => [
                'deployment' => 'drive-ocis',
                'namespace' => 'larakube-shared',
                'secret' => 'drive-ocis-oidc',
                'static' => [
                    'PROXY_AUTOPROVISION_ACCOUNTS' => 'true',
                    'PROXY_USER_OIDC_CLAIM' => 'email',
                    'PROXY_ROLE_ASSIGNMENT_DRIVER' => 'oidc',
                ],
                'vars' => [
                    'client_id' => 'WEB_OIDC_CLIENT_ID',
                    'client_secret' => 'OCIS_OIDC_CLIENT_SECRET',
                    'issuer' => 'OCIS_OIDC_ISSUER',
                ],
                'redirect_path' => '/', // oCIS root is the callback
            ],
            self::SIGN => [
                'deployment' => 'sign-documenso',
                'namespace' => 'larakube-shared',
                'secret' => 'sign-documenso-oidc',
                'static' => [
                    'NEXT_PUBLIC_DISABLE_OIDC_SIGNIN' => 'false',
                    'NEXT_PRIVATE_OIDC_ALLOW_SIGNUP' => 'true',
                ],
                'vars' => [
                    'client_id' => 'NEXT_PRIVATE_OIDC_CLIENT_ID',
                    'client_secret' => 'NEXT_PRIVATE_OIDC_CLIENT_SECRET',
                    'issuer' => 'NEXT_PRIVATE_OIDC_WELL_KNOWN',
                ],
                'redirect_path' => '/api/auth/callback/oidc',
            ],
            self::TASKS => [
                'deployment' => 'tasks-planka',
                'namespace' => 'larakube-shared',
                'secret' => 'tasks-planka-oidc',
                'static' => [],
                'vars' => [
                    'client_id' => 'OIDC_CLIENT_ID',
                    'client_secret' => 'OIDC_CLIENT_SECRET',
                    'issuer' => 'OIDC_ISSUER',
                ],
                'redirect_path' => '/api/auth/oidc/callback/', // Adjust if planka has a different callback
            ],
            default => null,
        };
    }

    /**
     * The Zitadel project role keys this tool gates login behind, keyed to a
     * short description for the operator instructions `sso:wire` prints.
     * Empty for every tool that has no elevated-access story of its own (its
     * users are scoped per-account — Vaultwarden's vault, Forgejo's repos —
     * so "any authenticated org member" is the correct default).
     *
     * A non-empty return routes the tool onto rbacProjectName() instead of
     * the shared "LaraKube Shared Tools" project, and makes sso:wire ensure
     * the role exists there and enable projectRoleAssertion so the
     * larakube_roles claim (see ensureRbacAction()) is populated. Granting
     * the role to specific users stays a manual Zitadel console step — see
     * plans/active/openbao-hardening.md.
     *
     * @return array<string, string>
     */
    public function rbacRoles(): array
    {
        return match ($this) {
            self::SECRETS => [
                'openbao-admin' => 'Full read/write on all secrets and Commons database credentials',
                'openbao-operator' => 'Read-only on production secrets and static database roles',
                'openbao-auditor' => 'Read-only on audit logs and secret metadata (no values)',
            ],
            self::MONITOR => [
                'grafana-admin' => 'Full Grafana admin — manage users, datasources, plugins',
                'grafana-editor' => 'Can create/edit dashboards and alerts',
                'grafana-user' => 'Can log in to Grafana (Viewer role)',
            ],
            default => [],
        };
    }

    /** True when this tool's SSO login is gated by rbacRoles() rather than open to any org member. */
    public function requiresRbacGating(): bool
    {
        return $this->rbacRoles() !== [];
    }

    /**
     * Roles that gate ADMIN privileges (not login) for open-to-org tools —
     * the counterpart to rbacRoles(). A tool with rbacRoles() keeps its
     * login itself gated: un-granted users are denied at the door. A tool
     * with only ssoAdminRoles() is open to every org member and merely
     * distinguishes elevated privileges (e.g. oCIS admin vs. regular user).
     *
     * sso:wire creates these on the tool's OWN project (the shared one, not
     * the RBAC project), and — unlike rbacRoles(), whose grants are a manual
     * `sso:grant` step — accepts an --admin-email= to grant the first one
     * right away. The claim-flattening Action (flattenOcisRoles) turns these
     * grants into the ocisRoles claim oCIS's PROXY_ROLE_ASSIGNMENT_DRIVER=oidc
     * re-asserts on every login.
     *
     * @return array<string, string>
     */
    public function ssoAdminRoles(): array
    {
        return match ($this) {
            self::DRIVE => [
                'ocisAdmin' => 'oCIS administrator — can create and manage Spaces',
                'ocisSpaceAdmin' => 'oCIS space administrator — create and manage Spaces, no system admin',
            ],
            default => [],
        };
    }

    /** The Zitadel project open-to-org tools with ssoAdminRoles() register under, instead of the RBAC project. */
    public static function ssoAdminProjectName(): string
    {
        return 'LaraKube Shared Tools';
    }

    /**
     * Every role key this tool supports granting — rbacRoles() plus
     * ssoAdminRoles(). Disjoint by construction: a tool is either gated
     * (rbacRoles) or open-to-org (ssoAdminRoles), never both.
     *
     * @return array<string, string>
     */
    public function grantableRoles(): array
    {
        return $this->rbacRoles() + $this->ssoAdminRoles();
    }

    /** The Zitadel project role-gated tools register under, instead of the shared open project. */
    public static function rbacProjectName(): string
    {
        return 'LaraKube RBAC';
    }

    /** The tool that owns a given grantableRoles() key, or null if none claims it. */
    public static function forGrantableRoleKey(string $roleKey): ?self
    {
        foreach (self::cases() as $tool) {
            if (array_key_exists($roleKey, $tool->grantableRoles())) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * The OIDC redirect URI to register in Zitadel for this tool.
     *
     * @return array<int, string>
     */
    public function oidcRedirectUris(string $toolHost): array
    {
        $schema = $this->oidcEnv();
        if ($schema === null) {
            return [];
        }

        $basePath = $schema['redirect_path'];

        return ["https://{$toolHost}{$basePath}"];
    }

    /**
     * The Traefik Middleware {name, namespace} a --vpn-only-capable tool's
     * ingress annotation already references (e.g.
     * larakube-shared-desk-vpn-only@kubernetescrd → name "desk-vpn-only" in
     * "larakube-shared"). NOT derivable from $this->value — several tools'
     * ingress partials reference their SharedClusterService label instead
     * (errors→glitchtip-web, git→gitea, sheets→sheet, uptime→uptime-kuma),
     * confirmed by reading every ingress template rather than assumed. null
     * for tools with no --vpn-only flag (Dns, Vpn itself).
     *
     * @return array{name: string, namespace: string}|null
     */
    public function vpnMiddlewareTarget(): ?array
    {
        return match ($this) {
            self::FLOW => ['name' => 'flow-vpn-only', 'namespace' => 'larakube-shared'],
            self::SHEETS => ['name' => 'sheet-vpn-only', 'namespace' => 'larakube-shared'],
            self::PASSWORDS => ['name' => 'vault-vpn-only', 'namespace' => 'larakube-vault'],
            self::MONITOR => ['name' => 'grafana-vpn-only', 'namespace' => 'larakube-shared'],
            self::SECRETS => ['name' => 'infisical-vpn-only', 'namespace' => 'larakube-secrets'],
            self::ERRORS => ['name' => 'glitchtip-web-vpn-only', 'namespace' => 'larakube-shared'],
            self::UPTIME => ['name' => 'uptime-kuma-vpn-only', 'namespace' => 'larakube-shared'],
            self::GIT => ['name' => 'gitea-vpn-only', 'namespace' => 'larakube-shared'],
            self::INSIGHTS => ['name' => 'insights-vpn-only', 'namespace' => 'larakube-shared'],
            self::MAIL => ['name' => 'mail-vpn-only', 'namespace' => 'larakube-shared'],
            self::DESK => ['name' => 'desk-vpn-only', 'namespace' => 'larakube-shared'],
            self::CHAT => ['name' => 'chat-vpn-only', 'namespace' => 'larakube-shared'],
            self::SSO => ['name' => 'sso-vpn-only', 'namespace' => 'larakube-sso'],
            self::WEBMAIL => ['name' => 'webmail-vpn-only', 'namespace' => 'larakube-shared'],
            self::NOTES => ['name' => 'notes-vpn-only', 'namespace' => 'larakube-shared'],
            self::DRIVE => ['name' => 'drive-vpn-only', 'namespace' => 'larakube-shared'],
            self::ANALYTICS => ['name' => 'analytics-vpn-only', 'namespace' => 'larakube-shared'],
            self::TASKS => ['name' => 'tasks-vpn-only', 'namespace' => 'larakube-shared'],

            self::SIGN => ['name' => 'sign-vpn-only', 'namespace' => 'larakube-shared'],
            self::SUPPORT => ['name' => 'support-vpn-only', 'namespace' => 'larakube-shared'],
            self::LINK => ['name' => 'link-vpn-only', 'namespace' => 'larakube-shared'],
            self::CRM => ['name' => 'crm-vpn-only', 'namespace' => 'larakube-shared'],
            default => null,
        };
    }
    case ANALYTICS = 'analytics';
    case CHAT = 'chat';
    case CRM = 'crm';
    case DESK = 'desk';
    case DNS = 'dns';
    case DRIVE = 'drive';
    case ERRORS = 'errors';
    case FLOW = 'flow';
    case GIT = 'git';
    case INSIGHTS = 'insights';
    case LINK = 'link';
    case MAIL = 'mail';
    case MONITOR = 'monitor';
    case NOTES = 'notes';
    case PASSWORDS = 'passwords';
    case RECORD = 'record';
    case SECRETS = 'secrets';
    case SHEETS = 'sheets';
    case SIGN = 'sign';
    case SSO = 'sso';
    case SUPPORT = 'support';
    case TASKS = 'tasks';
    case UPTIME = 'uptime';
    case VPN = 'vpn';
    case WEBMAIL = 'webmail';
}
