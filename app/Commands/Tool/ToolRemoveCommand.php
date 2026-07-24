<?php

namespace App\Commands\Tool;

use App\Traits\ConfirmsDestructiveAction;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesClusterTool;
use App\Traits\ResolvesStandaloneEnvironment;
use LaravelZero\Framework\Commands\Command;

class ToolRemoveCommand extends Command
{
    use ConfirmsDestructiveAction, LaraKubeOutput, ResolvesClusterTool, ResolvesStandaloneEnvironment;

    protected $signature = 'tool:remove
        {environment? : The environment to target}
        {--tool= : Comma-separated tool slugs to remove (e.g. flow,passwords)}
        {--context= : Target a specific kube-context}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Interactively remove LaraKube shared cluster tools';

    public function handle(): int
    {
        $this->renderHeader();

        [$env, $kubectl] = $this->resolveStandaloneEnvironmentAndKubectl();

        $tools = $this->resolveTools($kubectl, 'remove');

        if (empty($tools)) {
            return 0;
        }

        if (! $this->confirmDestructive($this->removalLines($tools))) {
            return 0;
        }

        // Teardown moved out of `{tool}:init --remove` into a real
        // `{tool}:remove` command. This still proxied to the old flag, which no
        // longer exists — every `tool:remove` would have died with
        // InvalidOptionException. Nothing covered it, so it went unnoticed.
        $params = ['--no-interaction' => true, '--force' => true];

        // {tool}:remove declares {environment=local}, so always pass one.
        $params['environment'] = $env ?: 'local';

        if ($this->option('context')) {
            $params['--context'] = $this->option('context');
        }

        $exitCode = 0;

        foreach ($tools as $tool) {
            $this->line("Proxying to {$tool->removeCommand()}...");
            $this->newLine();

            $result = $this->call($tool->removeCommand(), $params);

            if ($result === 0) {
                // The {tool}:remove base already unregisters; this is a
                // belt-and-braces sweep for a tool whose own command skipped it.
                $this->unregisterTool($kubectl, $tool);
            } else {
                $exitCode = $result;
            }
        }

        return $exitCode;
    }
}
