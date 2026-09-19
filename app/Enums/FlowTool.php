<?php

namespace App\Enums;

use App\Tools\N8n;
use App\Tools\Windmill;

/**
 * The FLOW engines, as the keys `--engine=` and the tool registry store.
 * Everything an engine knows lives on its class in app/Tools.
 */
enum FlowTool: string
{
    public function tool(): N8n|Windmill
    {
        return match ($this) {
            self::N8N => new N8n,
            self::WINDMILL => new Windmill,
        };
    }
    case N8N = N8n::ENGINE;
    case WINDMILL = Windmill::ENGINE;
}
