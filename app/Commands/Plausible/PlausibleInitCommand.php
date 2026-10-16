<?php

namespace App\Commands\Plausible;

use App\Commands\Tool\AbstractToolInitCommand;
use App\Enums\ClusterTool;
use App\Traits\RefusesUnshippedTools;

/**
 * Plausible has no deploy implementation yet — only Umami's analytics
 * engine is actually built. This exists so `--tool=plausible` refuses with
 * the standard unshipped-tool message instead of a missing-command error.
 */
abstract class PlausibleInitCommand extends AbstractToolInitCommand
{
    use RefusesUnshippedTools;

    protected function runInit(): int
    {
        return $this->refuseUnshippedTool(ClusterTool::PLAUSIBLE) ? 1 : 0;
    }
}
