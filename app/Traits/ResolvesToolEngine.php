<?php

namespace App\Traits;

use App\Enums\ClusterTool;
use App\Exceptions\MissingFlagException;
use Illuminate\Support\Facades\Process;

/**
 * Resolve which engine a tool's instance is actually running, for the
 * *:wire/*:unwire commands that need to target the right Deployment/secret
 * once a tool has more than one implementation (DATA: pocketbase|directus,
 * FLOW: n8n|windmill). Before this existed, three DIFFERENT ad-hoc copies of
 * "guess the engine" lived in SsoWireCommand (CHAT+DATA only),
 * SsoUnwireCommand (FLOW only, via a hardcoded flow-windmill probe), and
 * MailWireCommand/MailUnwireCommand (CHAT only) — none of them covered every
 * multi-engine tool, which is exactly how a PocketBase-only `data` instance
 * ended up handed Directus's OIDC/SMTP/db-secret schema by commands that
 * never learned it might not be Directus.
 *
 * The registry's InstanceData->engine field is a HINT, never authoritative —
 * mirrors DataRemoveCommand's existing "live-probe, registry is
 * informational" discipline (see InstanceData's own docblock): a stale
 * registry entry must never cause a wire command to patch the wrong
 * engine's Deployment, so the hint is always confirmed live before use.
 *
 * Requires InteractsWithToolRegistry (for getToolInstanceData()) — every
 * consumer already has it via DeploysClusterTool.
 */
trait ResolvesToolEngine
{
    /**
     * @param  string|null  $flagEngine  An explicit --engine= value, which always wins.
     */
    protected function resolveInstanceEngine(string $kubectl, ClusterTool $tool, ?string $instance, ?string $flagEngine): ?string
    {
        if ($flagEngine !== null && $flagEngine !== '') {
            return $flagEngine;
        }

        $candidates = array_keys($tool->engines());
        if ($candidates === []) {
            return $tool->defaultEngine();
        }

        $registered = $this->getToolInstanceData($kubectl, $tool, $instance)?->engine;
        if ($registered !== null && $registered !== '' && $this->engineDeploymentExists($kubectl, $tool, $instance, $registered)) {
            return $registered;
        }

        $live = array_values(array_filter(
            $candidates,
            fn (string $engine) => $this->engineDeploymentExists($kubectl, $tool, $instance, $engine),
        ));

        return match (count($live)) {
            0 => $tool->defaultEngine(),
            1 => $live[0],
            default => $this->promptForInstanceEngine($tool, $live),
        };
    }

    /**
     * More than one engine's Deployment is live for this instance at once —
     * an unusual, likely-transitional state. Ask rather than silently guess;
     * non-interactively (CI, MCP, `larakube` proxy) throw the same
     * MissingFlagException RequiresFlagsWhenNonInteractive::flagOrPrompt()
     * uses, so this is a loud, actionable failure naming --engine= rather
     * than a stack trace or a silently-wrong guess.
     *
     * @param  list<string>  $liveEngines
     */
    protected function promptForInstanceEngine(ClusterTool $tool, array $liveEngines): string
    {
        if ($this->option('no-interaction') || app()->runningUnitTests() || ! stream_isatty(STDIN)) {
            throw new MissingFlagException(
                'engine',
                "Both {$tool->getLabel()}'s engines have a live Deployment for this instance",
                '--engine='.$liveEngines[0],
            );
        }

        $options = [];
        foreach ($liveEngines as $slug) {
            $options[$slug] = $tool->engines()[$slug] ?? $slug;
        }

        return \Laravel\Prompts\select(
            label: "Multiple {$tool->getLabel()} engines are installed — which one?",
            options: $options,
        );
    }

    private function engineDeploymentExists(string $kubectl, ClusterTool $tool, ?string $instance, string $engine): bool
    {
        return trim(Process::run(
            "{$kubectl} get deployment {$tool->deploymentName($instance, $engine)} -n {$tool->namespace()} --no-headers --ignore-not-found",
        )->output()) !== '';
    }
}
