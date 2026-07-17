<?php

namespace App\Commands\Tool;

use App\Traits\InteractsWithMail;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesClusterTool;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class ToolAddCommand extends Command
{
    use InteractsWithMail, LaraKubeOutput, ResolvesClusterTool;

    protected $signature = 'tool:add {tool? : The tool to add to the cluster}';

    protected $description = 'Interactively discover and install LaraKube shared cluster tools';

    public function handle(): int
    {
        $this->renderHeader();

        $tool = $this->resolveTool();

        if ($tool === null) {
            return 0;
        }

        $this->line("Proxying to {$tool->value}:init...");
        $this->newLine();

        $result = $this->call("{$tool->value}:init");

        if ($result === 0) {
            $this->offerMailWiring($tool);
        }

        return $result;
    }

    /**
     * If the freshly-installed tool sends email and Stalwart is present, offer
     * to wire it up right away. Any tool that declares smtpEnv() gets this for
     * free — no per-tool code here.
     */
    protected function offerMailWiring(\App\Enums\ClusterTool $tool): void
    {
        if ($tool->smtpEnv() === null) {
            return;
        }

        $kubectl = $this->mailKubectl();
        $ns = $this->mailNamespace();

        if (! $this->isMailInstalled($kubectl, $ns)) {
            return;
        }

        $this->newLine();
        if (confirm("Wire {$tool->getLabel()} to your Stalwart mail server now?", true)) {
            $this->call('mail:wire', ['tool' => $tool->value]);
        }
    }
}
