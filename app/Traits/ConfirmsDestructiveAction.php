<?php

namespace App\Traits;

use App\Enums\ClusterTool;

use function Laravel\Prompts\text;

trait ConfirmsDestructiveAction
{
    /**
     * Require the operator to type "confirm" before proceeding.
     * Bypassed with --force (typically from automation or scripting).
     *
     * @param  list<string>  $lines  One-line summary of what will happen.
     */
    protected function confirmDestructive(array $lines): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $this->newLine();
        foreach ($lines as $line) {
            $this->line("  <fg=red>{$line}</>");
        }
        $this->newLine();

        $answer = text(
            label: 'Type confirm to proceed',
            placeholder: 'confirm',
            required: true,
        );

        if ($answer !== 'confirm') {
            $this->laraKubeInfo('Aborted.');

            return false;
        }

        $this->newLine();

        return true;
    }

    /**
     * Build the "will be removed" lines for a list of tools.
     *
     * @param  array<ClusterTool>  $tools
     * @return list<string>
     */
    protected function removalLines(array $tools): array
    {
        $names = implode(', ', array_map(fn ($t) => $t->getLabel(), $tools));

        return [
            'The following tools will be REMOVED:',
            $names,
        ];
    }

    /**
     * Build the "will be installed" lines for a list of tools.
     *
     * @param  array<ClusterTool>  $tools
     * @return list<string>
     */
    protected function installLines(array $tools): array
    {
        $names = implode(', ', array_map(fn ($t) => $t->getLabel(), $tools));

        return [
            'The following tools will be INSTALLED:',
            $names,
        ];
    }
}
