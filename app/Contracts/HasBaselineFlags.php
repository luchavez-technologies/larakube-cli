<?php

namespace App\Contracts;

/**
 * Feature flags a vendor needs enabled regardless of wiring state (SMTP,
 * OIDC, ...). Only Penpot (DesignTool) implements this today, to force-enable
 * its webhooks flag while deliberately excluding `enable-mcp` — a 2026-08-10
 * production outage was caused by that flag's config-merge logic, and Penpot
 * MCP stays unbuilt until it ships an official image + real auth flow.
 */
interface HasBaselineFlags
{
    /** @return list<string> */
    public function baselineFlags(): array;
}
