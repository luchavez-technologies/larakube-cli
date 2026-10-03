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
use App\Contracts\HasVpnWiring;
use App\Contracts\HasWhiteLabel;
use App\Contracts\HasWorkloadComponents;
use App\Contracts\UsesCliOidc;
use App\Contracts\UsesForwardAuth;
use App\Data\ClusterToolComponentData;
use App\Data\ToolInstance;
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
     * category (DATA, GIT, CHAT, DESIGN, TASKS), the engine's class in
     * app/Tools for FLOW, a plain class instance for a single-vendor one.
     * Every category has exactly one vendor.
     */
    public function vendor(?string $engine = null): ClusterToolVendor
    {
        if ($this->isLegacy()) {
            return $this->canonicalTool($engine)->vendor($engine);
        }

        return match ($this) {
            self::POCKETBASE => DataTool::POCKETBASE,
            self::DIRECTUS => DataTool::DIRECTUS,
            self::N8N => (FlowTool::tryFrom((string) $engine) ?? FlowTool::N8N)->tool(),
            self::WINDMILL => FlowTool::WINDMILL->tool(),
            self::FORGEJO => GitForgeTool::FORGEJO,
            self::MATRIX => ChatTool::MATRIX,
            self::DESIGN, self::PENPOT => DesignTool::PENPOT,
            self::TASKS, self::PLANKA => TaskTool::PLANKA,
            self::MAIL, self::STALWART => new MailTool,
            self::SECRETS, self::OPENBAO => new SecretTool,
            self::DRIVE, self::OCIS => new DriveTool,
            self::PASSWORDS, self::VAULTWARDEN => new PasswordTool,
            self::SIGN, self::DOCUMENSO => new SignTool,
            self::RECORD, self::SENDREC => new RecordTool,
            self::SSO, self::ZITADEL => new SsoTool,
            self::LINK, self::KUTT => new LinkTool,
            self::WEBMAIL, self::BULWARK => new WebmailTool,
            self::NOTES, self::OUTLINE => new NoteTool,
            self::SHEETS, self::TEABLE => new SheetTool,
            self::MONITOR, self::GRAFANA => new MonitorTool,
            self::CRM, self::TWENTY => new CrmTool,
            self::SUPPORT, self::CHATWOOT => new SupportTool,
            self::INSIGHTS, self::METABASE => new InsightTool,
            self::ERRORS, self::GLITCHTIP => new ErrorTool,
            self::ANALYTICS, self::UMAMI, self::PLAUSIBLE => new AnalyticsTool,
            self::MEET, self::LIVEKIT => new MeetTool,
            self::DNS, self::EXTERNAL_DNS => new DnsTool,
            self::UPTIME, self::KUMA => new UptimeTool,
            self::VPN, self::NETBIRD => new VpnTool,
            self::DASHBOARD, self::HEADLAMP => new DashboardTool,
            self::RESUME => new ResumeTool,
            self::PASTE, self::YOPASS => new YopassTool,
            default => throw new LogicException("No vendor defined for tool: {$this->value}"),
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::POCKETBASE => 'PocketBase (Embedded SQLite)',
            self::DIRECTUS => 'Directus (Headless CMS)',
            self::N8N => 'n8n (Workflow Automation)',
            self::WINDMILL => 'Windmill (Developer Workflow Platform)',
            self::MATRIX => 'Matrix (Synapse + Element)',
            self::TWENTY => 'Twenty (CRM)',
            self::LIVEKIT => 'LiveKit (WebRTC Meetings)',
            self::OPENBAO => 'OpenBao (Secrets & Encryption)',
            self::NETBIRD => 'NetBird (Zero-Trust VPN)',
            self::ZITADEL => 'Zitadel (Identity Provider & SSO)',
            self::VAULTWARDEN => 'Vaultwarden (Bitwarden Server)',
            self::KUMA => 'Uptime Kuma (Status Pages)',
            self::GRAFANA => 'Grafana (Metrics & Dashboards)',
            self::FORGEJO => 'Forgejo (Git Forge & CI/CD)',
            self::METABASE => 'Metabase (Business Intelligence)',
            self::GLITCHTIP => 'GlitchTip (Error Tracking)',
            self::OCIS => 'ownCloud Infinite Scale (oCIS)',
            self::OUTLINE => 'Outline (Team Knowledge Base)',
            self::TEABLE => 'Teable (Spreadsheet Database)',
            self::DOCUMENSO => 'Documenso (Document Signing)',
            self::CHATWOOT => 'Chatwoot (Customer Support)',
            self::UMAMI => 'Umami (Web Analytics)',
            self::PLAUSIBLE => 'Plausible (Privacy Analytics)',
            self::HEADLAMP => 'Headlamp (Kubernetes Dashboard)',
            self::STALWART => 'Stalwart (All-in-One Mail Server)',
            self::BULWARK => 'Bulwark (Webmail Client)',
            self::PLANKA => 'Planka (Kanban Project Management)',
            self::KUTT => 'Kutt (Link Shortener & Management)',
            self::PENPOT => 'Penpot (Design & Prototyping)',
            self::RESUME => 'Resume Builder (Reactive Resume)',
            self::YOPASS => 'Yopass (Burn-After-Read Secret Sharing)',
            self::SENDREC => 'Sendrec (Screen Recording & Sharing)',
            self::EXTERNAL_DNS => 'ExternalDNS (Cloudflare Sync)',

            // Legacy Categories
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
            self::POCKETBASE => '🗄️',
            self::DIRECTUS => '🐰',
            self::N8N => '⚡',
            self::WINDMILL => '💨',
            self::MATRIX => '💬',
            self::TWENTY => '🤝',
            self::LIVEKIT => '🎥',
            self::OPENBAO => '🔒',
            self::NETBIRD => '🔑',
            self::ZITADEL => '🪪',
            self::VAULTWARDEN => '🔐',
            self::KUMA => '🟢',
            self::GRAFANA => '📡',
            self::FORGEJO => '🦊',
            self::METABASE => '📈',
            self::GLITCHTIP => '🐛',
            self::OCIS => '☁️',
            self::OUTLINE => '📝',
            self::TEABLE => '📋',
            self::DOCUMENSO => '✍️',
            self::CHATWOOT => '💬',
            self::UMAMI => '📊',
            self::PLAUSIBLE => '📈',
            self::HEADLAMP => '☸️',
            self::STALWART => '✉️',
            self::BULWARK => '📬',
            self::PLANKA => '✅',
            self::KUTT => '🔗',
            self::PENPOT => '🎨',
            self::RESUME => '📄',
            self::YOPASS => '🔥',
            self::SENDREC => '🎥',
            self::EXTERNAL_DNS => '🌐',

            // Legacy categories
            self::ANALYTICS => '📊',
            self::CHAT => '💬',
            self::CRM => '🤝',
            self::DATA => '🗄️',
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
            self::POCKETBASE => 'PocketBase',
            self::DIRECTUS => 'Directus',
            self::N8N => 'n8n',
            self::WINDMILL => 'Windmill',
            self::MATRIX => 'Matrix',
            self::TWENTY => 'Twenty',
            self::LIVEKIT => 'LiveKit',
            self::OPENBAO => 'OpenBao',
            self::NETBIRD => 'NetBird',
            self::ZITADEL => 'Zitadel',
            self::VAULTWARDEN => 'Vaultwarden',
            self::KUMA => 'Uptime Kuma',
            self::GRAFANA => 'Grafana',
            self::FORGEJO => 'Forgejo',
            self::METABASE => 'Metabase',
            self::GLITCHTIP => 'GlitchTip',
            self::OCIS => 'oCIS',
            self::OUTLINE => 'Outline',
            self::TEABLE => 'Teable',
            self::DOCUMENSO => 'Documenso',
            self::CHATWOOT => 'Chatwoot',
            self::UMAMI => 'Umami',
            self::PLAUSIBLE => 'Plausible',
            self::HEADLAMP => 'Headlamp',
            self::STALWART => 'Stalwart',
            self::BULWARK => 'Bulwark',
            self::PLANKA => 'Planka',
            self::KUTT => 'Kutt',
            self::PENPOT => 'Penpot',
            self::RESUME => 'Resume',
            self::YOPASS => 'Yopass',
            self::SENDREC => 'Sendrec',
            self::EXTERNAL_DNS => 'ExternalDNS',

            // Legacy categories
            self::ANALYTICS => 'Analytics',
            self::CHAT => 'Chat',
            self::CRM => 'CRM',
            self::DATA => 'Data',
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
            self::PASTE => 'Paste',
        };
    }

    /**
     * Whitelabeling specification for tools that support custom branding (app name / logo)
     * via environment variables, Nginx sub_filter injection, or the tool's own Blade
     * template variables. null for tools with no whitelabeling support.
     *
     * @return array{app_name_key?: string, logo_url_key?: string, sub_filter?: bool, blade_variables?: bool}|null
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
            self::POCKETBASE, self::DIRECTUS, self::DATA => SharedClusterService::DATA,
            self::N8N, self::WINDMILL, self::FLOW => SharedClusterService::FLOW,
            self::FORGEJO, self::GIT => SharedClusterService::FORGEJO,
            self::MATRIX, self::CHAT => SharedClusterService::CHAT,
            self::TWENTY, self::CRM => SharedClusterService::CRM,
            self::LIVEKIT, self::MEET => SharedClusterService::MEET,
            self::OPENBAO, self::SECRETS => SharedClusterService::SECRETS,
            self::NETBIRD, self::VPN => SharedClusterService::VPN,
            self::ZITADEL, self::SSO => SharedClusterService::SSO,
            self::VAULTWARDEN, self::PASSWORDS => SharedClusterService::VAULT,
            self::KUMA, self::UPTIME => SharedClusterService::UPTIME_KUMA,
            self::GRAFANA, self::MONITOR => SharedClusterService::GRAFANA,
            self::METABASE, self::INSIGHTS => SharedClusterService::INSIGHTS,
            self::GLITCHTIP, self::ERRORS => SharedClusterService::ERRORS,
            self::OCIS, self::DRIVE => SharedClusterService::DRIVE,
            self::OUTLINE, self::NOTES => SharedClusterService::NOTES,
            self::TEABLE, self::SHEETS => SharedClusterService::SHEET,
            self::DOCUMENSO, self::SIGN => SharedClusterService::SIGN,
            self::CHATWOOT, self::SUPPORT => SharedClusterService::SUPPORT,
            self::UMAMI, self::PLAUSIBLE, self::ANALYTICS => SharedClusterService::ANALYTICS,
            self::HEADLAMP, self::DASHBOARD => SharedClusterService::DASHBOARD,
            self::STALWART, self::MAIL => SharedClusterService::MAIL,
            self::BULWARK, self::WEBMAIL => SharedClusterService::WEBMAIL,
            self::PLANKA, self::TASKS => SharedClusterService::TASKS,
            self::KUTT, self::LINK => SharedClusterService::LINK,
            self::PENPOT, self::DESIGN => SharedClusterService::DESIGN,
            self::RESUME => SharedClusterService::RESUME,
            self::YOPASS, self::PASTE => SharedClusterService::PASTE,
            self::SENDREC, self::RECORD => SharedClusterService::RECORD,
            self::EXTERNAL_DNS, self::DNS => null,
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
            self::PASSWORDS, self::VAULTWARDEN => 'larakube-vault',
            self::SECRETS, self::OPENBAO => 'larakube-secrets',
            self::SSO, self::ZITADEL => 'larakube-sso',
            self::VPN, self::NETBIRD => 'larakube-vpn',
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
    /**
     * Strict reverse lookup: matches ONLY `{component}-{instance}` and returns
     * the instance it derived. Unlike forDeployment(), a bare component name
     * never matches — such a Deployment carries no recoverable identity, so
     * `tool:list --refresh` deliberately leaves it undiscovered until it is
     * migrated to the naming convention. That absence IS the migration
     * checklist.
     *
     * @return array{tool: self, component: ClusterToolComponentData, instance: string}|null
     */
    public static function forInstancedDeployment(string $deploymentName): ?array
    {
        $best = null;

        foreach (self::shippedCases() as $tool) {
            foreach ($tool->engineCandidates() as $engine) {
                foreach ($tool->components(engine: $engine) as $component) {
                    $prefix = $component->deployment.'-';

                    if (! str_starts_with($deploymentName, $prefix)) {
                        continue;
                    }

                    $instance = substr($deploymentName, strlen($prefix));

                    if ($instance === '') {
                        continue;
                    }

                    // Longest component wins, or `crm-twenty-worker-crm-x`
                    // would resolve to the crm-twenty component with the
                    // instance "worker-crm-x".
                    if ($best === null || strlen($component->deployment) > strlen($best['component']->deployment)) {
                        $best = ['tool' => $tool, 'component' => $component, 'instance' => $instance];
                    }
                }
            }
        }

        return $best;
    }

    public static function forDeployment(string $deploymentName): ?array
    {
        foreach (self::cases() as $tool) {
            // A canonical tool's Deployments always carry their instance, so a
            // bare name is some other workload (a dead `stalwart`, a stray
            // `vaultwarden`) and must not resolve to it.
            if ($tool->resourceNaming() === ResourceNaming::CANONICAL) {
                continue;
            }

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

                    // What follows has to look like an instance: those are
                    // host-derived (ADR 0012), so always several segments.
                    // Without this, a component named after its upstream
                    // (`prometheus`, `redis`) would claim any third-party
                    // Deployment sharing the name (`prometheus-server`).
                    if (! str_contains(substr($deploymentName, strlen($component->deployment) + 1), '-')) {
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
     * The Commons Redis tenant names one instance allocates and `--purge`
     * releases. `:init` and `:remove` must both derive them here, or a purge
     * frees a name nothing ever allocated.
     *
     * @return list<string>
     */
    public function commonsRedisTenants(?string $instance = null): array
    {
        $keys = $this->commonsRedisKeys();
        if ($instance === null || $instance === '') {
            return $keys;
        }

        // A migrated tool's Redis tenant is its database name: both live in
        // one Commons registry row, and Postgres identifiers carry no hyphen.
        // Keeping the slug's hyphens here gave CRM two rows for one install,
        // and let a purge free a name nothing had allocated.
        if ($this->resourceNaming() === ResourceNaming::CANONICAL) {
            $instance = str_replace('-', '_', $instance);
        }

        return array_map(fn (string $key) => "{$key}_{$instance}", $keys);
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
            self::CHAT, self::MATRIX, self::DRIVE, self::OCIS, self::ERRORS, self::GLITCHTIP,
            self::FLOW, self::N8N, self::WINDMILL, self::GIT, self::FORGEJO,
            self::INSIGHTS, self::METABASE, self::SSO, self::ZITADEL,
            self::MONITOR, self::GRAFANA, self::VPN, self::NETBIRD => true,
            default => false,
        };
    }

    /**
     * How a person deploys this tool: `tool:init <environment> --tool=<slug>`.
     * Hints and instructions name this, never the old `{tool}:init`.
     */
    public function initInvocation(string $environment = ''): string
    {
        return 'tool:init'.($environment !== '' ? " {$environment}" : '')." --tool={$this->canonicalTool()->value}";
    }

    /** Canonical command names — the one place the `{tool}:{action}` shape is spelled out. */
    public function initCommand(): string
    {
        return "{$this->canonicalTool()->value}:init";
    }

    public function removeCommand(?string $engine = null): string
    {
        return "{$this->canonicalTool($engine)->value}:remove";
    }

    public function showCommand(?string $engine = null): string
    {
        return "{$this->canonicalTool($engine)->value}:show";
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
     * design, not a gap.
     */
    public function isShipped(): bool
    {
        return match ($this) {
            self::ANALYTICS, self::UMAMI, self::PLAUSIBLE, self::UPTIME, self::KUMA => false,
            default => true,
        };
    }

    /**
     * Whether this case represents a deprecated legacy category verb rather than an
     * individual tool.
     */
    public function isLegacy(): bool
    {
        return match ($this) {
            self::ANALYTICS,
            self::CHAT,
            self::MEET,
            self::CRM,
            self::DATA,
            self::DNS,
            self::DRIVE,
            self::ERRORS,
            self::FLOW,
            self::GIT,
            self::INSIGHTS,
            self::LINK,
            self::MAIL,
            self::MONITOR,
            self::NOTES,
            self::PASSWORDS,
            self::RECORD,
            self::SECRETS,
            self::SHEETS,
            self::SIGN,
            self::SSO,
            self::SUPPORT,
            self::TASKS,
            self::UPTIME,
            self::VPN,
            self::WEBMAIL,
            self::DASHBOARD,
            self::DESIGN,
            self::PASTE => true,
            default => false,
        };
    }

    /**
     * Resolve a legacy category or canonical case to its canonical individual tool.
     */
    public function canonicalTool(?string $engine = null): self
    {
        return match ($this) {
            self::DATA => ($engine === 'pocketbase') ? self::POCKETBASE : self::DIRECTUS,
            self::FLOW => ($engine === 'windmill') ? self::WINDMILL : self::N8N,
            self::GIT => self::FORGEJO,
            self::SHEETS => self::TEABLE,
            self::ANALYTICS => ($engine === 'plausible') ? self::PLAUSIBLE : self::UMAMI,
            self::CHAT => self::MATRIX,
            self::CRM => self::TWENTY,
            self::DNS => self::EXTERNAL_DNS,
            self::DRIVE => self::OCIS,
            self::ERRORS => self::GLITCHTIP,
            self::INSIGHTS => self::METABASE,
            self::LINK => self::KUTT,
            self::MAIL => self::STALWART,
            self::MONITOR => self::GRAFANA,
            self::NOTES => self::OUTLINE,
            self::PASSWORDS => self::VAULTWARDEN,
            self::RECORD => self::SENDREC,
            self::SECRETS => self::OPENBAO,
            self::SIGN => self::DOCUMENSO,
            self::SSO => self::ZITADEL,
            self::SUPPORT => self::CHATWOOT,
            self::TASKS => self::PLANKA,
            self::UPTIME => self::KUMA,
            self::VPN => self::NETBIRD,
            self::WEBMAIL => self::BULWARK,
            self::DASHBOARD => self::HEADLAMP,
            self::MEET => self::LIVEKIT,
            self::DESIGN => self::PENPOT,
            self::PASTE => self::YOPASS,
            default => $this,
        };
    }

    /**
     * The category case a tool-named case belongs to (ZITADEL → SSO, GRAFANA →
     * MONITOR); a category case, or one with no category, is its own. For code that
     * branches on "which kind of tool is this" and must give the same answer for
     * `--tool=sso` and `--tool=zitadel`.
     */
    public function category(): self
    {
        if ($this->isLegacy()) {
            return $this;
        }

        foreach (self::cases() as $case) {
            if (! $case->isLegacy()) {
                continue;
            }

            foreach ([null, 'pocketbase', 'windmill', 'plausible'] as $engine) {
                if ($case->canonicalTool($engine) === $this) {
                    return $case;
                }
            }
        }

        return $this;
    }

    /**
     * Legacy category prefix used in resource naming and stripping.
     */
    public function legacyCategoryPrefix(): ?string
    {
        return match ($this) {
            self::POCKETBASE, self::DIRECTUS, self::DATA => 'data',
            self::N8N, self::WINDMILL, self::FLOW => 'flow',
            self::FORGEJO, self::GIT => 'git',
            self::MATRIX, self::CHAT => 'chat',
            self::TWENTY, self::CRM => 'crm',
            self::LIVEKIT, self::MEET => 'meet',
            self::OPENBAO, self::SECRETS => 'secrets',
            self::NETBIRD, self::VPN => 'vpn',
            self::ZITADEL, self::SSO => 'sso',
            self::VAULTWARDEN, self::PASSWORDS => 'passwords',
            self::KUMA, self::UPTIME => 'uptime',
            self::GRAFANA, self::MONITOR => 'monitor',
            self::METABASE, self::INSIGHTS => 'insights',
            self::GLITCHTIP, self::ERRORS => 'errors',
            self::OCIS, self::DRIVE => 'drive',
            self::OUTLINE, self::NOTES => 'notes',
            self::TEABLE, self::SHEETS => 'sheets',
            self::DOCUMENSO, self::SIGN => 'sign',
            self::CHATWOOT, self::SUPPORT => 'support',
            self::UMAMI, self::PLAUSIBLE, self::ANALYTICS => 'analytics',
            self::HEADLAMP, self::DASHBOARD => 'dashboard',
            self::STALWART, self::MAIL => 'mail',
            self::BULWARK, self::WEBMAIL => 'webmail',
            self::PLANKA, self::TASKS => 'tasks',
            self::KUTT, self::LINK => 'link',
            self::PENPOT, self::DESIGN => 'design',
            self::YOPASS, self::PASTE => 'paste',
            self::SENDREC, self::RECORD => 'record',
            self::EXTERNAL_DNS, self::DNS => 'dns',
            self::RESUME => 'resume',
        };
    }

    /**
     * What a UI draws for this tool: an id a renderer knows, or later a URL. It is
     * the canonical tool's slug, so a client never has to know the category aliases.
     */
    public function logo(): string
    {
        return $this->canonicalTool()->value;
    }

    /** Whether the tool needs a paid plan. Every tool is free until LaraKube Cloud says otherwise. */
    public function isPaid(): bool
    {
        return false;
    }

    /** One line on what the tool is for, shown on its card. */
    public function tagline(): string
    {
        return match ($this->canonicalTool()) {
            self::POCKETBASE => 'Embedded SQLite & Backend API',
            self::DIRECTUS => 'Headless CMS & Data Platform',
            self::N8N => 'Workflow Automation',
            self::WINDMILL => 'Developer Workflow Engine',
            self::MATRIX => 'Decentralized Team Chat',
            self::TWENTY => 'CRM & Customer Management',
            self::LIVEKIT => 'WebRTC Video Meetings',
            self::OPENBAO => 'Secrets Vault & Encryption',
            self::NETBIRD => 'Zero-Trust VPN Mesh',
            self::ZITADEL => 'Identity Provider & SSO',
            self::VAULTWARDEN => 'Bitwarden Password Vault',
            self::KUMA => 'Status Pages & Monitoring',
            self::GRAFANA => 'Metrics & Observability Dashboards',
            self::FORGEJO => 'Self-Hosted Git & CI/CD',
            self::METABASE => 'Business Intelligence',
            self::GLITCHTIP => 'Error Tracking & Sentry APM',
            self::OCIS => 'Cloud Storage & File Sync',
            self::OUTLINE => 'Team Wiki & Knowledge Base',
            self::TEABLE => 'Spreadsheet Database',
            self::DOCUMENSO => 'Digital Document Signing',
            self::CHATWOOT => 'Customer Support & Live Chat',
            self::UMAMI => 'Privacy-Focused Web Analytics',
            self::PLAUSIBLE => 'Lightweight Web Analytics',
            self::HEADLAMP => 'Kubernetes Control Plane Dashboard',
            self::STALWART => 'All-in-One Mail Server',
            self::BULWARK => 'Webmail Client',
            self::PLANKA => 'Kanban Project Management',
            self::KUTT => 'Link Shortener & Management',
            self::PENPOT => 'Design & Prototyping',
            self::RESUME => 'Resume & CV Builder',
            self::YOPASS => 'Burn-After-Read Secret Sharing',
            self::SENDREC => 'Screen Recording & Sharing',
            self::EXTERNAL_DNS => 'Automated DNS Sync',
            default => $this->getLabel(),
        };
    }

    /**
     * The parts a multi-part tool is made of, in words, for its card. Empty for
     * a tool that is one workload.
     *
     * @return list<string>
     */
    public function stack(): array
    {
        return match ($this->canonicalTool()) {
            self::GRAFANA => ['Grafana', 'Prometheus', 'Loki'],
            self::NETBIRD => ['Management', 'Signal', 'Relay', 'Dashboard', 'Client'],
            self::MATRIX => ['Synapse', 'Element Web', 'MAS Auth', 'Coturn', 'Admin'],
            self::TWENTY => ['Twenty App', 'Worker'],
            self::FORGEJO => ['Forgejo Server', 'Actions Runner'],
            self::PENPOT => ['Backend', 'Frontend', 'Exporter'],
            self::GLITCHTIP => ['Web API', 'Worker'],
            self::LIVEKIT => ['LiveKit Server', 'JWT Auth'],
            default => [],
        };
    }

    /**
     * Functional category descriptors for this tool.
     *
     * @return list<ToolCategory>
     */
    public function categories(): array
    {
        return match ($this->canonicalTool()) {
            self::POCKETBASE => [ToolCategory::DATABASE, ToolCategory::BACKEND, ToolCategory::AUTH, ToolCategory::STORAGE],
            self::DIRECTUS => [ToolCategory::DATABASE, ToolCategory::BACKEND, ToolCategory::AUTH],
            self::N8N => [ToolCategory::DEVOPS, ToolCategory::PRODUCTIVITY, ToolCategory::COMMUNICATION],
            self::WINDMILL => [ToolCategory::DEVOPS, ToolCategory::PRODUCTIVITY, ToolCategory::BACKEND],
            self::MATRIX => [ToolCategory::COMMUNICATION],
            self::TWENTY => [ToolCategory::COMMUNICATION, ToolCategory::PRODUCTIVITY, ToolCategory::BACKEND, ToolCategory::DATABASE],
            self::LIVEKIT => [ToolCategory::COMMUNICATION],
            self::OPENBAO => [ToolCategory::SECURITY, ToolCategory::DEVOPS],
            self::NETBIRD => [ToolCategory::SECURITY, ToolCategory::DEVOPS],
            self::ZITADEL => [ToolCategory::AUTH, ToolCategory::SECURITY],
            self::VAULTWARDEN => [ToolCategory::SECURITY, ToolCategory::PRODUCTIVITY],
            self::KUMA => [ToolCategory::OBSERVABILITY, ToolCategory::DEVOPS],
            self::GRAFANA => [ToolCategory::OBSERVABILITY, ToolCategory::DEVOPS],
            self::FORGEJO => [ToolCategory::DEVOPS, ToolCategory::PRODUCTIVITY],
            self::METABASE => [ToolCategory::ANALYTICS, ToolCategory::DATABASE],
            self::GLITCHTIP => [ToolCategory::OBSERVABILITY, ToolCategory::DEVOPS],
            self::OCIS => [ToolCategory::STORAGE, ToolCategory::PRODUCTIVITY],
            self::OUTLINE => [ToolCategory::PRODUCTIVITY, ToolCategory::COMMUNICATION],
            self::TEABLE => [ToolCategory::DATABASE, ToolCategory::PRODUCTIVITY, ToolCategory::BACKEND],
            self::DOCUMENSO => [ToolCategory::PRODUCTIVITY, ToolCategory::SECURITY],
            self::CHATWOOT => [ToolCategory::COMMUNICATION, ToolCategory::PRODUCTIVITY],
            self::UMAMI, self::PLAUSIBLE => [ToolCategory::ANALYTICS],
            self::HEADLAMP => [ToolCategory::DEVOPS],
            self::STALWART => [ToolCategory::COMMUNICATION, ToolCategory::DEVOPS],
            self::BULWARK => [ToolCategory::COMMUNICATION, ToolCategory::PRODUCTIVITY],
            self::PLANKA => [ToolCategory::PRODUCTIVITY],
            self::KUTT => [ToolCategory::PRODUCTIVITY, ToolCategory::ANALYTICS],
            self::PENPOT => [ToolCategory::PRODUCTIVITY],
            self::RESUME => [ToolCategory::PRODUCTIVITY],
            self::YOPASS => [ToolCategory::SECURITY, ToolCategory::COMMUNICATION],
            self::SENDREC => [ToolCategory::COMMUNICATION, ToolCategory::PRODUCTIVITY],
            self::EXTERNAL_DNS => [ToolCategory::DEVOPS],
            default => [ToolCategory::PRODUCTIVITY],
        };
    }

    /**
     * Every case that is release-ready, for listings and prompts.
     *
     * @return list<self>
     */
    public static function shippedCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $tool) => $tool->isShipped() && ! $tool->isLegacy()));
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

            return $schema === null ? null : $this->wiringSchema($schema, SecretKind::SMTP, $instance, $engine);
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
     * Whether `vpn:wire` can restrict this tool's ingress to VPN peers.
     *
     * One predicate per wire pair, each backed by the pair's marker contract,
     * so a command never has to infer capability from whatever accessor happens
     * to return null. Before these existed each pair tested something
     * different -- vpnMiddlewareTarget(), smtpEnv(), dbSecretRef() -- and the
     * pickers drifted apart as a result.
     */
    public function hasVpnWire(): bool
    {
        return $this->vendor() instanceof HasVpnWiring;
    }

    /**
     * Whether `mail:wire` can point this tool at Stalwart.
     *
     * SSO is deliberately included without implementing HasSmtpWiring: Zitadel
     * takes its SMTP settings through its own API rather than deployment env,
     * so it has no smtpEnv() schema but is still very much mail-wireable.
     */
    public function hasMailWire(): bool
    {
        return $this->vendor() instanceof HasSmtpWiring || $this === self::SSO || $this === self::ZITADEL;
    }

    /**
     * Whether `secrets:wire` can hand this tool's Commons DB password to OpenBao to rotate.
     *
     * HasRotatableDatabasePassword, NOT HasOpenbaoSync -- the two are easy to
     * confuse and mean different things. OpenbaoSync is about pushing a tool's
     * own secrets INTO OpenBao (10 tools); this pair is about OpenBao taking
     * over rotation of a Commons DB password (17, exactly the set with a
     * dbSecretRef).
     */
    public function hasSecretsWire(): bool
    {
        return $this->vendor() instanceof HasRotatableDatabasePassword;
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
            self::SECRETS, self::OPENBAO => [
                'openbao-admin' => 'Full read/write on all secrets and Commons database credentials',
                'openbao-operator' => 'Read-only on production secrets and static database roles',
                'openbao-auditor' => 'Read-only on audit logs and secret metadata (no values)',
            ],
            self::MONITOR, self::GRAFANA => [
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
            self::DASHBOARD, self::HEADLAMP => [
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
            self::NOTES, self::OUTLINE => ['outline-user' => 'Can log in to Outline'],
            self::PASSWORDS, self::VAULTWARDEN => ['vaultwarden-user' => 'Can log in to Vaultwarden'],
            self::LINK, self::KUTT => ['kutt-user' => 'Can log in to Kutt'],
            self::RESUME => ['reactive-resume-user' => 'Can log in to Reactive Resume'],
            self::SIGN, self::DOCUMENSO => ['documenso-user' => 'Can log in to Documenso'],
            self::SHEETS, self::TEABLE => ['teable-user' => 'Can log in to Teable'],
            // Confirmed live 2026-08-21: admin@ourfridays.com (a partner-org
            // identity, same one Outline's incident involved) hit Penpot's
            // auto-provision-on-first-login prompt via plain Zitadel SSO —
            // access must be something LaraKube grants, not anyone with any
            // Zitadel account in the org.
            self::DESIGN, self::PENPOT => ['penpot-user' => 'Can log in to Penpot'],
            // ForwardAuth (ADR 0006), not native OIDC — wireForwardAuth()
            // reads this to also gate the shared sso-proxy's
            // --allowed-groups, not just to route onto rbacProjectName().
            self::RECORD, self::SENDREC => ['record-user' => 'Can log in to Sendrec'],
            // Added 2026-08-20 at the user's explicit request — a git forge
            // holding real source/CI credentials must not be reachable by
            // every org member (a future partner-org identity included).
            self::GIT, self::FORGEJO => ['git-user' => 'Can log in to Forgejo'],
            // DRIVE keeps its ssoAdminRoles() (ocisAdmin/ocisSpaceAdmin) —
            // this base role is the ONLY thing that changes: it's what an
            // operator grants for plain "can log in, no admin tier" access.
            // Genuinely coexists with ssoAdminRoles() here (see that
            // method's docblock) — requiresRbacGating() becoming true
            // routes BOTH sets of roles onto Drive's own rbacProjectName()
            // project; flattenOcisRoles() (OCIS_ROLES_SCRIPT) scans a
            // user's grants by role NAME across every project they hold,
            // not by project id, so moving ocisAdmin/ocisSpaceAdmin here
            // doesn't change how that Action finds them. Added 2026-08-20
            // at the user's request — a future partner given Drive access
            // (their stated plan) must not thereby get default access to
            // every other open-to-org tool sharing LaraKube Shared Tools.
            self::DRIVE, self::OCIS => ['ocisUser' => 'Can log in to oCIS (regular access, no admin)'],
            // VPN grants private NETWORK access (reach cluster-internal-only
            // services), not just a web login — a materially higher-stakes
            // gap than the open-to-org tools above if left ungated, so this
            // is gated from the moment sso:wire vpn ships, not added
            // reactively after an incident like the others above were.
            self::VPN, self::NETBIRD => ['vpn-user' => 'Can join the VPN via SSO'],
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
     * sso:wire creates these on the tool's OWN project — the shared
     * LaraKube Shared Tools project for a tool with only ssoAdminRoles(),
     * or the tool's own rbacProjectName() project when requiresRbacGating()
     * is also true (DRIVE) — and, unlike rbacRoles(), whose grants are a manual
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
            self::DRIVE, self::OCIS => [
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
     * ssoAdminRoles(). Disjoint for most tools (a tool is either gated by
     * rbacRoles or open-to-org via ssoAdminRoles) — DRIVE is the one
     * deliberate exception (2026-08-20): it needs both simultaneously,
     * rbacRoles() gating login itself while ssoAdminRoles() still
     * distinguishes admin tiers once someone's granted in. requiresRbacGating()
     * only checks rbacRoles() !== [], so this combination routes correctly:
     * both role sets land on the tool's own rbacProjectName() project.
     *
     * @return array<string, string>
     */
    public function grantableRoles(): array
    {
        return $this->rbacRoles() + $this->ssoAdminRoles();
    }

    /**
     * The Zitadel project THIS tool (and, when given, this specific
     * instance) registers under, instead of the shared open project.
     *
     * One project per (tool, instance), not one shared project for every
     * RBAC-gated tool — confirmed live against Zitadel's own docs
     * (2026-08-20): projectRoleCheck is project-wide, not per-role or
     * per-application ("either a user has at least one role in the
     * project, or authentication fails entirely"). Sharing one project
     * meant anyone granted even a single role on it — say, just
     * kutt-user — could already authenticate into every other tool on
     * that same project too, since the login gate never inspected WHICH
     * role existed, only that one did. Secrets/Monitor/Dashboard stayed
     * meaningfully protected only because they ALSO filter the specific
     * role claim themselves (OpenBao bound_claims, Grafana
     * role_attribute_path); Kutt/Outline/Documenso/Vaultwarden do not, so
     * passing the coarse project-level gate was full access for those.
     *
     * The claim-flattening Action (zitadelEnsureRbacAction()) already
     * flattens grants from EVERY project a user holds into one
     * larakube_roles/groups claim, so splitting projects needs no change
     * there or in how OpenBao/Grafana trust the resulting claim — only
     * the login gate itself becomes meaningful again.
     *
     * The name itself is just deploymentName($instance) — the exact live
     * Kubernetes Deployment name (2026-08-20, replacing an earlier
     * "LaraKube RBAC: {brand} ({instance})" scheme). Reusing it instead of
     * inventing a parallel naming convention means the project name always
     * matches what an operator actually sees in `kubectl get pods`/
     * `tool:list` — no separate scheme to keep in sync, no guessing which
     * project backs which tool. A tool's own Deployment naming may itself
     * be inconsistent (e.g. MONITOR's is bare `grafana`, not
     * `monitor-grafana`) — that's a pre-existing convention gap in the
     * Deployment name, not something this method should paper over by
     * diverging from reality.
     *
     * $instance IS explicitly gated on supportsMultipleInstances() here,
     * unlike a plain pass-through to deploymentName() — resolveInstanceForDomain()
     * unconditionally derives SOME non-null slug from the resolved host for
     * every tool, single-instance ones included, once nothing is registered
     * yet (instanceSlugFromHost()'s fallback; confirmed live 2026-08-20).
     * deploymentName() itself has no concept of "this tool can't actually
     * have a second instance" — it suffixes whatever it's given. Passing a
     * spurious derived slug through for Secrets/Monitor/Dashboard would
     * split their OWN grants across two projects on their very first wire,
     * for a tool that will never have a real second instance.
     */
    public function rbacProjectName(?string $instance = null): string
    {
        return $this->deploymentName($instance);
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
     *    sso:wire/SyncsClusterSecrets/grafana:init assumes exists exactly
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
            self::CHAT, self::MATRIX, self::MEET, self::LIVEKIT, self::GIT, self::FORGEJO,
            self::MAIL, self::STALWART, self::SSO, self::ZITADEL, self::SECRETS, self::OPENBAO,
            self::MONITOR, self::GRAFANA, self::VPN, self::NETBIRD, self::WEBMAIL, self::BULWARK,
            self::DASHBOARD, self::HEADLAMP, self::DNS, self::EXTERNAL_DNS => false,
            default => true,
        };
    }

    /**
     * Whether this tool's initializer requires or provisions a primary admin email.
     */
    public function requiresAdminEmail(?string $engine = null): bool
    {
        return match ($this->canonicalTool($engine)) {
            self::POCKETBASE,
            self::DIRECTUS,
            self::ZITADEL,
            self::STALWART,
            self::METABASE,
            self::PENPOT,
            self::OUTLINE,
            self::CHATWOOT,
            self::GLITCHTIP,
            self::FORGEJO => true,
            default => false,
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
     * ignores $instance/--domain. Only the tools listed below currently
     * resolve --domain to a specific registered instance before tearing it
     * down; every other tool would silently ignore --domain and delete the
     * one real installation no matter what host was passed, which is the
     * footgun this method exists to let the :remove guard close.
     */
    public function hasInstanceAwareRemoval(): bool
    {
        return match ($this) {
            self::DATA, self::POCKETBASE, self::DIRECTUS, self::NOTES, self::OUTLINE, self::CRM, self::TWENTY,
            self::DESIGN, self::PENPOT, self::PASTE, self::YOPASS, self::SIGN, self::DOCUMENSO,
            self::FLOW, self::N8N, self::WINDMILL, self::LINK, self::KUTT, self::ANALYTICS, self::UMAMI, self::PLAUSIBLE,
            self::SHEETS, self::TEABLE, self::TASKS, self::PLANKA, self::UPTIME, self::KUMA, self::INSIGHTS, self::METABASE,
            self::ERRORS, self::GLITCHTIP, self::SUPPORT, self::CHATWOOT, self::RECORD, self::SENDREC, self::RESUME, self::DESIGN, self::PENPOT => true,
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
        if ($this === self::DIRECTUS) {
            return DataTool::DIRECTUS->ssoLicenseCaveat();
        }

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
     * Vaultwarden. Forgejo/OpenBao/NetBird need CLI- or API-driven OIDC
     * registration instead of env vars — their `oidcEnv()` schemas return
     * empty `vars`/`static` and are dispatched to hand-written wiring in
     * SsoWireCommand::wire()/SsoUnwireCommand::unwire() instead of the
     * generic applyToolEnv() path (see plans/completed/sso-identity-provider.md
     * for the original scoping). GlitchTip is still deferred.
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

            return $schema === null ? null : $this->wiringSchema($schema, SecretKind::OIDC, $instance, $engine);
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
            self::DRIVE, self::OCIS => ["https://{$toolHost}/"],
            // Fixed NetBird dashboard-frontend path (IdentityProviderModal.tsx),
            // not derived from redirect_path — same reasoning as DRIVE above.
            self::VPN, self::NETBIRD => ["https://{$toolHost}/oauth2/logout/callback"],
            default => [],
        };
    }

    /**
     * The Traefik Middleware {name, namespace} a --vpn-only-capable tool's
     * ingress annotation already references (e.g.
     * larakube-shared-desk-vpn-only@kubernetescrd → name "desk-vpn-only" in
     * "larakube-shared"). NOT derivable from $this->value — several tools'
     * ingress partials reference their SharedClusterService label instead
     * (errors→glitchtip, git→forgejo, sheets→sheet, uptime→uptime-kuma),
     * confirmed by reading every ingress template rather than assumed. null
     * for tools with no --vpn-only flag (Dns, Vpn itself).
     *
     * @return array{name: string, namespace: string}|null
     */
    public function vpnMiddlewareTarget(?string $instance = null, ?string $engine = null): ?array
    {
        $vendor = $this->vendor($engine);
        if ($vendor instanceof HasVpnWiring) {
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
            $components = $vendor->components($instance, $engine);

            // A migrated tool drops the category it used to repeat: what is
            // left of the vendor's name IS the component (`git-forgejo-runner`
            // -> `forgejo-runner`), and the instance identifies the install.
            return $this->resourceNaming() === ResourceNaming::CANONICAL
                ? array_map(fn (ClusterToolComponentData $c) => $c->renamed($this->withoutCategory($c->deployment)), $components)
                : $components;
        }

        if (! $vendor instanceof HasDeploymentBaseName) {
            throw new LogicException("{$this->value} vendor implements neither HasWorkloadComponents nor HasDeploymentBaseName.");
        }

        $base = $this->resourceNaming() === ResourceNaming::CANONICAL
            ? $vendor->canonicalComponentName()
            : $vendor->baseDeploymentName();
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
    public function openbaoSyncConfig(?string $instance = null, ?string $engine = null): ?array
    {
        $vendor = $this->vendor($engine);
        if ($vendor instanceof HasOpenbaoSync) {
            $config = ['namespace' => $this->namespace()] + $vendor->openbaoSyncConfig($instance);
            $config['secret'] = $this->instanceSecretName($config['secret'], $instance, $config['kind'] ?? SecretKind::CREDENTIALS, engine: $engine);
            unset($config['kind']);

            return $config;
        }

        return null;
    }

    /**
     * Which naming generation this tool's manifests write. A tool moves up a
     * generation in the same change that migrates its manifests AND its live
     * resources, never before: rotation and the OpenBao sync derive names
     * from here, and a Merge-policy ExternalSecret can't create a Secret that
     * doesn't exist, so a name nothing deploys reaches nothing.
     */
    public function resourceNaming(): ResourceNaming
    {
        return match ($this) {
            self::MONITOR, self::GRAFANA, self::GIT, self::FORGEJO, self::NOTES, self::OUTLINE,
            self::FLOW, self::N8N, self::WINDMILL, self::SIGN, self::DOCUMENSO, self::DATA, self::POCKETBASE,
            self::DIRECTUS, self::LINK, self::KUTT, self::ANALYTICS, self::UMAMI, self::PLAUSIBLE, self::SHEETS,
            self::TEABLE, self::TASKS, self::PLANKA, self::DASHBOARD, self::HEADLAMP, self::MEET,
            self::LIVEKIT, self::WEBMAIL, self::BULWARK, self::DRIVE, self::OCIS, self::VPN, self::NETBIRD,
            self::CRM, self::TWENTY, self::PASSWORDS, self::VAULTWARDEN, self::CHAT, self::MATRIX, self::MAIL, self::STALWART,
            self::SECRETS, self::OPENBAO, self::SSO, self::ZITADEL,
            self::PASTE, self::YOPASS, self::UPTIME, self::KUMA, self::INSIGHTS, self::METABASE,
            // ExternalDNS is keyed by a zone group, not a host: `external-dns-{group}` already is
            // {component}-{instance}, so listing it changes no name.
            self::DNS, self::EXTERNAL_DNS, self::ERRORS, self::GLITCHTIP, self::SUPPORT, self::CHATWOOT, self::RECORD, self::SENDREC, self::RESUME, self::DESIGN, self::PENPOT => ResourceNaming::CANONICAL,
            default => ResourceNaming::INSTANCE_SUFFIXED,
        };
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
        if ($this === self::DATA && $engine === null) {
            $engine = 'directus';
        }

        $vendor = $this->vendor($engine);
        if ($vendor instanceof HasDbSecretRef) {
            $ref = $vendor->dbSecretRef();
            if ($ref === null) {
                return null;
            }

            $ref = ['namespace' => $this->namespace()] + $ref;
            $ref['secret'] = $this->instanceSecretName($ref['secret'], $instance, $ref['kind'] ?? SecretKind::CREDENTIALS, engine: $engine);
            unset($ref['kind']);

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
        return self::resolveCommonsResource($name)['tool'] ?? null;
    }

    /**
     * The tool and instance a Commons database or bucket name belongs to: a bare
     * name (`sendrec`) is the tool itself, an instanced one
     * (`sendrec_record_example_com`, `yopass-storage-paste-example-com`) is the
     * tool's base name plus the instance slug. Slugs hold only [a-z0-9-], so the
     * underscore form of a database name maps back to exactly one slug. The
     * longest matching base wins, so `forgejo-storage` is never read as another
     * tool's `forgejo`.
     *
     * @return array{tool: self, instance: ?string}|null
     */
    public static function resolveCommonsResource(string $name): ?array
    {
        $best = null;

        foreach (self::cases() as $tool) {
            if (in_array($name, $tool->commonsDatabases(), true) || in_array($name, $tool->commonsBuckets(), true)) {
                return ['tool' => $tool, 'instance' => null];
            }

            foreach ($tool->commonsDatabases() as $base) {
                if (str_starts_with($name, "{$base}_") && ($best === null || strlen($base) > $best['length'])) {
                    $best = ['tool' => $tool, 'instance' => str_replace('_', '-', substr($name, strlen($base) + 1)), 'length' => strlen($base)];
                }
            }

            foreach ($tool->commonsBuckets() as $base) {
                if (str_starts_with($name, "{$base}-") && ($best === null || strlen($base) > $best['length'])) {
                    $best = ['tool' => $tool, 'instance' => substr($name, strlen($base) + 1), 'length' => strlen($base)];
                }
            }
        }

        return $best === null ? null : ['tool' => $best['tool'], 'instance' => $best['instance']];
    }

    /** `git-forgejo-runner-x` -> `forgejo-runner-x`: the category, dropped. */
    public function withoutCategory(string $name): string
    {
        $prefix = $this->legacyCategoryPrefix();
        if ($prefix !== null && str_starts_with($name, "{$prefix}-")) {
            return substr($name, strlen($prefix) + 1);
        }

        if ($this->isLegacy() && str_starts_with($name, "{$this->value}-")) {
            return substr($name, strlen($this->value) + 1);
        }

        return $name;
    }

    /** The Secret this tool's manifests write, in whichever generation it is on. */
    public function instanceSecretName(string $shippedName, ?string $instance, SecretKind $kind = SecretKind::CREDENTIALS, ?string $engine = null): string
    {
        if ($instance === null || $instance === '') {
            return $shippedName;
        }

        return match ($this->resourceNaming()) {
            ResourceNaming::CANONICAL => ToolInstance::forInstance($this, $instance, $engine)->secret($kind),
            ResourceNaming::INSTANCE_SUFFIXED => "{$shippedName}-{$instance}",
            ResourceNaming::AS_SHIPPED => $shippedName,
        };
    }

    /**
     * A vendor's wiring schema with this tool's namespace, the deployments
     * that share the Secret, and — for a tool on the naming convention — the
     * Secret and Deployment names ToolInstance gives, so `sso:wire` and
     * `mail:wire` write `{component}-{kind}-{instance}` instead of the
     * instance-less name the vendor shipped with.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function wiringSchema(array $schema, SecretKind $kind, ?string $instance, ?string $engine): array
    {
        $schema = ['namespace' => $this->namespace(), 'also_patch' => $this->alsoPatchDeployments($instance, $engine)] + $schema;

        if ($instance === null || $instance === '' || $this->resourceNaming() !== ResourceNaming::CANONICAL) {
            return $schema;
        }

        $names = ToolInstance::forInstance($this, $instance, $engine);
        $schema['secret'] = $names->secret($kind);
        $schema['deployment'] = $names->deployment();
        // The wired Secret is discoverable like the tool's own resources.
        $schema['labels'] = $names->labels();

        return $schema;
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
        // FLOW with no resolved engine reports BOTH engines' tenants, so a
        // caller that can't tell which engine an instance ran still covers
        // it. Must run before vendor(), which defaults a null engine to n8n.
        if ($this === self::FLOW && $engine === null) {
            $canonical = $this->resourceNaming() === ResourceNaming::CANONICAL;

            return array_merge(...array_map(
                fn (FlowTool $c) => $canonical ? $c->tool()->canonicalDatabaseList() : $c->tool()->commonsDatabaseList(),
                FlowTool::cases(),
            ));
        }

        $vendor = $this->vendor($engine);
        if ($vendor instanceof HasCommonsDatabases) {
            return $this->resourceNaming() === ResourceNaming::CANONICAL
                ? $vendor->canonicalDatabaseList()
                : $vendor->commonsDatabaseList();
        }

        return [];
    }

    /** @return list<string> */
    private function commonsBucketList(?string $engine = null): array
    {
        $vendor = $this->vendor($engine);
        if ($vendor instanceof HasCommonsBuckets) {
            return $this->resourceNaming() === ResourceNaming::CANONICAL
                ? $vendor->canonicalBucketList()
                : $vendor->commonsBucketList();
        }

        return [];
    }

    // Legacy Category Cases (Retained as deprecated aliases for backwards compatibility with production clusters)
    case ANALYTICS = 'analytics';
    case CHAT = 'chat';
    case MEET = 'meet';
    case CRM = 'crm';
    case DATA = 'data';
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
    case PASTE = 'paste';

    // Canonical Individual Tool Cases
    case POCKETBASE = 'pocketbase';
    case DIRECTUS = 'directus';
    case N8N = 'n8n';
    case WINDMILL = 'windmill';
    case MATRIX = 'matrix';
    case TWENTY = 'twenty';
    case LIVEKIT = 'livekit';
    case OPENBAO = 'openbao';
    case NETBIRD = 'netbird';
    case ZITADEL = 'zitadel';
    case VAULTWARDEN = 'vaultwarden';
    case KUMA = 'kuma';
    case GRAFANA = 'grafana';
    case FORGEJO = 'forgejo';
    case METABASE = 'metabase';
    case GLITCHTIP = 'glitchtip';
    case OCIS = 'ocis';
    case OUTLINE = 'outline';
    case TEABLE = 'teable';
    case DOCUMENSO = 'documenso';
    case CHATWOOT = 'chatwoot';
    case UMAMI = 'umami';
    case PLAUSIBLE = 'plausible';
    case HEADLAMP = 'headlamp';
    case STALWART = 'stalwart';
    case BULWARK = 'bulwark';
    case PLANKA = 'planka';
    case KUTT = 'kutt';
    case PENPOT = 'penpot';
    case RESUME = 'resume';
    case YOPASS = 'yopass';
    case SENDREC = 'sendrec';
    case EXTERNAL_DNS = 'external-dns';
}
