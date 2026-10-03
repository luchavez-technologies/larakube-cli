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
 * written per tool. A command that declares its own signature (the ones still
 * registered under their own name) is left alone.
 */
abstract class AbstractToolInitCommand extends Command
{
    use LaraKubeOutput;

    private bool $legacyAlias = false;

    public function __construct(protected ?ClusterTool $initTool = null)
    {
        if ($initTool !== null) {
            $this->signature = ToolInitSpec::signature($initTool);
            $this->description = ToolInitSpec::description($initTool);
        }

        parent::__construct();
    }

    /** Called for the old `{tool}:init` names, so they say what replaces them. */
    public function markAsLegacyAlias(): void
    {
        $this->legacyAlias = true;
    }

    public function handle(): int
    {
        $this->renderHeader();

        if ($this->legacyAlias) {
            $this->line("  <fg=gray>{$this->getName()} is now</> <fg=yellow>tool:init <environment> --tool={$this->tool()->canonicalTool()->value}</><fg=gray>; the old name will be removed.</>");
        }

        return $this->runInit();
    }

    /** Deploys the tool: the family's one deploy method. */
    abstract protected function runInit(): int;

    protected function tool(): ClusterTool
    {
        return $this->initTool ?? throw new LogicException(static::class.' was not given a tool.');
    }
}
