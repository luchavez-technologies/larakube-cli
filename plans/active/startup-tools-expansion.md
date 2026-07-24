# Startup Tools Expansion Plan

## Objective
To implement 7 new lightweight, fully-featured open-source tools to the LaraKube ecosystem to provide a complete "Startup OS" for 4GB server constraints.

## Target Tools
1. `analytics` (Umami)
2. `tasks` (Plane or Planka)
3. `draw` (Excalidraw)
4. `sign` (Documenso)
5. `support` (Chatwoot)
6. `link` (Kutt)
7. `crm` (Twenty)

## Step 1: Enum Expansion (`app/Enums/ClusterTool.php`)
- [ ] Add the 7 new cases to the enum.
- [ ] Implement `getLabel()` for each tool.
- [ ] Define `smtpEnv()` hooks for tools that send emails (Umami, Documenso, Chatwoot, Twenty, Plane/Planka, Kutt).
- [ ] Define `oidcEnv()` hooks for tools that support SSO.
- [ ] Update `vpnMiddlewareTarget()` if they support `--vpn-only` flags.

## Step 2: Init Command Generation
For each tool, we need a corresponding `app/Commands/<Category>/<Category>InitCommand.php`.
- [ ] `app/Commands/Analytics/AnalyticsInitCommand.php`
- [ ] `app/Commands/Tasks/TasksInitCommand.php`
- [ ] `app/Commands/Draw/DrawInitCommand.php`
- [ ] `app/Commands/Sign/SignInitCommand.php`
- [ ] `app/Commands/Support/SupportInitCommand.php`
- [ ] `app/Commands/Link/LinkInitCommand.php`
- [ ] `app/Commands/Crm/CrmInitCommand.php`

## Step 3: Architecture & Wiring
- **Storage:** Tools that require S3 (Documenso, Excalidraw, Plane) should reuse the Plex Commons SeaweedFS/Minio instances.
- **Databases:** Tools that require Postgres/Redis (Umami, Twenty, Kutt, Chatwoot) should reuse the Plex Commons instances to preserve the 4GB server constraint.
- **Traits:** Each command should use standard traits (`DeploysClusterTool`, `InteractsWithClusterContext`, `LaraKubeOutput`).
- **Routing:** Each tool needs a standard Kustomize overlay that exposes a Traefik Ingress.

## Testing Strategy
- Ensure `tool:add` dynamically discovers the new enums.
- Verify that `ResolvesStandaloneEnvironment` continues to function for these tools.
- Create unit tests for the Init Commands mimicking the `NotesInitCommandTest` structure.
