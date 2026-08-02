<?php

namespace App\Enums;

enum ClusterTool: string
{
    public function getLabel(): string
    {
        return match ($this) {
            self::FLOW => 'Workflow Automation (N8N or Windmill)',
            self::SHEETS => 'Spreadsheet Database (Teable)',
            self::PASSWORDS => 'Password Manager (Vaultwarden)',
            self::MONITOR => 'Monitoring Stack (Grafana + Loki + Prometheus)',
            self::SECRETS => 'Secrets Manager (OpenBao)',
            self::ERRORS => 'Error Tracking (GlitchTip)',
            self::UPTIME => 'Status Pages (Uptime Kuma)',
            self::GIT => 'Git Forge & CI/CD (Forgejo)',
            self::VPN => 'Zero-Trust VPN Mesh (NetBird)',
            self::INSIGHTS => 'Business Intelligence (Metabase)',
            self::DNS => 'Automated DNS (ExternalDNS + Cloudflare)',
            self::MAIL => 'Mail Server (Stalwart)',
            self::DESK => 'Help Desk & Shared Inbox (FreeScout)',
            self::CHAT => 'Team Chat (Matrix)',
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
            self::RECORD => 'Screen Recording & Sharing (Sendrec)',
        };
    }

    public function productName(): string
    {
        return match ($this) {
            self::ANALYTICS => 'Umami',
            self::CHAT => 'Matrix',
            self::CRM => 'Twenty',
            self::DESK => 'FreeScout',
            self::DNS => 'ExternalDNS',
            self::DRIVE => 'oCIS',
            self::ERRORS => 'GlitchTip',
            self::FLOW => 'n8n',
            self::GIT => 'Forgejo',
            self::INSIGHTS => 'Metabase',
            self::LINK => 'Kutt',
            self::MAIL => 'Stalwart',
            self::MONITOR => 'Grafana',
            self::NOTES => 'Outline',
            self::PASSWORDS => 'Vaultwarden',
            self::RECORD => 'Sendrec',
            self::SECRETS => 'OpenBao',
            self::SHEETS => 'Teable',
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
            self::RECORD => SharedClusterService::RECORD,
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
     * Multi-entry lists are engine-switchable tools (flow: n8n|windmill)
     * where either engine's database may exist — the
     * teardown drops both to guarantee a clean slate, matching the existing
     * hand-written behaviour in FlowInitCommand::removeFlow().
     * Empty for tools with no Commons tenant (they bundle storage or are
     * stateless controllers).
     *
     * @return list<string>
     */
    public function commonsDatabases(): array
    {
        return $this->commonsDatabaseList();
    }

    /** The tool that owns a given Commons tenant, or null if none claims it. */
    public static function forCommonsTenant(string $tenant): ?self
    {
        foreach (self::cases() as $tool) {
            if (in_array($tenant, $tool->commonsDatabases(), true)) {
                return $tool;
            }
        }

        return null;
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
     * The secrets backend key a tenant's database password is stored under.
     *
     * Deliberately NOT prefixed with the storage topology. A tenant may stop
     * being a Commons tenant (that is what `--no-plex` means) without its
     * credential changing identity, so a `PLEX_`-prefixed key would either go
     * stale or force a rename on a purely operational choice. The key names the
     * TOOL, which is stable, and the manifest maps it to whatever env var the
     * tool actually reads.
     *
     * Overrides exist where a tool established a name before this was
     * centralised and its manifest already references it — renaming those would
     * break a running install for no gain.
     */
    public function clusterSecretDbKey(string $tenant): string
    {
        return match ($this) {
            self::MAIL => 'STALWART_STORE_PASSWORD',
            self::RECORD => 'RECORD_DB_PASSWORD',
            self::SIGN => 'SIGN_DB_PASSWORD',
            default => self::tenantKey($tenant),
        };
    }

    /** `record_sendrec` → `RECORD_SENDREC_DB_PASSWORD`. */
    public static function tenantKey(string $tenant): string
    {
        return strtoupper(str_replace('-', '_', $tenant)).'_DB_PASSWORD';
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
            self::GIT => ['forgejo'],
            self::NOTES => ['outline'],
            self::SHEETS => ['teable'],
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
            self::CHAT => ['matrix' => 'Matrix (Synapse + Element)'],
            self::DRIVE => ['ocis' => 'oCIS'],
            self::FLOW => ['n8n' => 'n8n', 'windmill' => 'Windmill'],
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
            self::FLOW, self::GIT, self::INSIGHTS, self::SSO => true,
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
    public function smtpEnv(?string $engine = null): ?array
    {
        return match ($this) {
            self::SHEETS => [
                'deployment' => 'sheet-teable',
                'namespace' => 'larakube-shared',
                'secret' => 'sheet-teable-smtp',
                'static' => [
                    'BACKEND_MAIL_SECURE' => 'true',
                ],
                'vars' => [
                    'host' => 'BACKEND_MAIL_HOST',
                    'port' => 'BACKEND_MAIL_PORT',
                    'user' => 'BACKEND_MAIL_AUTH_USER',
                    'password' => 'BACKEND_MAIL_AUTH_PASS',
                    'from' => 'BACKEND_MAIL_SENDER',
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
                'deployment' => 'chat-synapse',
                'namespace' => 'larakube-shared',
                'secret' => 'chat-smtp',
                'static' => [],
                'vars' => [
                    'host' => 'host',
                    'port' => 'port',
                    'user' => 'user',
                    'password' => 'password',
                    'from' => 'from',
                ],
            ],
            self::NOTES => [
                'deployment' => 'notes-outline',
                'namespace' => 'larakube-shared',
                'secret' => 'notes-outline-smtp',
                // Stalwart submissions is port 465 (implicit TLS). Outline
                // defaults SMTP_SECURE to true, but pin it so the 465 intent
                // survives any future default change.
                'static' => [
                    'SMTP_SECURE' => 'true',
                ],
                'vars' => [
                    'host' => 'SMTP_HOST',
                    'port' => 'SMTP_PORT',
                    'user' => 'SMTP_USERNAME',
                    'password' => 'SMTP_PASSWORD',
                    'from' => 'SMTP_FROM_EMAIL',
                ],
            ],
            self::DRIVE => [
                // oCIS is the canonical drive engine, so mail:wire targets it.
                // Its notifications service reads NOTIFICATIONS_SMTP_*; ssltls is
                // implicit TLS for Stalwart's port 465 (starttls|ssltls|none).
                'deployment' => 'drive-ocis',
                'namespace' => 'larakube-shared',
                'secret' => 'drive-ocis-smtp',
                'static' => [
                    'NOTIFICATIONS_SMTP_ENCRYPTION' => 'ssltls',
                    'NOTIFICATIONS_SMTP_AUTHENTICATION' => 'login',
                ],
                'vars' => [
                    'host' => 'NOTIFICATIONS_SMTP_HOST',
                    'port' => 'NOTIFICATIONS_SMTP_PORT',
                    'user' => 'NOTIFICATIONS_SMTP_USERNAME',
                    'password' => 'NOTIFICATIONS_SMTP_PASSWORD',
                    'from' => 'NOTIFICATIONS_SMTP_SENDER',
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
                    // mail:wire targets Stalwart's submissions port 465 (implicit
                    // TLS), so Documenso's nodemailer transport must use SSL —
                    // secure=false on 465 never negotiates TLS and mail fails.
                    'NEXT_PRIVATE_SMTP_SECURE' => 'true',
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
            self::GIT => [
                // Forgejo is entirely env-configurable via FORGEJO__<section>__<KEY>;
                // its entrypoint folds them into app.ini on every boot. Keys are
                // the 1.18+ mailer names (PROTOCOL/SMTP_ADDR replaced the old
                // MAILER_TYPE/HOST). `smtps` = implicit TLS, which is Stalwart's
                // 465 submissions listener.
                'deployment' => 'forgejo',
                'namespace' => 'larakube-shared',
                'secret' => 'forgejo-smtp',
                'static' => [
                    'FORGEJO__mailer__ENABLED' => 'true',
                    'FORGEJO__mailer__PROTOCOL' => 'smtps',
                ],
                'vars' => [
                    'host' => 'FORGEJO__mailer__SMTP_ADDR',
                    'port' => 'FORGEJO__mailer__SMTP_PORT',
                    'user' => 'FORGEJO__mailer__USER',
                    'password' => 'FORGEJO__mailer__PASSWD',
                    'from' => 'FORGEJO__mailer__FROM',
                ],
            ],
            self::RECORD => [
                'deployment' => 'record-sendrec',
                'namespace' => 'larakube-shared',
                'secret' => 'record-sendrec-smtp',
                // SendRec defaults to STARTTLS, which deadlocks on Stalwart's
                // 465 (implicit TLS) listener: plaintext EHLO vs a waiting TLS
                // handshake, 30s read timeout. Stalwart exposes no 587 listener,
                // so the CLIENT must do implicit TLS.
                //
                // The value for that is `tls`, NOT `implicit`. SendRec coerces
                // any unrecognised value back to starttls with only a startup
                // warning — `implicit` shipped here and silently reinstated the
                // very deadlock it was meant to fix. Confirmed from the pod:
                //   WARN "unrecognized SMTP_TLS value; falling back to starttls" value=implicit
                // Accepted values are starttls | tls | auto | none.
                'static' => [
                    'SMTP_TLS' => 'tls',
                ],
                'vars' => [
                    'host' => 'SMTP_HOST',
                    'port' => 'SMTP_PORT',
                    // NOT SMTP_USER / SMTP_PASS / SMTP_FROM — those matched only
                    // as substrings; the real names are these.
                    'user' => 'SMTP_USERNAME',
                    'password' => 'SMTP_PASSWORD',
                    'from' => 'EMAIL_FROM_ADDRESS',
                ],
            ],
            self::MONITOR => [
                'deployment' => 'grafana',
                'namespace' => 'larakube-shared',
                'secret' => 'grafana-smtp',
                'static' => [
                    'GF_SMTP_ENABLED' => 'true',
                ],
                'vars' => [
                    'host' => 'GF_SMTP_HOST',
                    'user' => 'GF_SMTP_USER',
                    'password' => 'GF_SMTP_PASSWORD',
                    'from' => 'GF_SMTP_FROM_ADDRESS',
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

    /**
     * Whether sso:wire enforces SSO at the Traefik ingress level via
     * Traefik ForwardAuth middleware + OAuth2-Proxy rather than native app OIDC.
     */
    public function usesForwardAuth(): bool
    {
        return match ($this) {
            self::RECORD => true,
            default => false,
        };
    }

    /**
     * True when this tool (for this engine) is configured by a mounted config
     * FILE and ignores environment variables entirely — so the `kubectl set env`
     * path that mail:wire and sso:wire use cannot reach it.
     *
     * Synapse is the case: everything lives in homeserver.yaml (`oidc_providers`,
     * `email`), the container declares no env block, and the mounted ConfigMap
     * has no variable substitution. Wiring it via env rolls the pod and reports
     * success while changing nothing — the worst possible outcome, because it
     * looks configured.
     *
     * Until the ConfigMap path is built (plans/active/matrix-configmap-wiring.md)
     * both wire commands refuse rather than pretend.
     */
    public function configuresViaConfigFile(?string $engine = null): bool
    {
        return $this === self::CHAT && ($engine ?? $this->defaultEngine()) === 'matrix';
    }

    /**
     * OIDC that is registered by running a command INSIDE the tool's pod rather
     * than by setting env vars, because the tool stores login sources in its own
     * database. Forgejo is the case: `forgejo admin auth add-oauth`. The Zitadel side
     * is identical — only the tool-side application differs.
     */
    public function usesCliOidc(): bool
    {
        return match ($this) {
            self::GIT => true,
            default => false,
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
     * @return array{deployment: string, namespace: string, secret: string, static?: array<string, string>, vars: array<string, string>, redirect_path: string, public_client?: bool}|null
     */
    public function oidcEnv(?string $engine = null): ?array
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
                    // Gate login itself, not just the assigned role — least-
                    // privilege default (per audit: Grafana has no non-admin
                    // "gate at the door" of its own, unlike OpenBao's
                    // bound_claims). larakube_roles is the flattened claim
                    // ensureRbacGating()/zitadelEnsureRbacAction() maintain;
                    // Zitadel's native roles claim is a nested object
                    // Grafana's role_attribute_path (JMESPath) can't read.
                    // Priority order matters — first true branch wins, so
                    // admin is checked before editor before user. The ''
                    // fallback + STRICT deny-on-no-match was verified live
                    // 2026-07-30 (real login, no role → "IdP did not return
                    // a role attribute", not a silent Viewer fallback).
                    // 'Admin' here is Grafana's ORG admin (can manage this
                    // org's users/datasources/plugins), not the separate
                    // server-wide GrafanaAdmin superadmin flag — that one's
                    // gated by ALLOW_ASSIGN_GRAFANA_ADMIN below, which stays
                    // false: nothing here should ever request it, since a
                    // single-org deployment has no cross-org admin need.
                    'GF_AUTH_GENERIC_OAUTH_ROLE_ATTRIBUTE_PATH' => "contains(larakube_roles[*], 'grafana-admin') && 'Admin' || contains(larakube_roles[*], 'grafana-editor') && 'Editor' || contains(larakube_roles[*], 'grafana-user') && 'Viewer' || ''",
                    'GF_AUTH_GENERIC_OAUTH_ROLE_ATTRIBUTE_STRICT' => 'true',
                    'GF_AUTH_GENERIC_OAUTH_ALLOW_ASSIGN_GRAFANA_ADMIN' => 'false',
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
                    // Zitadel includes extra audiences (project id, etc.) in the
                    // id_token beyond the client_id. Vaultwarden trusts only the
                    // client_id by default and rejects the rest ("not a trusted
                    // audience"). Trust any Zitadel numeric id — issuer + token
                    // signature are still validated, so this is safe.
                    'SSO_AUDIENCE_TRUSTED' => '^[0-9]+$',
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
            self::GIT => [
                // CLI-wired (usesCliOidc): Forgejo keeps login sources in its DB,
                // so there are no env `vars` to set — sso:wire execs
                // `forgejo admin auth add-oauth` instead. The callback path is
                // /user/oauth2/<source name>/callback, and sso:wire names the
                // source `zitadel`.
                'deployment' => 'forgejo',
                'namespace' => 'larakube-shared',
                'secret' => 'forgejo-oidc',
                'static' => [],
                'vars' => [],
                'redirect_path' => '/user/oauth2/zitadel/callback',
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
                // oCIS web is a browser SPA doing the full authorize+token
                // exchange in-page with PKCE — the served config.json's
                // openIdConnect block carries NO client_secret (verified live
                // 2026-07-31). Registering it as a confidential client like
                // Grafana/Vaultwarden makes Zitadel demand client auth at the
                // token endpoint the browser can't provide (invalid_client on
                // every login); a public client is what oCIS's own web client
                // assumes. sso:wire must then not push a client secret either.
                'public_client' => true,
                'static' => [
                    'PROXY_AUTOPROVISION_ACCOUNTS' => 'true',
                    // Resolve SSO users by their email claim. oCIS looks the
                    // value up against the attribute named by PROXY_USER_CS3_CLAIM
                    // (default "username"), so leaving that at its default would
                    // query username == <email> and never match an autoprovisioned
                    // account (which is minted with preferred_username as its
                    // username). "mail" makes resolution self-consistent.
                    'PROXY_USER_OIDC_CLAIM' => 'email',
                    'PROXY_USER_CS3_CLAIM' => 'mail',
                    // OIDC role assignment. This used to be "default": the oidc
                    // driver locks a user out if their token carries no role claim
                    // matching the built-in mapping (ocisAdmin/ocisSpaceAdmin/
                    // ocisUser/ocisGuest), and Zitadel's native roles claim is a
                    // nested object, never a flat list, so the Keycloak-style oidc
                    // example can't be copied verbatim. That gap is closed on the
                    // Zitadel side instead: sso:wire installs an org-wide Action
                    // ("flattenOcisRoles") that ALWAYS emits a flat top-level
                    // `ocisRoles` claim — ["ocisAdmin"] / ["ocisSpaceAdmin"] when
                    // the user holds the drive ocisAdmin/ocisSpaceAdmin role on
                    // the shared project (admin outranks spaceadmin), otherwise
                    // ["ocisUser"]. That no-match guarantee is what makes
                    // driver=oidc safe here: oCIS re-asserts the role from the
                    // claim on EVERY login (dynamic promote/demote — a manual
                    // admin-settings role edit would be overwritten), and a user
                    // with zero grants still lands on ocisUser instead of being
                    // denied. The claim maps through oCIS's built-in default
                    // mapping (ocisAdmin->admin, ocisSpaceAdmin->spaceadmin,
                    // ocisUser->user), so no role-mapping yaml is needed.
                    'PROXY_ROLE_ASSIGNMENT_DRIVER' => 'oidc',
                    'PROXY_ROLE_ASSIGNMENT_OIDC_CLAIM' => 'ocisRoles',
                    // Desktop/iOS/Android clients discover the OIDC provider at
                    // drive.<host>/.well-known/openid-configuration; without this
                    // rewrite they'd hit oCIS's builtin discovery instead of
                    // Zitadel's. Matches the canonical Keycloak external-IDP
                    // deployment example.
                    'PROXY_OIDC_REWRITE_WELLKNOWN' => 'true',
                    // Zitadel issues opaque (non-JWT) access tokens by default,
                    // so oCIS's default jwt verify rejects every API call with
                    // "token contains an invalid number of segments" -> 401 ->
                    // oCIS web's "Not logged in" error page (verified live
                    // 2026-08-01 in the proxy log after a successful Zitadel
                    // login). oCIS 8.0.6's PROXY_OIDC_ACCESS_TOKEN_VERIFY_METHOD
                    // accepts only "none" or "jwt"; "none" validates the access
                    // token against the IdP's userinfo endpoint server-side
                    // (Zitadel /oidc/v1/userinfo), which is the supported method
                    // for opaque tokens. PROXY_OIDC_SKIP_USER_INFO must stay
                    // unset: it is incompatible with "none".
                    'PROXY_OIDC_ACCESS_TOKEN_VERIFY_METHOD' => 'none',
                ],
                'vars' => [
                    'client_id' => 'WEB_OIDC_CLIENT_ID',
                    'client_secret' => 'OCIS_OIDC_CLIENT_SECRET',
                    'issuer' => 'OCIS_OIDC_ISSUER',
                ],
                // oCIS web's real OIDC callback page, not the tool root. The
                // root used to be registered here, which made Zitadel 400 the
                // authorize request with "redirect_uri not allowed" (verified
                // live: /oauth/v2/authorize 400s for /oidc-callback.html but
                // 302s for /). OwnCloud web also renews tokens via its own
                // silent-redirect page — see oidcRedirectUris().
                'redirect_path' => '/oidc-callback.html',
            ],
            self::SIGN => [
                'deployment' => 'sign-documenso',
                'namespace' => 'larakube-shared',
                'secret' => 'sign-documenso-oidc',
                'static' => [
                    'NEXT_PUBLIC_DISABLE_OIDC_SIGNIN' => 'false',
                    // v2 has no NEXT_PRIVATE_OIDC_ALLOW_SIGNUP; the real control
                    // is NEXT_PUBLIC_DISABLE_OIDC_SIGNUP (inverted). false =
                    // auto-provision users on first SSO login.
                    'NEXT_PUBLIC_DISABLE_OIDC_SIGNUP' => 'false',
                ],
                'vars' => [
                    'client_id' => 'NEXT_PRIVATE_OIDC_CLIENT_ID',
                    'client_secret' => 'NEXT_PRIVATE_OIDC_CLIENT_SECRET',
                    // Documenso feeds this to NextAuth's `wellKnown`, which wants
                    // the full discovery URL, not the issuer base.
                    'well_known' => 'NEXT_PRIVATE_OIDC_WELL_KNOWN',
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
            self::LINK => [
                // Kutt has native OIDC support (server/passport.js) driven by
                // plain env vars — OIDC_ENABLED plus the standard trio. The
                // manifest already mounts the link-kutt-oidc secret, so this
                // case is what makes `sso:wire link` work end-to-end. Verified
                // against thedevs-network/kutt docs: redirect path is
                // /login/oidc, and OIDC_SCOPE defaults to "openid profile
                // email" (matches Zitadel's default scopes).
                'deployment' => 'link-kutt',
                'namespace' => 'larakube-shared',
                'secret' => 'link-kutt-oidc',
                'static' => [
                    'OIDC_ENABLED' => 'true',
                ],
                'vars' => [
                    'client_id' => 'OIDC_CLIENT_ID',
                    'client_secret' => 'OIDC_CLIENT_SECRET',
                    'issuer' => 'OIDC_ISSUER',
                ],
                'redirect_path' => '/login/oidc',
            ],
            // SendRec has NO generic OIDC provider of its own — it hardcodes
            // Google/Microsoft/GitHub (GOOGLE_CLIENT_ID, MICROSOFT_CLIENT_ID,
            // GITHUB_SSO_CLIENT_ID; callback /api/auth/sso/{provider}/callback),
            // so Zitadel can never be an in-app login here. That is why it is a
            // FORWARDAUTH tool (ADR 0006): sso:wire gates it at Traefik via the
            // shared SSO proxy and deliberately sets nothing on the pod. The
            // redirect_path below is the PROXY's callback on auth.<domain>, not
            // a SendRec route, and the vars are unused on this path — do not
            // "fix" them to match SendRec, and do not expect an SSO button on
            // its login screen: the gate authorises access, the app still keeps
            // its own accounts.
            self::RECORD => [
                'deployment' => 'record-sendrec',
                'namespace' => 'larakube-shared',
                'secret' => 'record-sendrec-oidc',
                'static' => [],
                'vars' => [
                    'client_id' => 'OIDC_CLIENT_ID',
                    'client_secret' => 'OIDC_CLIENT_SECRET',
                    'issuer' => 'OIDC_ISSUER',
                ],
                'redirect_path' => '/oauth2/callback',
            ],
            self::CHAT => [
                'deployment' => 'chat-synapse',
                'namespace' => 'larakube-shared',
                'secret' => 'chat-oidc',
                'static' => [
                    'SYNAPSE_OIDC_ENABLED' => 'true',
                ],
                'vars' => [
                    'client_id' => 'SYNAPSE_OIDC_CLIENT_ID',
                    'client_secret' => 'SYNAPSE_OIDC_CLIENT_SECRET',
                    'issuer' => 'SYNAPSE_OIDC_ISSUER',
                ],
                'redirect_path' => '/_synapse/client/oidc/callback',
            ],
            self::SHEETS => [
                'deployment' => 'sheet-teable',
                'namespace' => 'larakube-shared',
                'secret' => 'sheet-teable-oidc',
                'static' => [
                    'SOCIAL_AUTH_PROVIDERS' => 'oidc',
                    // Without the email scope the IdP returns no email claim,
                    // Teable's strategy reads emails?.[0].value as undefined and
                    // every login dies at the callback with a 401 "No email
                    // provided from OIDC" — which looks like a Zitadel problem
                    // but is this variable missing. passport adds `openid`
                    // itself, so the two below are what Teable's docs specify.
                    'BACKEND_OIDC_OTHER' => '{"scope":["email","profile"]}',
                ],
                'vars' => [
                    'client_id' => 'BACKEND_OIDC_CLIENT_ID',
                    'client_secret' => 'BACKEND_OIDC_CLIENT_SECRET',
                    'issuer' => 'BACKEND_OIDC_ISSUER',
                    'auth_url' => 'BACKEND_OIDC_AUTHORIZATION_URL',
                    'token_url' => 'BACKEND_OIDC_TOKEN_URL',
                    'userinfo_url' => 'BACKEND_OIDC_USER_INFO_URL',
                    'callback_url' => 'BACKEND_OIDC_CALLBACK_URL',
                ],
                // Verified against the running container's route map and
                // Teable's OIDC docs: auth mounts at /api/auth and NOTHING in
                // Teable sits under /api/v1. A wrong path here surfaces as a
                // redirect_uri error that reads like a Zitadel misconfiguration.
                'redirect_path' => '/api/auth/oidc/callback',
            ],
            self::SECRETS => [
                'deployment' => 'openbao-backend',
                'namespace' => 'larakube-secrets',
                'secret' => 'openbao-oidc',
                'static' => [],
                'vars' => [],
                'redirect_path' => '/v1/auth/oidc/oidc/callback',
            ],
            default => null,
        };
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

        // OpenBao uses different callback paths for its UI and API
        if ($this === self::SECRETS) {
            return [
                "https://{$toolHost}{$basePath}",
                "https://{$toolHost}/ui/vault/auth/oidc/oidc/callback",
            ];
        }

        // oCIS web does its token exchange on /oidc-callback.html AND renews
        // expired tokens in the background through /oidc-silent-redirect.html
        // (both served by the web service — verified live 2026-07-31). Missing
        // either one fails the corresponding Zitadel request with a
        // redirect_uri error.
        if ($this === self::DRIVE) {
            return [
                "https://{$toolHost}/oidc-callback.html",
                "https://{$toolHost}/oidc-silent-redirect.html",
            ];
        }

        return ["https://{$toolHost}{$basePath}"];
    }

    /**
     * Post-logout redirect URIs a tool's SPA registers on the IdP so Zitadel
     * accepts the OIDC RP-initiated logout redirect (post_logout_redirect_uri
     * must be pre-registered or end_session 400s with "post_logout_redirect_uri
     * invalid"). Empty for tools that don't use RP-initiated logout.
     *
     * oCIS web always sends its own origin root — the bundled UserManager
     * defaults `post_logout_redirect_uri` to the site root (verified in the
     * served web-runtime bundle: `post_logout_redirect_uri: br(Ze, "/")`, and
     * live 2026-08-01: logout from drive 400'd exactly because
     * https://drive.<host>/ was not registered). The tool root is IdP-agnostic,
     * so this stays correct no matter which provider sso:wire points at.
     */
    public function oidcPostLogoutRedirectUris(string $toolHost): array
    {
        return match ($this) {
            self::DRIVE => ["https://{$toolHost}/"],
            default => [],
        };
    }

    /**
     * The Traefik Middleware {name, namespace} a --vpn-only-capable tool's
     * ingress annotation already references (e.g.
     * larakube-shared-desk-vpn-only@kubernetescrd → name "desk-vpn-only" in
     * "larakube-shared"). NOT derivable from $this->value — several tools'
     * ingress partials reference their SharedClusterService label instead
     * (errors→glitchtip-web, git→forgejo, sheets→sheet, uptime→uptime-kuma),
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
            self::SECRETS => ['name' => 'openbao-vpn-only', 'namespace' => 'larakube-secrets'],
            self::ERRORS => ['name' => 'glitchtip-web-vpn-only', 'namespace' => 'larakube-shared'],
            self::UPTIME => ['name' => 'uptime-kuma-vpn-only', 'namespace' => 'larakube-shared'],
            self::GIT => ['name' => 'forgejo-vpn-only', 'namespace' => 'larakube-shared'],
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
            self::RECORD => ['name' => 'record-vpn-only', 'namespace' => 'larakube-shared'],
            default => null,
        };
    }

    /**
     * The canonical Kubernetes Deployment name for this tool.
     */
    public function deploymentName(): string
    {
        return match ($this) {
            self::ANALYTICS => 'analytics-umami',
            self::CHAT => 'chat-synapse',
            self::CRM => 'crm-twenty',
            self::DESK => 'desk-freescout',
            self::DRIVE => 'drive-ocis',
            self::ERRORS => 'glitchtip-web',
            self::FLOW => 'flow-n8n',
            self::GIT => 'forgejo',
            self::INSIGHTS => 'insights-metabase',
            self::LINK => 'link-kutt',
            self::MAIL => 'stalwart',
            self::MONITOR => 'grafana',
            self::NOTES => 'notes-outline',
            self::PASSWORDS => 'vaultwarden',
            self::RECORD => 'record-sendrec',
            self::SECRETS => 'openbao-backend',
            self::SHEETS => 'sheet-teable',
            self::SIGN => 'sign-documenso',
            self::SSO => 'sso-zitadel',
            self::SUPPORT => 'support-chatwoot',
            self::TASKS => 'tasks-planka',
            self::UPTIME => 'uptime-kuma',
            self::VPN => 'netbird-management',
            self::WEBMAIL => 'webmail-bulwark',
            self::DNS => 'external-dns',
        };
    }

    /**
     * The Kubernetes Secret name and namespace to sync secrets from OpenBao into.
     * null when the tool has no secrets (or none migrated from the original secrets backend).
     *
     * @return array{namespace: string, secret: string}|null
     */
    public function openbaoSyncConfig(): ?array
    {
        return match ($this) {
            self::MAIL => [
                'namespace' => $this->namespace(),
                'secret' => 'stalwart',
                'keys' => [
                    'STALWART_STORE_PASSWORD',
                    'STALWART_S3_KEY_ID',
                    'STALWART_S3_SECRET_KEY',
                    'STALWART_MAIL_PASSWORD',
                    'STALWART_MAIL_SENDER',
                    'STALWART_CLOUDFLARE_TOKEN',
                ],
            ],
            self::GIT => [
                'namespace' => $this->namespace(),
                'secret' => 'forgejo',
                'keys' => [
                    'FORGEJO_DB_PASSWORD',
                ],
            ],
            self::PASSWORDS => [
                'namespace' => $this->namespace(),
                'secret' => 'vaultwarden-secrets',
                'keys' => [
                    'VAULTWARDEN_DATABASE_URL',
                ],
            ],
            default => null,
        };
    }

    /**
     * The Kubernetes Secret + key that holds this tool's Commons database
     * password, for `secrets:wire` to hand over to OpenBao static-role
     * rotation. null for tools with no simple single-key password (e.g. one
     * baked into a composed connection URL, or no Commons DB at all) —
     * those need bespoke handling, not this generic path.
     *
     * @return array{namespace: string, secret: string, key: string}|null
     */
    public function dbSecretRef(): ?array
    {
        return match ($this) {
            self::SIGN => ['namespace' => $this->namespace(), 'secret' => 'sign-documenso-secrets', 'key' => 'db-password'],
            self::RECORD => ['namespace' => $this->namespace(), 'secret' => 'record-sendrec-secrets', 'key' => 'db-password'],
            self::SSO => ['namespace' => $this->namespace(), 'secret' => 'sso-secrets', 'key' => 'db-password'],
            self::LINK => ['namespace' => $this->namespace(), 'secret' => 'link-kutt-secrets', 'key' => 'db-password'],
            default => null,
        };
    }

    /** @return list<string> */
    public function commonsBuckets(): array
    {
        return $this->commonsBucketList();
    }

    /**
     * Reverse lookup: which tool owns this Commons registry entry, by DB or
     * bucket name. Lets any command that reads the Plex tenant registry tell
     * "this is actually a cluster tool" from "this is someone's app" without
     * hand-maintaining a second list of tool names — a stale copy of that list
     * is exactly how the old plex:show still said 'gitea' after the Forgejo
     * rename.
     */
    public static function forCommonsResource(string $name): ?self
    {
        foreach (self::cases() as $tool) {
            if (in_array($name, $tool->commonsDatabases(), true) || in_array($name, $tool->commonsBuckets(), true)) {
                return $tool;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function commonsDatabaseList(): array
    {
        return match ($this) {
            self::ANALYTICS => ['umami'],
            self::CHAT => ['chat_matrix'],
            self::CRM => ['crm_twenty'],
            self::DESK => ['freescout'],
            self::ERRORS => ['glitchtip'],
            self::FLOW => ['n8n', 'windmill'],
            self::GIT => ['forgejo'],
            self::INSIGHTS => ['metabase'],
            self::LINK => ['link_kutt'],
            self::NOTES => ['outline'],
            self::MAIL => ['stalwart'],
            self::PASSWORDS => ['vaultwarden'],
            self::RECORD => ['record_sendrec'],
            self::SECRETS => [],
            self::SHEETS => ['teable'],
            self::SIGN => ['sign_documenso'],
            self::SSO => ['zitadel'],
            self::SUPPORT => ['support_chatwoot'],
            self::TASKS => ['tasks_planka'],
            default => [],
        };
    }

    /** @return list<string> */
    private function commonsBucketList(): array
    {
        return match ($this) {
            self::DRIVE => ['drive-ocis'],
            self::GIT => ['forgejo-storage', 'forgejo-packages', 'forgejo-lfs'],
            self::MAIL => ['stalwart'],
            self::NOTES => ['notes-storage'],
            self::RECORD => ['record-storage'],
            self::SHEETS => ['sheet-public', 'sheet-private'],
            self::SIGN => ['sign-storage'],
            default => [],
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
