<?php

namespace App\Commands\Tool;

use App\Enums\ClusterTool;
use App\Exceptions\MissingFlagException;
use App\Traits\InteractsWithToolRegistry;
use App\Traits\LaraKubeOutput;
use App\Traits\RefusesUnshippedTools;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesStandaloneEnvironment;
use LaravelZero\Framework\Commands\Command;

/**
 * Proxy to `{tool}:show`, so `tool:show --tool=flow` and `flow:show` are the
 * same thing. Two ways in, one implementation: the per-tool command is where
 * tool-specific detail lives, and this is the discoverable entry point for
 * someone who knows they have "some tool" but not its command name.
 *
 * Which tools exist and which are installed comes from the cluster registry,
 * never from a project file — see ToolListCommand for why.
 */
class ToolShowCommand extends Command
{
    use InteractsWithToolRegistry, LaraKubeOutput, RefusesUnshippedTools, RequiresFlagsWhenNonInteractive, ResolvesStandaloneEnvironment;

    protected $signature = 'tool:show
        {environment? : The environment to inspect}
        {--tool=      : Tool slug to show (e.g. flow, passwords)}
        {--context=   : Target a specific kube-context}
        {--json       : Forwarded to the underlying {tool}:show}';

    protected $description = 'Show how to reach one shared cluster tool (proxies {tool}:show)';

    public function handle(): int
    {
        [$env, $kubectl] = $this->resolveStandaloneEnvironmentAndKubectl();

        $tool = $this->resolveTool($kubectl);
        if ($tool === null) {
            return 1;
        }

        if ($tool->service() === null) {
            $this->laraKubeError("{$tool->getLabel()} has no web interface, so there is nothing to show.");

            return 1;
        }

        $params = ['environment' => $env ?: 'local'];

        if ($this->option('context')) {
            $params['--context'] = $this->option('context');
        }
        if ($this->option('json')) {
            $params['--json'] = true;
        }

        return $this->call($tool->showCommand(), $params);
    }

    /**
     * The tool to show: --tool when given, otherwise a pick from what is
     * ACTUALLY installed on this cluster (not the full catalogue — offering an
     * uninstalled tool would only ever produce "not installed").
     */
    protected function resolveTool(string $kubectl): ?ClusterTool
    {
        $slug = (string) ($this->option('tool') ?? '');

        if ($slug !== '') {
            $tool = ClusterTool::tryFrom($slug);
            if ($tool === null) {
                $this->laraKubeError("Unknown tool '{$slug}'.");
                $this->line('  <fg=gray>Available: </>'.implode(', ', array_column(ClusterTool::shippedCases(), 'value')));

                return null;
            }
            if ($this->refuseUnshippedTool($tool)) {
                return null;
            }

            return $tool;
        }

        $installedTools = array_values(array_unique(array_column($this->getRegisteredTools($kubectl), 'tool')));

        if ($installedTools === []) {
            $this->laraKubeInfo('No tools are installed on this cluster.');
            $this->line('  <fg=gray>Install one with</> <fg=blue>larakube tool:add</><fg=gray>.</>');

            return null;
        }

        if ($this->cannotPrompt()) {
            throw new MissingFlagException(
                'tool',
                'which tool to show',
                'larakube tool:show production --tool='.$installedTools[0],
            );
        }

        $options = [];
        foreach (ClusterTool::shippedCases() as $tool) {
            if (in_array($tool->value, $installedTools, true)) {
                $options[$tool->value] = $tool->getLabel();
            }
        }

        asort($options);

        return ClusterTool::from(\Laravel\Prompts\select(
            label: 'Which tool would you like to see?',
            options: $options,
            scroll: max(5, count($options)),
        ));
    }
}
