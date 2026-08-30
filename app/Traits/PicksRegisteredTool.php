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
     * The tool named by --tool, if any.
     *
     * Returns null when the flag is absent (pick from everything) and false
     * when it names something unusable, so a caller can tell "nothing asked
     * for" apart from "asked for something bad" without a second error path.
     */
    protected function namedTool(string $option = 'tool'): ClusterTool|false|null
    {
        $slug = (string) ($this->option($option) ?: '');

        if ($slug === '') {
            return null;
        }

        $tool = ClusterTool::tryFrom($slug);

        if ($tool === null) {
            $this->laraKubeError("Unknown tool '{$slug}'.");

            return false;
        }

        if (method_exists($this, 'refuseUnshippedTool') && $this->refuseUnshippedTool($tool)) {
            return false;
        }

        return $tool;
    }

    /**
     * @param  callable(ClusterTool): bool  $capable  the pair's marker predicate
     * @param  (callable(ClusterTool, string): bool)|null  $wired  extra gate, e.g. "is currently wired"
     * @param  ClusterTool|null  $only  narrow the list to this tool's instances (--tool)
     * @param  string|null  $domain  select this instance outright, no prompt (--domain)
     * @return array{0: ClusterTool, 1: ?string}|null tool + the chosen instance's host
     */
    protected function pickRegisteredTool(
        string $kubectl,
        string $label,
        callable $capable,
        ?callable $wired = null,
        string $emptyMessage = 'No matching tools are registered on this cluster.',
        ?ClusterTool $only = null,
        ?string $domain = null,
    ): ?array {
        $choices = [];

        foreach ($this->getRegisteredTools($kubectl) as $entry) {
            $tool = ClusterTool::tryFrom((string) ($entry['tool'] ?? ''));

            if ($tool === null || ! $tool->isShipped() || ! $capable($tool)) {
                continue;
            }

            // --tool narrows to that tool's instances rather than skipping the
            // choice entirely. Naming a tool used to mean "and take its default
            // instance", which on a multi-instance tool silently acted on the
            // wrong one.
            if ($only !== null && $tool !== $only) {
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

        // --domain names one instance outright, so there is nothing to ask.
        $domain = $domain !== null ? trim($domain) : null;
        if ($domain !== null && $domain !== '') {
            foreach ($choices as $choice) {
                if ($choice['host'] === $domain) {
                    return [$choice['tool'], $choice['host']];
                }
            }

            // Unmatched, but the tool was named: honour it rather than refusing.
            // The registry can legitimately lag a freshly-created instance, and
            // downstream resolveInstanceForDomain() derives a slug either way.
            if ($only !== null) {
                return [$only, $domain];
            }

            $this->laraKubeError("No registered instance at '{$domain}'.");
            $this->line('  <fg=gray>Pass</> <fg=blue>--tool</> <fg=gray>as well if the instance is not registered yet.</>');

            return null;
        }

        if ($choices === []) {
            // A named tool with nothing registered keeps working: the registry
            // is a convenience here, not a gate, and refusing would break
            // scripted runs against a cluster whose registry lags.
            if ($only !== null) {
                return [$only, null];
            }

            $this->laraKubeError($emptyMessage);
            $this->line('  <fg=gray>Only tools installed through their own</> <fg=blue>:init</> <fg=gray>appear here.</>');

            return null;
        }

        if (count($choices) === 1) {
            $choice = reset($choices);

            return [$choice['tool'], $choice['host']];
        }

        if ($this->option('no-interaction')) {
            $this->laraKubeError('Several instances match — pass --domain to choose one.');
            foreach ($choices as $choice) {
                $this->line('  <fg=gray>  • '.$choice['label'].'</>');
            }

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
