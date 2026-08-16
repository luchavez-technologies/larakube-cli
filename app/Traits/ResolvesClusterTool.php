<?php

namespace App\Traits;

use App\Enums\ClusterTool;

use function Laravel\Prompts\multiselect;

trait ResolvesClusterTool
{
    use InteractsWithToolRegistry, RefusesUnshippedTools;

    /**
     * Resolve one or more ClusterTools from the --tool option (comma-separated)
     * or via an interactive multiselect prompt. Filters available tools based
     * on whether we're adding or removing them against the cluster's registry.
     *
     * @return array<ClusterTool>
     */
    protected function resolveTools(string $kubectl, string $actionHint = 'install'): array
    {
        $raw = $this->option('tool');

        if ($raw !== null) {
            $slugs = array_map('trim', explode(',', $raw));
            $tools = [];

            foreach ($slugs as $slug) {
                $tool = ClusterTool::tryFrom($slug);
                if ($tool === null) {
                    $this->error("  Unknown tool: {$slug}");
                    $this->line('  Available tools: '.implode(', ', array_map(fn ($t) => $t->value, ClusterTool::shippedCases())));

                    return [];
                }
                if ($this->refuseUnshippedTool($tool)) {
                    return [];
                }
                $tools[] = $tool;
            }

            return $tools;
        }

        $installedTools = array_unique(array_column($this->getRegisteredTools($kubectl), 'tool'));
        $options = [];

        foreach (ClusterTool::shippedCases() as $tool) {
            $isInstalled = in_array($tool->value, $installedTools, true)
                || ($actionHint === 'remove' && $this->isToolPresentOnCluster($kubectl, $tool));

            if ($actionHint === 'install' && $isInstalled) {
                continue;
            }
            if ($actionHint === 'remove' && ! $isInstalled) {
                continue;
            }

            $options[$tool->value] = $tool->getLabel();
        }

        asort($options);

        if (empty($options)) {
            $this->laraKubeInfo("No shared tools available to {$actionHint} on this cluster.");

            return [];
        }

        $choices = multiselect(
            label: "Which tools would you like to {$actionHint}?",
            options: $options,
            scroll: count($options),
            required: true,
            hint: 'Type to filter, then select. Use space to toggle, enter to confirm.',
        );

        if (empty($choices)) {
            return [];
        }

        return array_map(fn ($value) => ClusterTool::from($value), $choices);
    }
}
