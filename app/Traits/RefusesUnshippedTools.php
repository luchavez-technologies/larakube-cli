<?php

namespace App\Traits;

use App\Enums\ClusterTool;

/**
 * Hard gate for tools that are NOT release-ready (see ClusterTool::isShipped()).
 *
 * Unshipped tools are hidden from every listing/prompt UI (tool:list, tool:add,
 * tool:show, wire-command candidate loops), and any command that reaches one —
 * per-tool verbs, an explicit --tool=uptime/analytics, a wired command — refuses
 * with a single consistent message instead of a misleading "not installed".
 * The tool's enums and reverse lookups stay intact so live resources from a
 * pre-gate install are still discovered and backed up.
 */
trait RefusesUnshippedTools
{
    /**
     * Refuse when the tool is not yet shipped.
     *
     * @return bool true when the caller must abort (message already emitted)
     */
    protected function refuseUnshippedTool(ClusterTool $tool): bool
    {
        if ($tool->isShipped()) {
            return false;
        }

        $this->laraKubeError("{$tool->getLabel()} is not yet shipped in LaraKube — it stays hidden from tool:list and tool:add until it's release-ready.");

        return true;
    }
}
