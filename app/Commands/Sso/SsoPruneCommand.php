<?php

namespace App\Commands\Sso;

use App\Enums\ClusterTool;
use App\Exceptions\MissingFlagException;
use App\Traits\InteractsWithSsoGrants;
use App\Traits\InteractsWithToolRegistry;
use App\Traits\InteractsWithZitadelApi;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * Find Zitadel projects nothing live references anymore and delete them.
 *
 * Projects get stranded whenever wiring moves to a different project name
 * while the old one keeps everything inside it: the 2026-08-20 RBAC redesign
 * renamed `forgejo` → `git-forgejo` (rbacProjectName() = deploymentName())
 * and silently orphaned the original — operators then granted roles on the
 * ghost project because it sat in the Zitadel console looking legitimate,
 * and projectRoleCheck denied their logins with a cryptic "at least one
 * grant" error (live incident 2026-08-24). sso:unwire can never clean these:
 * it deletes the app the sso-app-{tool} Secret currently tracks, so a
 * re-wire that changed project names leaves the old project ownerless.
 *
 * Safety model — a project is prunable ONLY if BOTH hold:
 *  1. Its id appears in NO `sso-app-*` Secret's project-id key (every wired
 *     tool records its live project there; this is the authoritative
 *     reference set, not a heuristic).
 *  2. Its name matches no protected name: the two fixed system projects,
 *     every shipped tool's unnamed rbacProjectName(), and every registered
 *     tool instance's rbacProjectName($instance) from the tools registry.
 *
 * Deletion cascades the project's apps + user grants inside Zitadel — dead
 * apps also mean dead client credentials, which is the point.
 */
class SsoPruneCommand extends Command
{
    use InteractsWithSsoGrants;
    use InteractsWithToolRegistry;
    use InteractsWithZitadelApi;
    use LaraKubeOutput;
    use RequiresFlagsWhenNonInteractive;

    protected $signature = 'sso:prune
        {environment=local : Environment whose Zitadel to target}
        {--context=   : Target a specific kube-context}
        {--project=*  : Candidate project id(s) or name(s) to prune — skips interactive selection}
        {--force      : Confirm deletions without prompting (non-interactive runs require this AND --project=)}';

    protected $description = 'Delete Zitadel projects no longer referenced by any wired tool (orphans left behind by renames/re-wires)';

    public function handle(): int
    {
        $this->renderHeader();

        $connection = $this->resolveSsoGrantConnection((string) $this->argument('environment'), $this->option('context'));
        if ($connection === null) {
            return 1;
        }
        [$ssoHost, $pat, $kubectl] = $connection;

        $liveIds = $this->referencedProjectIds($kubectl);
        if ($liveIds === null) {
            $this->laraKubeError("Could not read the sso-app-* secrets in '{$this->ssoNamespace()}' — refusing to prune without the live reference set.");

            return 1;
        }

        $protectedNames = $this->protectedProjectNames($kubectl);

        $projects = $this->zitadelListAllProjects($ssoHost, $pat);
        if ($projects === []) {
            $this->laraKubeError('Could not list Zitadel projects — check the automation credentials.');

            return 1;
        }

        $candidates = array_values(array_filter(
            $projects,
            fn (array $p) => ! in_array($p['name'], $protectedNames, true) && ! in_array($p['id'], $liveIds, true),
        ));

        // --project= accepts ids OR names, but only ever narrows to actual
        // candidates — naming a live/protected/unknown project is an error,
        // not a silent no-op (an operator mistyping into a mass-delete flag
        // must hear about it).
        $requested = (array) $this->option('project');
        if ($requested !== []) {
            [$selected, $rejected] = $this->resolveRequested($requested, $candidates);
            foreach ($rejected as $r) {
                $this->laraKubeError("'{$r}' is not a prunable project — either unknown, still wire-tracked, or a system project.");
            }

            if ($rejected !== []) {
                return 1;
            }

            $candidates = $selected;
        }

        if ($candidates === []) {
            $this->laraKubeInfo('No orphaned Zitadel projects — nothing to prune.');

            return 0;
        }

        if ($this->cannotPrompt()) {
            if (! $this->option('force') || $requested === []) {
                throw new MissingFlagException(
                    'project',
                    'Pruning deletes whole projects non-interactively — pass --force together with the exact --project=<id> list.',
                    'sso:prune production --project=387127298416967780 --force',
                );
            }
        } else {
            table(
                headers: ['Orphaned Project', 'ID'],
                rows: array_map(fn (array $p) => [$p['name'], $p['id']], $candidates),
            );

            if (! $this->flagOrConfirm('force', fn (): bool => confirm(
                label: count($candidates).' project(s) will be deleted — apps and role assignments inside them are lost. Continue?',
                default: false,
            ))) {
                $this->laraKubeInfo('Aborted — nothing was deleted.');

                return 0;
            }
        }

        $failed = 0;
        foreach ($candidates as $candidate) {
            // Re-check the gate against a FRESHLY-read reference set right
            // before each deletion: something could have wired this project
            // between listing and now (another operator, another terminal).
            $fresh = $this->referencedProjectIds($kubectl);
            if ($fresh === null || in_array($candidate['id'], $fresh, true)) {
                $this->laraKubeError("Skipped '{$candidate['name']}' — it became wire-referenced mid-run.");

                continue;
            }

            if ($this->withSpin("Deleting '{$candidate['name']}'...", fn (): bool => $this->zitadelDeleteProject($ssoHost, $pat, $candidate['id']))) {
                $this->laraKubeLine("    <fg=green>✓</> pruned '{$candidate['name']}' ({$candidate['id']})");
            } else {
                $this->laraKubeLine("    <fg=red>✗</> Zitadel refused to delete '{$candidate['name']}' ({$candidate['id']})");
                $failed++;
            }
        }

        return $failed === 0 ? 0 : 1;
    }

    /**
     * The authoritative live-reference set: every `sso-app-*` Secret in the
     * SSO namespace carries the project-id of the app a tool actually logs
     * in through (written by sso:wire for native-OIDC, forward-auth, AND
     * CLI-OIDC tools alike). Null means the sweep itself failed — callers
     * must refuse to prune rather than treat "unreadable" as "unreferenced".
     *
     * @return list<string>|null
     */
    protected function referencedProjectIds(string $kubectl): ?array
    {
        $raw = Process::run("{$kubectl} get secrets -n {$this->ssoNamespace()} -o json");

        if (! $raw->successful()) {
            return null;
        }

        $decoded = json_decode($raw->output(), true);
        if (! is_array($decoded)) {
            return null;
        }

        $ids = [];
        foreach ($decoded['items'] ?? [] as $item) {
            $name = $item['metadata']['name'] ?? '';
            $projectId = $item['data']['project-id'] ?? null;

            if (str_starts_with($name, 'sso-app-') && is_string($projectId)) {
                $decodedId = base64_decode($projectId, true);

                if ($decodedId !== false && $decodedId !== '') {
                    $ids[] = $decodedId;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Names sso:prune never touches regardless of references: the Zitadel
     * system project, the shared open-to-org project, every shipped tool's
     * unnamed RBAC project name, and every REGISTERED instance's per-instance
     * project name from the tools registry (multi-instance tools key projects
     * off deploymentName($instance), so a registered-but-unwired-yet
     * instance's project must survive even with no sso-app secret yet).
     *
     * @return list<string>
     */
    protected function protectedProjectNames(string $kubectl): array
    {
        $names = ['ZITADEL', ClusterTool::ssoAdminProjectName()];

        foreach (ClusterTool::shippedCases() as $tool) {
            if ($tool->hasSsoWire()) {
                $names[] = $tool->rbacProjectName();
            }
        }

        foreach ($this->getRegisteredTools($kubectl) as $entry) {
            $tool = ClusterTool::tryFrom((string) ($entry['tool'] ?? ''));
            $instance = $entry['instance'] ?? null;

            if ($tool !== null && $tool->hasSsoWire() && is_string($instance) && $instance !== '') {
                $names[] = $tool->rbacProjectName($instance);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Split --project= values into resolved candidates and refusals. Values
     * may be ids or names; anything that isn't one of the current candidates
     * (unknown, live-referenced, or protected) lands in $rejected.
     *
     * @param  list<string>  $requested
     * @param  list<array{id: string, name: string}>  $candidates
     * @return array{0: list<array{id: string, name: string}>, 1: list<string>}
     */
    protected function resolveRequested(array $requested, array $candidates): array
    {
        $byKey = collect($candidates)
            ->flatMap(fn (array $p) => [$p['id'] => $p, $p['name'] => $p]);

        $selected = [];
        $rejected = [];

        foreach ($requested as $key) {
            $match = $byKey[$key] ?? null;

            if ($match === null) {
                $rejected[] = $key;
            } elseif (! in_array($match, $selected, true)) {
                $selected[] = $match;
            }
        }

        return [$selected, $rejected];
    }
}
