# Desktop MCP Server Enhancements: Phase 1 & Phase 2

## Context & Objectives
Extend LaraKube Desktop's Model Context Protocol (MCP) server based on the official `laravel/mcp` documentation and specification.
Following our successful implementation of `create-server`, `delete-server`, and context auto-resolution, this plan delivers:

- **Phase 1: Frictionless Agent UX**:
  - Safety annotations (`#[IsReadOnly]`, `#[IsDestructive]`, `#[IsIdempotent]`) across all 13 tools.
  - Capability registration for `completions` in `LaraKubeDesktopServer`.
  - Dynamic autocompletions (`Completable` & `CompletionResponse`) for tools and prompts (`tool`, `server`, `provider`, `region`, `size`, `target_kind`, `environment`, `name`).
  - Extended JSON-RPC `completion/complete` handler supporting prompt arguments, resource URI templates, and tool arguments (`ref/tool`).

- **Phase 2: Live Resources & Slash Prompts**:
  - High-speed live Resources:
    - `servers://list` (`ServerListResource`)
    - `servers://{name}/status` (`ServerDetailResource`, `HasUriTemplate`)
    - `projects://list` (`ProjectListResource`)
    - `projects://{id}/blueprint` (`ProjectBlueprintResource`, `HasUriTemplate`)
  - Curated Slash Prompts:
    - `diagnose-cluster` (`DiagnoseClusterPrompt`, argument: `server`)
    - `new-server-wizard` (`NewServerWizardPrompt`)
  - Integration with `LaraKubeDesktopServer::$resources` and `LaraKubeDesktopServer::$prompts`.
  - Comprehensive feature tests in `desktop/tests/Feature/Mcp/`.

## Architecture Details

### 1. Safety Annotations
Using `Laravel\Mcp\Server\Tools\Annotations\*`:
- `#[IsReadOnly]`:
  - `ListProjectsTool`
  - `ShowProjectTool`
  - `ListServersTool`
  - `ShowServerTool`
  - `ListClusterToolsTool`
  - `GetRunStatusTool`
- `#[IsDestructive]`:
  - `DeleteServerTool`
  - `RemoveClusterToolTool`
  - `ProjectDownTool`
- `#[IsIdempotent]`:
  - `CreateServerTool`
  - `InstallClusterToolTool`
  - `ProjectUpTool`
  - `ProjectHealTool`

### 2. Autocompletions (`Completable`)
Implement `Laravel\Mcp\Server\Contracts\Completable` and return `CompletionResponse`:
- `InstallClusterToolTool`: `tool` -> ClusterTool enum cases, `server` -> existing Server names.
- `CreateServerTool`: `provider` -> CloudProvider slugs (`gcp`, `do`, `hetzner`, `aws`, `custom`), `target_kind` -> `['vps', 'managed']`.
- `DeleteServerTool` & `ShowServerTool`: `name` -> existing Server names.
- `ProjectUpTool`, `ProjectDownTool`, `ProjectHealTool`, `ShowProjectTool`: `environment` -> `['local', 'production', 'staging']`.
- `LaraKubeDesktopServer`: advertise `self::CAPABILITY_COMPLETIONS` capability.
- Register enhanced `CompletionComplete` method in `LaraKubeDesktopServer` to support `ref/tool`, `ref/prompt`, and `ref/resource`.

### 3. Resources
Classes extending `Laravel\Mcp\Server\Resource`:
- `ServerListResource`: uri `servers://list`, returns JSON summary of servers.
- `ServerDetailResource`: implements `HasUriTemplate`, uri `servers://{name}/status`, returns JSON status, nodes, and companion tools.
- `ProjectListResource`: uri `projects://list`, returns registered projects.
- `ProjectBlueprintResource`: implements `HasUriTemplate`, uri `projects://{id}/blueprint`, returns project configuration and workloads.

### 4. Prompts
Classes extending `Laravel\Mcp\Server\Prompt`:
- `DiagnoseClusterPrompt`: name `diagnose-cluster`, argument `server` (required: false). Guides the LLM to inspect nodes, tools, and run status.
- `NewServerWizardPrompt`: name `new-server-wizard`. Guides the LLM to ask appropriate questions for provisioning on GCP, DigitalOcean, Hetzner, or AWS.

## Verification
- Unit & Feature tests via Pest (`McpAnnotationsTest`, `McpCompletionTest`, `McpResourcesTest`, `McpPromptsTest`).
- Biome & TypeScript checks.
- Mandated full test suite run (`php -d memory_limit=2G ./vendor/bin/pest --parallel`).
