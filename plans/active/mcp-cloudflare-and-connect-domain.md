# Plan: Cloudflare & Connect-Domain Support in LaraKube Desktop MCP

## Objective
Support Cloudflare DNS and TLS in LaraKube Desktop MCP tools with privacy-first token handling:
1. Allow `create-server` to configure ExternalDNS via `cloudflare_token` or environment variables (`LARAKUBE_CLOUDFLARE_TOKEN`, `CLOUDFLARE_API_TOKEN`).
2. Add a new `connect-domain` MCP tool to wire ExternalDNS and TLS on any server, auto-resolving cluster context and secrets.
3. Update `NewServerWizardPrompt` to guide agents toward zero-leak environment / desktop token configuration instead of asking users to paste raw API keys into chat.

## Implementation Steps
1. **CreateServerTool**:
   - Add nullable `cloudflare_token` and `dns_provider` schema.
   - Detect `cloudflare_token` or fallback to `LARAKUBE_CLOUDFLARE_TOKEN` / `CLOUDFLARE_API_TOKEN` in `secretEnvironment`.
   - Add `--cloudflare` flag if a Cloudflare token is resolved.
2. **ConnectDomainTool**:
   - Attributes: `#[Name('connect-domain')]`, `#[Title('Connect Domain & DNS')]`, `#[Description(...)]`, `#[IsIdempotent]`.
   - Arguments: `server` (Completable), `context` (optional, auto-resolved), `cloudflare_token` (optional, auto-fallback to env), `dns_provider` (default 'cloudflare'), `enable_tls` (default true).
   - Runs `tool:init production --tool=external-dns --context=... --force` with `LARAKUBE_CLOUDFLARE_TOKEN` in `secretEnvironment`.
   - If `enable_tls`, follows with `tls:init production --context=... --force`.
   - Handles polling with `pollRun()`.
3. **Registration & Permissions**:
   - Register `ConnectDomainTool::class` in `LaraKubeDesktopServer::$tools`.
   - Add `'connect-domain'` to `McpHttpServer::WRITE_TOOLS`.
4. **Agent Guidance**:
   - Update `NewServerWizardPrompt.php` to advise agents to guide users on environment variables or Desktop UI inputs rather than pasting raw secrets into chat.
5. **Testing & QA**:
   - `ConnectDomainToolTest.php` and `CreateServerToolTest.php`.
   - Pint formatting and PHPStan.
   - Full test suite execution (`pest --parallel`).
