<?php

namespace App\Commands\Tool;

use App\Enums\ClusterTool;
use App\Services\Tools\ToolInitSpec;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;
use LogicException;

/**
 * What every Cluster Tool's init command stands on. Given a tool, the command
 * takes its name, signature and description from ToolInitSpec, so nothing is
 * written per tool. The command is never registered under that name:
 * `tool:init` builds it for the tool it is asked to deploy.
 */
abstract class AbstractToolInitCommand extends Command
{
    use LaraKubeOutput;

    public function __construct(protected ?ClusterTool $initTool = null)
    {
        if ($initTool !== null) {
            $this->signature = ToolInitSpec::signature($initTool);
            $this->description = ToolInitSpec::description($initTool);
        }

        parent::__construct();
    }

    public function handle(): int
    {
        $this->renderHeader();

        return $this->runInit();
    }

    /** Deploys the tool: the family's one deploy method. */
    abstract protected function runInit(): int;

    protected function tool(): ClusterTool
    {
        return $this->initTool ?? throw new LogicException(static::class.' was not given a tool.');
    }
}
