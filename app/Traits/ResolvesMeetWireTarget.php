<?php

namespace App\Traits;

use App\Enums\ClusterTool;
use App\Exceptions\MissingFlagException;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

/**
 * The `--tool=` resolution shared by meet:wire and meet:unwire. Split out of
 * InteractsWithMeet so that trait stays free of command context ($this->option,
 * prompts) and can be reused by the MEET case of LaravelFeature, which has
 * neither. (Written without the `::` form on purpose: EnumImportResolutionTest
 * scans raw source, comments included, and would read it as a missing import.)
 */
trait ResolvesMeetWireTarget
{
    /**
     * Resolve which tool to wire. Mirrors MailWireCommand::resolveTargets():
     * honour --tool= when given, prompt from the installed set when not, and
     * fail with the flag name rather than hanging when there is no TTY (CI,
     * MCP, the `larakube` proxy). Deliberately no default on the flag — a
     * silent assumption is wrong the moment a second tool becomes wireable.
     */
    protected function resolveMeetWireTarget(string $kubectl, string $verb): ?ClusterTool
    {
        $installed = array_values(array_filter(
            ClusterTool::cases(),
            fn (ClusterTool $t) => $t->hasMeetWire()
                && trim(Process::run(
                    "{$kubectl} get deployment {$t->deploymentName()} -n {$t->namespace()} --no-headers --ignore-not-found",
                )->output()) !== '',
        ));

        $slug = $this->option('tool');

        if ($slug !== null && $slug !== '') {
            $tool = ClusterTool::tryFrom($slug);

            if ($tool === null || ! $tool->hasMeetWire()) {
                $this->laraKubeError("'{$slug}' cannot be wired to Meet. Laravel apps use `larakube add meet` instead.");

                return null;
            }

            if (! in_array($tool, $installed, true)) {
                $this->laraKubeError("{$tool->getLabel()} is not installed on this cluster.");

                return null;
            }

            return $tool;
        }

        if ($installed === []) {
            $this->laraKubeError('No Meet-capable tools are installed on this cluster.');

            return null;
        }

        $options = [];
        foreach ($installed as $tool) {
            $options[$tool->value] = $tool->getLabel();
        }

        if ($this->cannotPrompt()) {
            throw new MissingFlagException('tool', "which tool to {$verb}", "larakube meet:{$verb} production --tool=…");
        }

        return ClusterTool::from(select(
            label: "Which tool would you like to {$verb} ".($verb === 'wire' ? 'to' : 'from').' Meet?',
            options: $options,
            scroll: count($options),
        ));
    }
}
