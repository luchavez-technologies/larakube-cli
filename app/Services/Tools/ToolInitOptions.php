<?php

namespace App\Services\Tools;

use App\Enums\ClusterTool;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Every option any tool's init takes, as one set a generic command can accept
 * (`tool:init`, `tool:add`), and the checks that keep an option from reaching a
 * tool that does not have it.
 */
final class ToolInitOptions
{
    /**
     * Every distinct option any deployable tool takes, once, by name.
     *
     * @return array<string, InitOption>
     */
    public static function union(): array
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

    /**
     * The console option for one of them. `--proxied` is a flag for most tools
     * and a `=1` value for the ones that default to it, so it takes an optional value.
     */
    public static function inputOption(InitOption $option): InputOption
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

    /**
     * The names of the given options that the tool does not take.
     *
     * @param  list<string>  $given
     * @return list<string>
     */
    public static function refusedBy(ClusterTool $tool, array $given): array
    {
        $allowed = array_map(fn (InitOption $option): string => $option->name, ToolInitSpec::for($tool));

        return array_values(array_diff($given, $allowed));
    }

    /**
     * The value to pass on for an option that was given: a flag is on, a value
     * is what was typed, and `--proxied` with no value means on.
     */
    public static function forwardedValue(InputInterface $input, InitOption $option): mixed
    {
        $given = $input->getOption($option->name);

        return $option->kind === InitOption::FLAG ? true : ($given ?? ($option->default ?? true));
    }

    /**
     * Which of the options in $names were actually typed on the command line.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function given(InputInterface $input, array $names): array
    {
        return array_values(array_filter($names, fn (string $name): bool => $input->hasParameterOption('--'.$name)));
    }
}
