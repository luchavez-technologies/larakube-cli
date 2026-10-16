<?php

namespace App\Commands\Tool;

use App\Enums\ClusterTool;
use App\Exceptions\MissingFlagException;
use App\Services\Kubectl;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithToolRegistry;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolHost;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

/**
 * Re-point which host currently serves an already-installed Cluster Tool,
 * WITHOUT minting a new instance identity. Per ADR 0012/0021, a tool's
 * Kubernetes resource names (Deployment/Service/PVC/Secret/DB/bucket) are
 * keyed off an opaque instance slug, not the live host directly — but every
 * `*:init` command re-derives that slug fresh from whatever host it's given,
 * so simply re-running `{tool}:init --domain=<new>` after losing access to a
 * domain mints a brand-new, empty instance instead of recovering the old one
 * (see cli/plans/active/tool-rebind-host-identity.md).
 *
 * This command does only the two things that actually need to change: the
 * registry's recorded host (so every later lookup — including sso:wire's —
 * resolves back to the SAME pinned instance), and the live Ingress host/TLS.
 * It deliberately never calls `{tool}:init` itself.
 */
class ToolRebindCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithToolRegistry, LaraKubeOutput, ResolvesToolHost;

    protected $signature = 'tool:rebind
        {environment=local : Environment whose deployment to rebind}
        {--tool= : The tool to rebind (e.g. stalwart, matrix, forgejo)}
        {--domain= : The CURRENT host identifying which registered instance to target. Omit for the tool\'s sole instance}
        {--to= : The NEW host this instance should be served under}
        {--context= : Target a specific kube-context}
        {--wire-sso : Automatically re-wire Zitadel SSO to the new domain if the tool is SSO-capable}
        {--no-wire-sso : Do not re-wire Zitadel SSO}
        {--force : Skip the confirmation prompt; required together with --to when non-interactive}';

    protected $description = "Re-point an already-installed Cluster Tool's live host without changing its resource identity";

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $context = $this->resolveToolContext($env, $this->option('context'));
        $kubectl = Kubectl::forContext($context)->prefix();

        $toolSlug = $this->flagOrPrompt(
            'tool',
            fn () => text(label: 'Which tool should be rebound?', required: true),
            'the tool to rebind',
            '--tool=stalwart',
        );
        $tool = ClusterTool::tryFrom($toolSlug);
        if ($tool === null) {
            $this->laraKubeError("Unknown tool '{$toolSlug}'.");

            return 1;
        }

        $domainOption = (string) ($this->option('domain') ?: '');
        $instancePreference = $this->resolveInstanceForTool($tool, $kubectl, $domainOption);
        if ($instancePreference === false) {
            return 1;
        }

        // registerTool()/matchIndex() happily accept a null "no preference"
        // instance to find the tool's sole row — but resource NAMING must
        // use the real pinned slug the registry already stores for it
        // (InstanceData::$instance: "always a real, host-derived slug, never
        // a bare/empty default"), never a blind null-coalesce to ''. Passing
        // '' to reapplyToolIngress() would target the bare/legacy-named
        // Ingress instead of the real {tool}-{instance} one.
        $entry = $this->findToolInstanceEntry($kubectl, $tool, $instancePreference);
        if ($entry === null) {
            $this->laraKubeError("Tool '{$tool->value}' is not registered on this cluster — nothing to rebind.");

            return 1;
        }

        $instance = (string) ($entry['instance'] ?? '');
        $suffix = $instance !== '' ? " ({$instance})" : '';

        $currentHost = (string) ($entry['host'] ?? '');
        if ($currentHost === '') {
            $this->laraKubeError("Could not resolve the current host for {$tool->getLabel()}{$suffix}.");

            return 1;
        }

        $newHost = $this->sanitizeDomainInput($this->flagOrPrompt(
            'to',
            fn () => text(label: "What host should {$tool->getLabel()}{$suffix} move to?", required: true),
            'the new host this instance should be served under',
            '--to=new.example.com',
        ));

        if ($newHost === $currentHost) {
            $this->laraKubeInfo("{$tool->getLabel()}{$suffix} already serves {$newHost} — nothing to rebind.");

            return 0;
        }

        $collision = $this->findHostCollision($kubectl, $tool, $instance, $newHost);
        if ($collision !== null) {
            $this->laraKubeError("'{$newHost}' is already registered to {$collision}.");

            return 1;
        }

        if (! $this->option('force')) {
            if ($this->cannotPrompt()) {
                throw new MissingFlagException('force', 'confirm a rebind when running non-interactively', '--force');
            }

            $confirmed = confirm(
                "Rebind {$tool->getLabel()}{$suffix} from {$currentHost} to {$newHost}? Kubernetes resource names are unaffected — only the live Ingress host and SSO/OIDC redirect URIs change.",
                default: false,
            );

            if (! $confirmed) {
                $this->laraKubeInfo('Aborted — nothing changed.');

                return 0;
            }
        }

        // The one write that matters: the registry's pinned instance never
        // moves, only the host it currently answers to. Every later lookup
        // against $newHost (including sso:wire's) now resolves back to this
        // SAME instance — see resolveInstanceForTool()/ToolRegistry::register().
        if (! $this->registerTool($kubectl, $tool, ['host' => $newHost], $instance)) {
            $this->laraKubeError('Failed to update the tool registry.');

            return 1;
        }

        $aliasHosts = $this->getToolAliasHosts($kubectl, $tool, $instance);

        $applied = $this->reapplyToolIngress($kubectl, $tool, $newHost, $aliasHosts, $instance, recordCurrentHost: true);
        if (! $applied) {
            $this->laraKubeError("Registry updated, but re-applying {$tool->getLabel()}'s Ingress failed — re-run this command to retry just that step.");

            return 1;
        }

        $this->issueLocalCertForHost($newHost);

        $this->laraKubeInfo("✅ {$tool->getLabel()}{$suffix} now serves https://{$newHost}.");
        $this->line("  <fg=gray>Resource names (Deployment/PVC/Secret/DB) are unchanged — instance:</> <fg=blue>{$instance}</>");
        $this->newLine();

        if ($this->shouldWireSso($tool, $kubectl)) {
            $this->call('sso:wire', array_filter([
                'environment' => $env,
                '--tool' => $tool->value,
                '--domain' => $newHost,
                '--context' => $this->option('context') ?: null,
            ]));
        } elseif ($tool->hasSsoWire()) {
            $this->line('  <fg=yellow>⚠ SSO-wired — redirect URIs changed.</> Re-register its OIDC client under the SAME Zitadel project:');
            $this->line("    <fg=blue>larakube sso:wire {$env} --tool={$tool->value} --domain={$newHost}</>");
            $this->newLine();
        }

        $this->line('  <fg=gray>Note: any Deployment env var baked with the old public URL (e.g. a dashboard base URL) is NOT refreshed by this command.</>');

        if ($tool === ClusterTool::STALWART) {
            $this->line("  <fg=gray>Mail: stale DKIM/SPF/MX records for {$currentHost} are not cleaned up automatically — no cleanup command exists for that yet.</>");
        }

        $this->newLine();

        return 0;
    }

    protected function shouldWireSso(ClusterTool $tool, string $kubectl): bool
    {
        if (! $tool->hasSsoWire()) {
            return false;
        }

        if (! $this->isToolRegistered($kubectl, ClusterTool::ZITADEL)) {
            return false;
        }

        if ($this->option('wire-sso')) {
            return true;
        }

        if ($this->option('no-wire-sso')) {
            return false;
        }

        if ($this->cannotPrompt()) {
            return false;
        }

        return confirm("Re-wire {$tool->getLabel()} to Zitadel SSO under the new host now?", default: true);
    }

    /**
     * Whether $newHost is already registered to a DIFFERENT tool/instance
     * anywhere on the cluster — rebinding onto a host something else already
     * owns would silently steal it rather than fail loudly.
     */
    protected function findHostCollision(string $kubectl, ClusterTool $tool, string $instance, string $newHost): ?string
    {
        foreach ($this->toolRegistry($kubectl)->rows() as $row) {
            if (($row['host'] ?? null) !== $newHost) {
                continue;
            }

            $rowTool = ClusterTool::tryFrom((string) ($row['tool'] ?? ''));
            $rowInstance = (string) ($row['instance'] ?? '');
            $isSameEntry = $rowTool === $tool && $rowInstance === $instance;

            if (! $isSameEntry) {
                $label = $rowTool?->getLabel() ?? (string) ($row['tool'] ?? 'an unknown tool');

                return $rowInstance !== '' ? "{$label} ({$rowInstance})" : $label;
            }
        }

        return null;
    }
}
