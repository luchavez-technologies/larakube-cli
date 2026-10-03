<?php

namespace App\Commands\Tool;

use App\Enums\ClusterTool;
use App\Services\Tools\InitOption;
use App\Services\Tools\ToolInitCommands;
use App\Services\Tools\ToolInitOptions;
use App\Services\Tools\ToolInitSpec;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * Deploys one Cluster Tool: `larakube tool:init <environment> --tool=<slug>`.
 * It takes the options of every tool, runs the tool's own deploy with the ones
 * given, and refuses an option the chosen tool does not have, so a typo does
 * not silently do nothing.
 */
class ToolInitCommand extends Command
{
    use LaraKubeOutput;

    protected $signature = 'tool:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--tool= : The tool to deploy (e.g. outline, vaultwarden, zitadel)}';

    protected $description = 'Deploy a LaraKube cluster tool into the shared cluster';

    public function __construct()
    {
        parent::__construct();

        foreach (ToolInitOptions::union() as $option) {
            $this->getDefinition()->addOption(ToolInitOptions::inputOption($option));
        }
    }

    public function handle(): int
    {
        $tool = $this->resolveTool((string) $this->option('tool'));

        if ($tool === null) {
            return 1;
        }

        $allowed = array_map(fn (InitOption $option): string => $option->name, ToolInitSpec::for($tool));
        $params = $this->input->getArgument('environment') !== null ? ['environment' => $this->input->getArgument('environment')] : [];

        foreach (ToolInitOptions::union() as $option) {
            if (! $this->input->hasParameterOption('--'.$option->name)) {
                continue;
            }

            if (! in_array($option->name, $allowed, true)) {
                $this->laraKubeError("{$tool->brandName()} has no --{$option->name} option.");
                $this->line('  <fg=gray>It takes:</> '.implode(' ', array_map(fn (string $name): string => "--{$name}", $allowed)));

                return 1;
            }

            $params['--'.$option->name] = ToolInitOptions::forwardedValue($this->input, $option);
        }

        $command = ToolInitCommands::for($tool);
        $command->setLaravel($this->laravel);

        $input = new ArrayInput($params);
        $input->setInteractive(! $this->option('no-interaction'));

        return $command->run($input, $this->output);
    }

    private function resolveTool(string $slug): ?ClusterTool
    {
        $tool = ClusterTool::tryFrom(strtolower(trim($slug)));
        $available = array_values(array_filter(ClusterTool::shippedCases(), fn (ClusterTool $case): bool => ToolInitCommands::has($case)));

        if ($tool === null || ! in_array($tool, $available, true)) {
            $this->laraKubeError($slug === '' ? 'Say which tool to deploy with --tool=.' : "Unknown tool '{$slug}'.");
            $this->line('  <fg=gray>One of:</> '.implode(', ', array_map(fn (ClusterTool $case): string => $case->value, $available)));

            return null;
        }

        return $tool;
    }
}
