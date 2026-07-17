<?php

namespace App\Traits;

use App\Enums\ClusterTool;

use function Laravel\Prompts\select;

trait ResolvesClusterTool
{
    /**
     * Resolve a ClusterTool from an argument, or prompt the user.
     */
    protected function resolveTool(string $actionHint = 'install'): ?ClusterTool
    {
        $slug = $this->argument('tool');

        if ($slug !== null) {
            $tool = ClusterTool::tryFrom($slug);
            if ($tool === null) {
                $this->error("  Unknown tool: {$slug}");
                $this->line('  Available tools: '.implode(', ', array_map(fn ($t) => $t->value, ClusterTool::cases())));

                return null;
            }

            return $tool;
        }

        $options = ClusterTool::options();

        $choice = select(
            label: "Which tool would you like to {$actionHint}?",
            options: $options,
            scroll: count($options),
            hint: "Select a shared tool to {$actionHint}.",
        );

        return ClusterTool::from($choice);
    }
}
