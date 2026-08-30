<?php

namespace App\Traits;

use App\Enums\ClusterTool;

use function Laravel\Prompts\select;

/**
 * The one tool picker every `*:wire` / `*:unwire` pair shares.
 *
 * Extracted after the pairs drifted: sso:* listed real installations with their
 * hosts while mail:*, secrets:* and vpn:* listed bare tool labels, so on a
 * cluster running two instances of a tool there was no way to say which one you
 * meant -- the picker could not even show that a second one existed.
 *
 * Two properties matter and are easy to get wrong separately:
 *
 *   - Options are keyed by "{tool}|{host}" STRINGS. Laravel Prompts treats an
 *     integer-keyed array as a LIST and hands back the selected label instead of
 *     the key; casting that to a tool then silently resolves to whatever sits
 *     first. Confirmed live 2026-08-29 -- picking NetBird wired Matrix.
 *   - Choices come from the cluster registry, so only what is actually
 *     installed can be picked, and each instance appears on its own row with
 *     the host that identifies it.
 */
trait PicksRegisteredTool
{
    use InteractsWithToolRegistry;

    /**
     * @param  callable(ClusterTool): bool  $capable  the pair's marker predicate
     * @param  (callable(ClusterTool, string): bool)|null  $wired  extra gate, e.g. "is currently wired"
     * @return array{0: ClusterTool, 1: ?string}|null tool + the chosen instance's host
     */
    protected function pickRegisteredTool(
        string $kubectl,
        string $label,
        callable $capable,
        ?callable $wired = null,
        string $emptyMessage = 'No matching tools are registered on this cluster.',
    ): ?array {
        $choices = [];

        foreach ($this->getRegisteredTools($kubectl) as $entry) {
            $tool = ClusterTool::tryFrom((string) ($entry['tool'] ?? ''));

            if ($tool === null || ! $tool->isShipped() || ! $capable($tool)) {
                continue;
            }

            $host = (string) ($entry['host'] ?? '');

            if ($wired !== null && ! $wired($tool, $host)) {
                continue;
            }

            $choices[$tool->value.'|'.$host] = [
                'tool' => $tool,
                'host' => $host !== '' ? $host : null,
                'label' => $host !== '' ? "{$tool->getLabel()} ({$host})" : $tool->getLabel(),
            ];
        }

        if ($choices === []) {
            $this->laraKubeError($emptyMessage);
            $this->line('  <fg=gray>Only tools installed through their own</> <fg=blue>:init</> <fg=gray>appear here.</>');

            return null;
        }

        $key = (string) select(
            label: $label,
            options: array_map(fn (array $c): string => $c['label'], $choices),
            scroll: min(count($choices), 15),
        );

        return isset($choices[$key]) ? [$choices[$key]['tool'], $choices[$key]['host']] : null;
    }
}
