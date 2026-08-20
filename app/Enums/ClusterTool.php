<?php

namespace App\Enums;

use App\Contracts\ClusterToolVendor;
use App\Contracts\ConfiguresViaConfigFile;
use App\Contracts\HasBaselineFlags;
use App\Contracts\HasClusterSecretDbKey;
use App\Contracts\HasCommonsBuckets;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasCommonsRedisKeys;
use App\Contracts\HasDbSecretRef;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasMeetBridge;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasOpenbaoSync;
use App\Contracts\HasRotatableDatabasePassword;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasSsoLicenseCaveat;
use App\Contracts\HasWhiteLabel;
use App\Contracts\HasWorkloadComponents;
use App\Contracts\UsesCliOidc;
use App\Contracts\UsesForwardAuth;
use App\Data\ClusterToolComponentData;
use App\Vendors\AnalyticsTool;
use App\Vendors\CrmTool;
use App\Vendors\DashboardTool;
use App\Vendors\DnsTool;
use App\Vendors\DriveTool;
use App\Vendors\ErrorTool;
use App\Vendors\InsightTool;
use App\Vendors\LinkTool;
use App\Vendors\MailTool;
use App\Vendors\MeetTool;
use App\Vendors\MonitorTool;
use App\Vendors\NoteTool;
use App\Vendors\PasswordTool;
use App\Vendors\RecordTool;
use App\Vendors\ResumeTool;
use App\Vendors\SecretTool;
use App\Vendors\SheetTool;
use App\Vendors\SignTool;
use App\Vendors\SsoTool;
use App\Vendors\SupportTool;
use App\Vendors\UptimeTool;
use App\Vendors\VpnTool;
use App\Vendors\WebmailTool;
use App\Vendors\YopassTool;
use LogicException;

enum ClusterTool: string implements HasWorkloadComponents
{
    /**
     * The vendor backing this category — an enum case for a multi-vendor
     * category (DATA, FLOW, GIT, CHAT, DESIGN, TASKS, DESK), a plain class
     * instance for a single-vendor one (the other 22 categories). Total
     * over all 29 cases — every category has exactly one vendor.
     */
    public function vendor(?string $engine = null): ClusterToolVendor
    {
        return match ($this) {
            self::DATA => DataTool::tryFrom((string) $engine) ?? DataTool::DIRECTUS,
            self::FLOW => FlowTool::tryFrom((string) $engine) ?? FlowTool::N8N,
            self::GIT => GitForgeTool::FORGEJO,
            self::CHAT => ChatTool::MATRIX,
            self::DESIGN => DesignTool::PENPOT,
            self::TASKS => TaskTool::PLANKA,
            self::DESK => DeskTool::FREESCOUT,
            self::MAIL => new MailTool,
            self::SECRETS => new SecretTool,
            self::DRIVE => new DriveTool,
            self::PASSWORDS => new PasswordTool,
            self::SIGN => new SignTool,
            self::RECORD => new RecordTool,
            self::SSO => new SsoTool,
            self::LINK => new LinkTool,
            self::WEBMAIL => new WebmailTool,
            self::NOTES => new NoteTool,
            self::SHEETS => new SheetTool,
            self::MONITOR => new MonitorTool,
            self::CRM => new CrmTool,
            self::SUPPORT => new SupportTool,
            self::INSIGHTS => new InsightTool,
            self::ERRORS => new ErrorTool,
            self::ANALYTICS => new AnalyticsTool,
            self::MEET => new MeetTool,
            self::DNS => new DnsTool,
            self::UPTIME => new UptimeTool,
            self::VPN => new VpnTool,
            self::DASHBOARD => new DashboardTool,
            self::RESUME => new ResumeTool,
            self::PASTE => new YopassTool,
        };
    }

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
            self::TASKS => 'Project Management (Planka)',
            self::SIGN => 'Document Signing (Documenso)',
            self::SUPPORT => 'Customer Support (Chatwoot)',
            self::LINK => 'Link Management (Kutt)',
            self::CRM => 'CRM (Twenty)',
            self::DATA => 'Headless CMS & Data API (PocketBase or Directus)',
            self::RECORD => 'Screen Recording & Sharing (Sendrec)',
            self::DASHBOARD => 'Kubernetes Control Plane (Headlamp)',
            self::MEET => 'Video Meetings (LiveKit)',
            self::DESIGN => 'Design & Prototyping (Penpot)',
            self::RESUME => 'Resume Builder (Reactive Resume)',
            self::PASTE => 'Secure Paste Sharing (Yopass)',
        };
    }

    public function productName(?string $engine = null): string
    {
        return $this->vendor($engine)->getLabel() ?? $this->value;
    }

    /**
     * A terminal-safe emoji icon that visually identifies this tool at a glance.
     * Rendered in `tool:list`, `tool:show`, and init command headers.
     */
    public function icon(): string
    {
        return match ($this) {
            self::ANALYTICS => '📊',
            self::CHAT => '💬',
            self::CRM => '🤝',
            self::DATA => '🗄️',
            self::DESK => '🎫',
            self::DNS => '🌐',
            self::DRIVE => '☁️',
            self::ERRORS => '🐛',
            self::FLOW => '⚡',
            self::GIT => '🦊',
            self::INSIGHTS => '📈',
            self::LINK => '🔗',
            self::MAIL => '✉️',
            self::MONITOR => '📡',
            self::NOTES => '📝',
            self::PASSWORDS => '🔐',
            self::RECORD => '🎥',
            self::SECRETS => '🔒',
            self::SHEETS => '📋',
            self::SIGN => '✍️',
            self::SSO => '🪪',
            self::SUPPORT => '💬',
            self::TASKS => '✅',
            self::UPTIME => '🟢',
            self::VPN => '🔑',
            self::WEBMAIL => '📬',
            self::DASHBOARD => '☸️',
            self::MEET => '🎥',
            self::DESIGN => '🎨',
            self::RESUME => '📄',
            self::PASTE => '🔥',
        };
    }

    /**
     * The operator-facing branded name shown in CLI output and Cinny/UI titles.
     * This is the static default — init commands may accept an --app-name flag
     * to override it and persist the custom value to the cluster registry under
     * the 'brandName' key, making it visible to `tool:list` and `tool:show`
     * without any project file involvement.
     */
    public function brandName(): string
    {
        return match ($this) {
            self::ANALYTICS => 'Analytics',
            self::CHAT => 'Chat',
            self::CRM => 'CRM',
            self::DATA => 'Data',
            self::DESK => 'Help Desk',
            self::DNS => 'DNS',
            self::DRIVE => 'Drive',
            self::ERRORS => 'Error Tracking',
            self::FLOW => 'Automation',
            self::GIT => 'Git',
            self::INSIGHTS => 'Insights',
            self::LINK => 'Links',
            self::MAIL => 'Mail',
            self::MONITOR => 'Monitor',
            self::NOTES => 'Notes',
            self::PASSWORDS => 'Passwords',
            self::RECORD => 'Record',
            self::SECRETS => 'Secrets',
            self::SHEETS => 'Sheets',
            self::SIGN => 'Sign',
            self::SSO => 'SSO',
            self::SUPPORT => 'Support',
            self::TASKS => 'Tasks',
            self::UPTIME => 'Uptime',
            self::VPN => 'VPN',
            self::WEBMAIL => 'Webmail',
            self::DASHBOARD => 'Dashboard',
            self::MEET => 'Meet',
            self::DESIGN => 'Design',
            self::RESUME => 'Resume',
            self::PASTE => 'Paste',
        };
    }

    /**
     * Whitelabeling specification for tools that support custom branding (app name / logo)
     * via environment variables, config keys, or Nginx sub_filter injection.
     * null for tools with no env-var-driven whitelabeling support.
     *
     * @return array{app_name_key?: string, logo_url_key?: string, sub_filter?: bool}|null
     */
    public function whiteLabel(): ?array
    {
        $vendor = $this->vendor();
        if ($vendor instanceof HasWhiteLabel) {
            return $vendor->whiteLabel();
        }

        return null;
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
            self::DATA => SharedClusterService::DATA,
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
            self::DASHBOARD => SharedClusterService::DASHBOARD,
            self::MEET => SharedClusterService::MEET,
            self::DESIGN => SharedClusterService::DESIGN,
            self::RESUME => SharedClusterService::RESUME,
            self::PASTE => SharedClusterService::PASTE,
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

    /**
     * Which tool + component owns a given live Deployment name, across every
     * engine variant this tool has — used by dynamic PVC backup discovery to
     * decide whether a Deployment is backup-worthy without a hardcoded list.
     * null when nothing claims it (Prometheus, or any other unmanaged
     * Deployment) — the reverse lookup itself IS the exclusion mechanism.
     *
     * Exact matches are checked across every tool/component/engine BEFORE any
     * instance-suffix (prefix) match is considered, so a genuinely different
     * component whose name happens to prefix-match another tool's (e.g.
     * "forgejo-runner" starting with "forgejo-") is never mistaken for an
     * instance-suffixed copy of it.
     *
     * @return array{tool: self, component: ClusterToolComponentData}|null
     */
    public static function forDeployment(string $deploymentName): ?array
    {
        foreach (self::cases() as $tool) {
            foreach ($tool->engineCandidates() as $engine) {
                foreach ($tool->components(engine: $engine) as $component) {
                    if ($component->deployment === $deploymentName) {
                        return ['tool' => $tool, 'component' => $component];
                    }
                }
            }
        }

        $best = null;
        foreach (self::cases() as $tool) {
            foreach ($tool->engineCandidates() as $engine) {
                foreach ($tool->components(engine: $engine) as $component) {
                    if (! str_starts_with($deploymentName, "{$component->deployment}-")) {
                        continue;
                    }

                    if ($best === null || strlen($component->deployment) > strlen($best['component']->deployment)) {
                        $best = ['tool' => $tool, 'component' => $component];
                    }
                }
            }
        }

        return $best;
    }

    /**
     * The tool that owns a given grantableRoles() key, or null if none claims
     * it. Per-app secrets grants (secrets:grant) mint dynamic role keys
     * ("secrets-{app}-{environment}-{role}") that can't appear in SECRETS's
     * static rbacRoles() map — the app name is arbitrary — but they live on
     * the same RBAC project, so the "secrets-" prefix alone is enough to
     * route them here. This is what lets sso:revoke's discovery/--role fast
     * path find and revoke them too, without a second revoke command having
     * to duplicate that machinery.
     */
    public static function forGrantableRoleKey(string $roleKey): ?self
    {
        if (str_starts_with($roleKey, 'secrets-')) {
            return self::SECRETS;
        }

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
        $vendor = $this->vendor();
        if ($vendor instanceof HasClusterSecretDbKey) {
            return $vendor->clusterSecretDbKey($tenant);
        }

        return self::tenantKey($tenant);
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
        $vendor = $this->vendor();
        if ($vendor instanceof HasCommonsRedisKeys) {
            return $vendor->commonsRedisKeys();
        }

        return [];
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
            self::DATA => ['pocketbase' => 'PocketBase', 'directus' => 'Directus'],
            self::DRIVE => ['ocis' => 'oCIS'],
            self::FLOW => ['n8n' => 'n8n', 'windmill' => 'Windmill'],
            self::TASKS => ['planka' => 'Planka'],
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
     * Whether this tool is release-ready and may be advertised to operators.
     *
     * Unshipped tools are hidden from every listing/prompt UI (tool:list,
     * tool:add, tool:show, and the wire commands' candidate loops), and their
     * per-tool commands refuse to run with a "not yet shipped" message. The
     * case itself, its reverse lookups (forDeployment(), forCommonsResource(),
     * forCommonsTenant(), forGrantableRoleKey()) and its components stay fully
     * intact so live resources are still discovered, backed up, and torn down.
     *
     * Current unshipped tools, and the gap that withholds them:
     *  - ANALYTICS (Umami): no OIDC/SSO integration and no SMTP client wiring —
     *    it cannot join the fleet's identity or mail story yet.
     *  - UPTIME (Uptime Kuma): no OIDC/SSO integration and no programmatic SMTP
     *    story (its mail settings are UI-only, over SQLite) — nothing to wire.
     *
     * PASTE (Yopass) is shipped despite having no OIDC/SSO story — that's a
     * deliberate exception, not an oversight: zero-knowledge/no-account
     * secret sharing is the whole point of the tool, so "no auth" is the
     * design, not a gap. Note it's unauthenticated to anyone with the link —
     * fine for the tool's own zero-knowledge model, but don't add ForwardAuth
     * gating casually: this cluster's oauth2-proxy is ONE shared pod across
     * every ForwardAuth tool (ADR 0006) with a single global --allowed-group,
     * so a second ForwardAuth-gated tool with a different (or no) role
     * requirement will silently override another tool's gate — needs real
     * per-tool group scoping first. Shipped 2026-08-20, before a live smoke
     * test — verify the happy path (paste:init → paste:show a real secret →
     * confirm burn-after-read) the moment there's a spare minute.
     */
    public function isShipped(): bool
    {
        return match ($this) {
            self::ANALYTICS, self::UPTIME => false,
            default => true,
        };
    }

    /**
     * Every case that is release-ready, for listings and prompts.
     *
     * @return list<self>
     */
    public static function shippedCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $tool) => $tool->isShipped()));
    }

    /**
     * Get an associative array of value => label for prompts.
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::shippedCases() as $tool) {
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
    /**
     * Feature flags this tool needs regardless of which (if any) integration
     * gets wired — access tokens and MCP don't depend on SSO or SMTP, so
     * :init seeds them directly rather than waiting on sso:wire/mail:wire to
     * ever run. mail:wire's and sso:wire's own PENPOT_FLAGS defaults below
     * fold this in too, so there's exactly one place these flag names are
     * spelled. See docs/decisions/0013-design-init-idempotent-flags.md.
     *
     * @return list<string>
     */
    public function baselineFlags(): array
    {
        $vendor = $this->vendor();

        return $vendor instanceof HasBaselineFlags ? $vendor->baselineFlags() : [];
    }

    public function smtpEnv(?string $engine = null, ?string $instance = null): ?array
    {
        $vendor = $this->vendor($engine);
        if ($vendor instanceof HasSmtpWiring) {
            $schema = $vendor->smtpEnv($instance);

            return $schema === null ? null : ['namespace' => $this->namespace(), 'also_patch' => $this->alsoPatchDeployments($instance, $engine)] + $schema;
        }

        return null;
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
    /**
     * Whether this tool can be connected to the shared LiveKit SFU by
     * `meet:wire`. Only Matrix today — it is the one tool with a bridge
     * (lk-jwt-service) that translates its identity into LiveKit tokens.
     * Laravel apps consume Meet through their project blueprint, not here.
     */
    public function hasMeetWire(): bool
    {
        return $this->vendor() instanceof HasMeetBridge;
    }

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
            // Single tier, not admin/viewer like the others above: Headlamp's
            // ServiceAccount is bound to cluster-admin with no lesser role to
            // offer (k8s.dashboard.headlamp.blade.php's ClusterRoleBinding),
            // and it runs -in-cluster — every logged-in session shares that
            // one ServiceAccount's token, so OIDC login is the ONLY gate
            // between "authenticated Zitadel user" and full cluster-admin.
            // Must never be open-to-org.
            self::DASHBOARD => [
                'dashboard-admin' => 'Full cluster-admin access via the Headlamp Kubernetes dashboard',
            ],
            // Single login-gate role, not admin/viewer tiers: confirmed none
            // of these six apps' native OIDC consumes a Zitadel role/group
            // claim to set in-app permission tiers (unlike oCIS's
            // ocisRoles-driven admin/user split) — a second Zitadel-level
            // tier would be purely decorative. Each app's own admin panel is
            // where finer-grained in-app roles get set once someone's in.
            // Added 2026-08-20 after a partner org's ORG_OWNER (created by
            // sso:org) could read internal Outline docs — every SSO-wired
            // tool without a rbacRoles() entry admits ANY authenticated
            // Zitadel user, regardless of which org they belong to.
            self::NOTES => ['outline-user' => 'Can log in to Outline'],
            self::PASSWORDS => ['vaultwarden-user' => 'Can log in to Vaultwarden'],
            self::LINK => ['kutt-user' => 'Can log in to Kutt'],
            self::RESUME => ['reactive-resume-user' => 'Can log in to Reactive Resume'],
            self::SIGN => ['documenso-user' => 'Can log in to Documenso'],
            self::SHEETS => ['teable-user' => 'Can log in to Teable'],
            // ForwardAuth (ADR 0006), not native OIDC — wireForwardAuth()
            // reads this to also gate the shared sso-proxy's
            // --allowed-groups, not just to route onto rbacProjectName().
            self::RECORD => ['record-user' => 'Can log in to Sendrec'],
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
        return $this->vendor() instanceof UsesForwardAuth;
    }

    /**
     * Whether more than one named `--instance` of this tool can coexist on
     * one cluster. Two distinct reasons a tool is `false` here:
     *
     *  - A hard technical blocker: CHAT (Synapse TURN) and MEET (LiveKit SFU)
     *    bind `hostPort` (3478 / 7881-7882) — a second instance collides on
     *    the same node. GIT (Forgejo) exposes SSH via a fixed-port
     *    `LoadBalancer` (2222), same collision risk on a single-node cluster.
     *  - An architectural singleton: MAIL/SSO/SECRETS/MONITOR/VPN are each
     *    "the one X for this cluster" that every other tool's mail:wire/
     *    sso:wire/SyncsClusterSecrets/monitor:init assumes exists exactly
     *    once. WEBMAIL is 1:1 bound to the one Stalwart. DASHBOARD is one
     *    view into the one cluster. DNS already has its own multi-tenancy
     *    scheme keyed by `--zone`, not this generic `--instance` mechanism.
     *
     * Default `true` (plain ClusterIP+Ingress HTTP app, Commons-backed or
     * embedded-SQLite with a PVC-per-instance) so a newly added tool has to
     * opt OUT deliberately rather than silently inherit a hostPort trap. Only
     * DATA and NOTES actually have `--instance` wired into their `:init`
     * today — `true` here means "no known blocker", not "already built".
     */
    public function supportsMultipleInstances(): bool
    {
        return match ($this) {
            self::CHAT, self::MEET, self::GIT,
            self::MAIL, self::SSO, self::SECRETS, self::MONITOR, self::VPN, self::WEBMAIL, self::DASHBOARD,
            self::DNS => false,
            default => true,
        };
    }

    /**
     * Whether `{tool}:remove --domain=X` actually targets instance X, rather
     * than silently accepting the flag and then tearing down the one real
     * installation regardless of what was passed.
     *
     * Deliberately narrower than supportsMultipleInstances() above: that
     * method answers "is there a known architectural blocker to this tool
     * ever supporting more than one instance" and defaults `true` — a
     * forward-looking, optimistic default meant for things like the generic
     * `-{instance}` suffixing in dbSecretRef()/openbaoSyncConfig(), which is
     * harmless to compute even for a tool that never gets a second instance.
     *
     * This method instead answers "does this tool's :remove command have
     * real per-instance teardown logic TODAY" — and defaults `false`, because
     * most tools' teardown() hardcodes fixed resource names and completely
     * ignores $instance/--domain. Only DATA, NOTES, CRM and DESIGN currently
     * resolve --domain to a specific registered instance before tearing it
     * down; every other tool would silently ignore --domain and delete the
     * one real installation no matter what host was passed, which is the
     * footgun this method exists to let the :remove guard close.
     */
    public function hasInstanceAwareRemoval(): bool
    {
        return match ($this) {
            self::DATA, self::NOTES, self::CRM, self::DESIGN => true,
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
        return $this->vendor($engine) instanceof ConfiguresViaConfigFile;
    }

    /**
     * A short operator-facing warning when this tool's SSO integration is real
     * (oidcEnv() vars are genuinely read by the app) but gated behind a paid
     * license even for self-hosted use. sso:wire still runs and prepares the
     * wiring — so nothing needs to be redone once a license is bought — but
     * login will not work until then, and the CLI needs to say so loudly
     * rather than let a successful "wired" message imply a working login.
     * Null when SSO just works.
     */
    public function ssoLicenseCaveat(?string $engine = null): ?string
    {
        if ($this !== self::DATA) {
            return null;
        }

        // Unlike vendor()'s tryFrom()-then-Directus-fallback (which treats
        // an unspecified engine as "not pocketbase"), a null $engine here
        // means "caller didn't resolve one" and defaults to DATA's actual
        // default engine (pocketbase) — preserving the original's
        // `$engine ?? $this->defaultEngine()` semantics exactly.
        $vendor = DataTool::tryFrom($engine ?? $this->defaultEngine() ?? '') ?? DataTool::DIRECTUS;

        return $vendor instanceof HasSsoLicenseCaveat ? $vendor->ssoLicenseCaveat() : null;
    }

    /**
     * OIDC that is registered by running a command INSIDE the tool's pod rather
     * than by setting env vars, because the tool stores login sources in its own
     * database. Forgejo is the case: `forgejo admin auth add-oauth`. The Zitadel side
     * is identical — only the tool-side application differs.
     */
    public function usesCliOidc(): bool
    {
        return $this->vendor() instanceof UsesCliOidc;
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
     * @return array{deployment: string, namespace: string, secret: string, static?: array<string, string>, vars: array<string, string>, redirect_path: string, public_client?: bool, also_patch?: list<string>}|null
     */
    public function oidcEnv(?string $engine = null, ?string $instance = null): ?array
    {
        $vendor = $this->vendor($engine);
        if ($vendor instanceof HasOidcWiring) {
            $schema = $vendor->oidcEnv($instance);

            return $schema === null ? null : ['namespace' => $this->namespace(), 'also_patch' => $this->alsoPatchDeployments($instance, $engine)] + $schema;
        }

        return null;
    }

    /**
     * The OIDC redirect URI to register in Zitadel for this tool.
     *
     * @return array<int, string>
     */
    public function oidcRedirectUris(string $toolHost, array $aliasHosts = [], ?string $engine = null): array
    {
        $allHosts = array_values(array_unique(array_merge([$toolHost], $aliasHosts)));
        $schema = $this->oidcEnv($engine);
        if ($schema === null) {
            return [];
        }

        $basePath = $schema['redirect_path'];
        $uris = [];

        foreach ($allHosts as $h) {
            if ($this === self::SECRETS) {
                $uris[] = "https://{$h}{$basePath}";
                $uris[] = "https://{$h}/ui/vault/auth/oidc/oidc/callback";
            } elseif ($this === self::DRIVE) {
                $uris[] = "https://{$h}/oidc-callback.html";
                $uris[] = "https://{$h}/oidc-silent-redirect.html";
            } else {
                $uris[] = "https://{$h}{$basePath}";
            }
        }

        return array_values(array_unique($uris));
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
    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $vendor = $this->vendor();
        if ($vendor instanceof \App\Contracts\HasVpnWiring) {
            return $vendor->vpnMiddlewareTarget($instance);
        }

        return null;
    }

    public function presenceProbe(?string $instance = null): ?string
    {
        $vendor = $this->vendor();
        if ($vendor instanceof \App\Contracts\HasPresenceProbe) {
            return $vendor->presenceProbe($instance);
        }

        return $this->service()?->presenceProbe();
    }

    /**
     * The canonical Kubernetes Deployment name for this tool's PRIMARY
     * component. Delegates to components() so compound tools (CHAT, GIT,
     * DESIGN) and single-Deployment tools share one derivation.
     */
    public function deploymentName(?string $instance = null, ?string $engine = null): string
    {
        return $this->primaryComponent($instance, $engine)->deployment;
    }

    /**
     * This tool's sub-deployments, fully resolved for the given
     * instance/engine — exactly one PRIMARY, zero or more INGRESS/WORKER/
     * DATABASE. ~26 of 29 tools return exactly one PRIMARY component; CHAT,
     * GIT, and DESIGN return several, built from the same deployment names
     * their Blade manifests and (formerly hand-copied) teardown() resource
     * lists already used. `backupVolume`/`backupPath` are populated only
     * for the components InteractsWithBackup's hardcoded allow-list already
     * covers today (SECRETS, GIT, DRIVE, PASSWORDS, MAIL, CHAT's synapse
     * signing key) — every other component defaults to `backupVolume:
     * false` until a future backup-discovery pass audits it, so switching
     * backup discovery over to this representation cannot silently start
     * (or stop) backing up something no one has verified yet.
     *
     * @return list<ClusterToolComponentData>
     */
    public function components(?string $instance = null, ?string $engine = null): array
    {
        $vendor = $this->vendor($engine);
        if ($vendor instanceof HasWorkloadComponents) {
            return $vendor->components($instance, $engine);
        }

        if (! $vendor instanceof HasDeploymentBaseName) {
            throw new LogicException("{$this->value} vendor implements neither HasWorkloadComponents nor HasDeploymentBaseName.");
        }

        $base = $vendor->baseDeploymentName();
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";

        return [
            new ClusterToolComponentData(key: 'app', role: ClusterToolComponentRole::PRIMARY, deployment: $name($base)),
        ];
    }

    /** The tool's PRIMARY component — the app-logic deployment every non-compound-aware call site already assumed was the only one. */
    public function primaryComponent(?string $instance = null, ?string $engine = null): ClusterToolComponentData
    {
        foreach ($this->components($instance, $engine) as $component) {
            if ($component->role === ClusterToolComponentRole::PRIMARY) {
                return $component;
            }
        }

        throw new LogicException("{$this->value} declares no PRIMARY component — every tool must have exactly one.");
    }

    /** A specific named component, or null if this tool has none by that key. */
    public function componentByKey(string $key, ?string $instance = null, ?string $engine = null): ?ClusterToolComponentData
    {
        foreach ($this->components($instance, $engine) as $component) {
            if ($component->key === $key) {
                return $component;
            }
        }

        return null;
    }

    /**
     * Deployments that must also be patched with the PRIMARY component's
     * oidc/smtp secret — the general form of Penpot's frontend needing the
     * same OIDC client as its backend, so a future compound tool with a
     * secondary component needing the primary's credentials needs zero new
     * wire-command code, just a `sharesPrimarySecret: true` component.
     *
     * @return list<string>
     */
    public function alsoPatchDeployments(?string $instance = null, ?string $engine = null): array
    {
        return array_values(array_map(
            fn (ClusterToolComponentData $component) => $component->deployment,
            array_filter($this->components($instance, $engine), fn (ClusterToolComponentData $component) => $component->sharesPrimarySecret),
        ));
    }

    /**
     * Derive a Kubernetes-resource-naming-safe instance slug from a host —
     * the identifier every multi-instance tool's registry entry and
     * deploymentName()/commonsDatabases()/commonsBuckets() suffix uses.
     * Always derived from the FULL host, not just its leftmost label — two
     * different hosts that happen to share a leftmost label
     * (blog.siteA.com vs blog.siteB.com) must never collide on the same
     * Kubernetes Service name. This includes a tool's own conventional
     * default host (e.g. "data.example.com") — there is no bare-prefix/
     * 'main' escape hatch (ADR 0012, amended 2026-08-15). Confirmed live
     * 2026-08-09.
     */
    public function instanceSlugFromHost(string $host): string
    {
        $slug = strtolower(str_replace('.', '-', $host));
        $slug = trim((string) preg_replace('/[^a-z0-9-]/', '-', $slug), '-');

        // K8s Service names are DNS-1035 labels, max 63 chars. The longest
        // realistic prefix ("data-pocketbase-") is 17 chars — truncate+hash
        // defensively past ~40 for the slug itself rather than let a
        // pathologically long host make kubectl reject the apply.
        return strlen($slug) > 40 ? substr($slug, 0, 32).'-'.substr(md5($slug), 0, 6) : $slug;
    }

    /**
     * The Kubernetes Secret name and namespace to sync secrets from OpenBao into.
     * null when the tool has no secrets (or none migrated from the original secrets backend).
     *
     * @return array{namespace: string, secret: string}|null
     */
    public function openbaoSyncConfig(?string $instance = null): ?array
    {
        $vendor = $this->vendor();
        if ($vendor instanceof HasOpenbaoSync) {
            $config = ['namespace' => $this->namespace()] + $vendor->openbaoSyncConfig();

            if ($instance === null || $instance === '') {
                return $config;
            }

            $config['secret'] = "{$config['secret']}-{$instance}";

            return $config;
        }

        return null;
    }

    /**
     * The Kubernetes Secret + key that holds this tool's Commons database
     * password, for `secrets:wire` to hand over to OpenBao static-role
     * rotation. null for tools with no simple single-key password (e.g. one
     * baked into a composed connection URL, or no Commons DB at all) —
     * those need bespoke handling, not this generic path.
     */
    public function supportsDatabasePasswordRotation(?string $instance = null, ?string $engine = null): bool
    {
        return $this->vendor($engine) instanceof HasRotatableDatabasePassword;
    }

    public function dbSecretRef(?string $instance = null, ?string $engine = null): ?array
    {
        $vendor = $this->vendor($engine);
        if ($vendor instanceof HasDbSecretRef) {
            $ref = $vendor->dbSecretRef();
            if ($ref === null) {
                return null;
            }

            $ref = ['namespace' => $this->namespace()] + $ref;
            if ($instance === null || $instance === '') {
                return $ref;
            }

            $ref['secret'] = "{$ref['secret']}-{$instance}";

            return $ref;
        }

        return null;
    }

    /** @return list<string> */
    public function commonsBuckets(?string $instance = null, ?string $engine = null): array
    {
        $list = $this->commonsBucketList($engine);
        if ($instance === null || $instance === '') {
            return $list;
        }

        return array_map(fn (string $bucket) => "{$bucket}-{$instance}", $list);
    }

    /** @return list<string> */
    public function commonsDatabases(?string $instance = null, ?string $engine = null): array
    {
        $list = $this->commonsDatabaseList($engine);
        if ($instance === null || $instance === '') {
            return $list;
        }

        // Postgres identifiers with a hyphen need quoting everywhere they're
        // used (unquoted SQL parses `-` as subtraction) — a footgun this
        // codebase already avoids for CRM's hand-rolled equivalent
        // (CrmTool::commonsDatabaseList()). Instance slugs come from
        // instanceSlugFromHost(), which is hyphen-heavy by design (dashed
        // hostnames), so convert them here too rather than leaving a mixed
        // `db_instance-with-hyphens` name.
        $dbInstance = str_replace('-', '_', $instance);

        return array_map(fn (string $db) => "{$db}_{$dbInstance}", $list);
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

    /**
     * Every engine slug worth checking components() against — every real
     * engine for a multi-engine tool, or [null] (the single "no engine"
     * case) for everything else. Shared by forDeployment()'s exhaustive scan.
     *
     * @return list<string|null>
     */
    private function engineCandidates(): array
    {
        $engines = array_keys($this->engines());

        return $engines !== [] ? $engines : [null];
    }

    /** @return list<string> */
    private function commonsDatabaseList(?string $engine = null): array
    {
        // FLOW with no resolved engine must report BOTH n8n and windmill
        // tenants — teardown calls this with no $engine to drop whichever
        // engine's tenant DB exists, guaranteeing a clean slate after an
        // engine switch (see FlowInitCommand::removeFlow()). This has to
        // run before the generic vendor() dispatch below, which would
        // otherwise default a null $engine to N8N's list alone.
        if ($this === self::FLOW && $engine === null) {
            return array_merge(...array_map(fn (FlowTool $c) => $c->commonsDatabaseList(), FlowTool::cases()));
        }

        $vendor = $this->vendor($engine);
        if ($vendor instanceof HasCommonsDatabases) {
            return $vendor->commonsDatabaseList();
        }

        return [];
    }

    /** @return list<string> */
    private function commonsBucketList(?string $engine = null): array
    {
        $vendor = $this->vendor($engine);
        if ($vendor instanceof HasCommonsBuckets) {
            return $vendor->commonsBucketList();
        }

        return [];
    }

    case ANALYTICS = 'analytics';
    case CHAT = 'chat';
    case MEET = 'meet';
    case CRM = 'crm';
    case DATA = 'data';
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
    case DASHBOARD = 'dashboard';
    case DESIGN = 'design';
    case RESUME = 'resume';
    case PASTE = 'paste';
}
