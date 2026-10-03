<?php

namespace App\Commands\Tool;

use App\Enums\ClusterTool;
use App\Services\Tools\InitOption;
use App\Services\Tools\ToolInitCommands;
use App\Services\Tools\ToolInitSpec;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;

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

        foreach (self::unionOfOptions() as $option) {
            $this->getDefinition()->addOption($this->inputOption($option));
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

        foreach (self::unionOfOptions() as $option) {
            if (! $this->input->hasParameterOption('--'.$option->name)) {
                continue;
            }

            if (! in_array($option->name, $allowed, true)) {
                $this->laraKubeError("{$tool->brandName()} has no --{$option->name} option.");
                $this->line('  <fg=gray>It takes:</> '.implode(' ', array_map(fn (string $name): string => "--{$name}", $allowed)));

                return 1;
            }

            $params['--'.$option->name] = $this->forwardedValue($option);
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

    /**
     * A flag is on when it was given; a value is what was typed. `--proxied`
     * is a flag for most tools and a value for the ones that default to it.
     */
    private function forwardedValue(InitOption $option): mixed
    {
        $given = $this->input->getOption($option->name);

        return $option->kind === InitOption::FLAG ? true : ($given ?? ($option->default ?? true));
    }

    /**
     * Every distinct option any tool takes, once. `--proxied` is both a flag
     * and a `=1` value across tools, so it is registered as an optional value.
     *
     * @return array<string, InitOption>
     */
    private static function unionOfOptions(): array
    {
        $options = [];

        foreach (ClusterTool::shippedCases() as $tool) {
            if (! ToolInitCommands::has($tool)) {
                continue;
            }

            foreach (ToolInitSpec::for($tool) as $option) {
                $options[$option->name] ??= $option;
            }
        }

        return $options;
    }

    private function inputOption(InitOption $option): InputOption
    {
        $mode = match (true) {
            $option->name === 'proxied' => InputOption::VALUE_OPTIONAL,
            $option->kind === InitOption::FLAG => InputOption::VALUE_NONE,
            $option->kind === InitOption::LIST => InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            default => InputOption::VALUE_REQUIRED,
        };

        $description = $option->name === 'domain' ? 'Base domain OR full host for the tool (example.com → prefix.example.com)' : $option->description;

        return new InputOption($option->name, null, $mode, $description, $mode & InputOption::VALUE_IS_ARRAY ? [] : null);
    }
}
