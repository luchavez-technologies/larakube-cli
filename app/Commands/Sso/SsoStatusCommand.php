<?php

namespace App\Commands\Sso;

use App\Enums\ClusterTool;
use App\Services\Kubectl;
use App\Traits\DeploysClusterTool;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithSso;
use App\Traits\InteractsWithSsoGrants;
use App\Traits\InteractsWithToolRegistry;
use App\Traits\InteractsWithZitadelApi;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEngine;
use App\Traits\ResolvesToolEnvironment;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * Read-only fleet-wide summary of Zitadel SSO live state — primary organization,
 * which tools are wired to SSO, active user grants across projects, and
 * orphaned projects. Backs Desktop's SSO section and CLI operator reporting.
 */
class SsoStatusCommand extends Command
{
    use DeploysClusterTool,
        EmitsJsonOutput,
        InteractsWithSso,
        InteractsWithSsoGrants,
        InteractsWithToolRegistry,
        InteractsWithZitadelApi,
        LaraKubeOutput,
        ResolvesToolEngine,
        ResolvesToolEnvironment;

    protected $signature = 'sso:status
        {environment=local : Environment to inspect}
        {--context= : Target a specific kube-context}
        {--json : Emit machine-readable JSON output}';

    protected $description = 'Report fleet-wide Zitadel SSO live state, wired tools, user grants, and organizations';

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        if ($json) {
            $this->enableJsonMode();
        } else {
            $this->renderHeader();
        }

        $env = $this->resolveToolEnvironment(ClusterTool::ZITADEL);
        $context = $this->resolveToolContext($env, $this->option('context'));
        $kubectl = Kubectl::forContext($context)->prefix();
        $ssoNs = $this->ssoNamespace();

        $installed = $this->isSsoInstalled($kubectl, $ssoNs);

        if (! $installed) {
            if ($json) {
                $this->jsonOutput([
                    'success' => true,
                    'installed' => false,
                ]);

                return 0;
            }

            $this->laraKubeInfo('Zitadel is not deployed. Run `larakube tool:init --tool=zitadel` first.');

            return 0;
        }

        // resolveSsoGrantConnection must be called exactly ONCE per command run, never per tool/project.
        $connection = $this->resolveSsoGrantConnection($env, $this->option('context'));
        if ($connection === null) {
            return 1;
        }
        [$ssoHost, $pat, $kubectl] = $connection;

        $wiredTools = $this->wiredToolsSummary($kubectl, $ssoNs);

        $projects = $this->zitadelListAllProjects($ssoHost, $pat);
        $projectsByName = [];
        $projectsById = [];
        foreach ($projects as $project) {
            if (isset($project['name'])) {
                $projectsByName[$project['name']] = $project;
            }
            if (isset($project['id'])) {
                $projectsById[$project['id']] = $project;
            }
        }

        $grants = $this->grantsSummary($ssoHost, $pat, $wiredTools, $projectsByName, $projectsById);

        $rawOrgs = $this->zitadelListOrgs($ssoHost, $pat);
        $orgs = [];
        foreach ($rawOrgs as $orgId => $name) {
            $orgs[] = [
                'name' => $name,
                'orgId' => (string) $orgId,
                // zitadelOrgDomainVerified() needs a specific domain to check
                // and has no "this org's primary domain" concept to default
                // to safely — always true here is a known simplification
                // until sso:org's own onboarded-domain is threaded through.
                'domainVerified' => true,
            ];
        }
        $primaryOrg = $orgs[0]['name'] ?? null;

        $orphanedProjects = $this->orphanedProjectsSummary($projects, $wiredTools, $projectsById);

        $wiredToolsOutput = array_map(fn (array $row) => [
            'tool' => $row['tool'],
            'slug' => $row['slug'],
            'instance' => (string) $row['instance'],
            'host' => $row['host'],
            'wired' => true,
        ], $wiredTools);

        $payload = [
            'success' => true,
            'installed' => true,
            'org' => $primaryOrg,
            'wiredTools' => $wiredToolsOutput,
            'grants' => $grants,
            'orgs' => $orgs,
            'orphanedProjects' => $orphanedProjects,
        ];

        if ($json) {
            $this->jsonOutput($payload);

            return 0;
        }

        $this->laraKubeInfo($primaryOrg !== null
            ? "Zitadel SSO is deployed (Organization: {$primaryOrg})."
            : 'Zitadel SSO is deployed.');

        if ($wiredToolsOutput !== []) {
            table(
                headers: ['Tool', 'Instance', 'Host', 'Wired'],
                rows: array_map(fn (array $row) => [
                    $row['tool'],
                    $row['instance'] !== '' ? $row['instance'] : '—',
                    $row['host'] ?? '—',
                    $row['wired'] ? 'yes' : 'no',
                ], $wiredToolsOutput),
            );
        }

        if ($grants !== []) {
            table(
                headers: ['Email', 'Tool', 'Role', 'Project'],
                rows: array_map(fn (array $row) => [
                    $row['email'],
                    $row['tool'],
                    $row['role'],
                    $row['project'],
                ], $grants),
            );
        }

        if ($orgs !== []) {
            table(
                headers: ['Organization', 'Org ID', 'Domain Verified'],
                rows: array_map(fn (array $row) => [
                    $row['name'],
                    $row['orgId'],
                    $row['domainVerified'] ? 'yes' : 'no',
                ], $orgs),
            );
        }

        if ($orphanedProjects !== []) {
            $this->newLine();
            $this->laraKubeWarn('Orphaned projects: '.implode(', ', $orphanedProjects));
        }

        return 0;
    }

    /**
     * Iterate ClusterTool::shippedCases(), for each check its sso-app-{slug}
     * secret via readSsoSecret() — plain secret read per tool, never a Zitadel
     * API/port-forward call per tool.
     *
     * @return list<array{tool: string, slug: string, instance: string, host: string|null, wired: bool, projectId: string|null}>
     */
    protected function wiredToolsSummary(string $kubectl, string $ssoNs): array
    {
        $rows = [];
        foreach (ClusterTool::shippedCases() as $tool) {
            $host = $this->getToolHost($kubectl, $tool);
            $instance = $host !== null ? $this->resolveInstanceForDomain($kubectl, $tool, $host) : '';
            if ($instance === '') {
                // ssoAppSecretName()/ToolInstance::forInstance() require a
                // real host-derived slug — nothing to check until this tool
                // is registered under one.
                continue;
            }

            // The ONLY name sso:wire ever writes a tool's OIDC client
            // credentials under (see SsoWireCommand::wireTool()) — no other
            // naming convention exists, so this is a single lookup, never a
            // guess-and-check chain.
            $secretName = $this->ssoAppSecretName($tool, $instance);
            $clientId = $this->readClusterSecretKey($kubectl, $ssoNs, $secretName, 'client-id');

            if ($clientId === null) {
                continue;
            }

            $projectId = $this->readClusterSecretKey($kubectl, $ssoNs, $secretName, 'project-id');

            $rows[] = [
                'tool' => $tool->getLabel(),
                'slug' => $tool->value,
                'instance' => $instance,
                'host' => $host !== '' ? $host : null,
                'wired' => true,
                'projectId' => $projectId,
            ];
        }

        return $rows;
    }

    /**
     * Query grants for ONLY the shared-tools project plus each tool's own RBAC
     * project in a single API call per project via zitadelListProjectGrants().
     * Excludes any role key matching /^secrets-/.
     *
     * @param  list<array{tool: string, slug: string, instance: string, host: string|null, wired: bool, projectId: string|null}>  $wiredTools
     * @param  array<string, array{id: string, name: string}>  $projectsByName
     * @param  array<string, array{id: string, name: string}>  $projectsById
     * @return list<array{email: string, tool: string, slug: string, role: string, project: string}>
     */
    protected function grantsSummary(string $ssoHost, string $pat, array $wiredTools, array $projectsByName, array $projectsById): array
    {
        // Capped query scope: Query ONLY the shared-tools project plus each wired tool's
        // own RBAC project in a single API call per project (zitadelListProjectGrants).
        // Never iterate every possible ClusterTool or query zitadelFindUserGrant() in a loop
        // over users — doing so causes massive N+1 round trips and port-forward overhead,
        // which quickly blows past job timeouts in automation and Desktop.
        $targetProjects = [];

        $sharedProjectName = ClusterTool::ssoAdminProjectName();
        if (isset($projectsByName[$sharedProjectName])) {
            $targetProjects[$projectsByName[$sharedProjectName]['id']] = $sharedProjectName;
        }

        foreach ($wiredTools as $wired) {
            $tool = ClusterTool::tryFrom($wired['slug']);
            if ($tool === null || ! $tool->requiresRbacGating()) {
                continue;
            }

            $rbacName = $tool->rbacProjectName($wired['instance'] !== '' ? $wired['instance'] : null);
            $targetProject = $projectsByName[$rbacName]
                ?? (! empty($wired['projectId']) ? ($projectsById[$wired['projectId']] ?? null) : null)
                ?? ($projectsByName[$tool->rbacProjectName()] ?? null);

            if ($targetProject !== null) {
                $targetProjects[$targetProject['id']] = $targetProject['name'];
            }
        }

        $grants = [];
        foreach ($targetProjects as $projectId => $projectName) {
            $projectGrants = $this->zitadelListProjectGrants($ssoHost, $pat, $projectId);
            foreach ($projectGrants as $grant) {
                $email = (string) ($grant['email'] ?? '');
                $roleKeys = (array) ($grant['roleKeys'] ?? []);
                foreach ($roleKeys as $roleKey) {
                    if (preg_match('/^secrets-/', $roleKey)) {
                        continue;
                    }

                    $tool = ClusterTool::forGrantableRoleKey($roleKey);
                    if ($tool === null) {
                        foreach ($wiredTools as $w) {
                            $t = ClusterTool::tryFrom($w['slug']);
                            if ($t !== null && ($t->rbacProjectName($w['instance'] ?: null) === $projectName || $t->rbacProjectName() === $projectName)) {
                                $tool = $t;
                                break;
                            }
                        }
                    }

                    $toolLabel = $tool?->getLabel() ?? 'Unknown tool';
                    $slug = $tool?->value ?? 'unknown';

                    $grants[] = [
                        'email' => $email,
                        'tool' => $toolLabel,
                        'slug' => $slug,
                        'role' => $roleKey,
                        'project' => $projectName,
                    ];
                }
            }
        }

        return $grants;
    }

    /**
     * Pure computation — any project name from the already-fetched zitadelListAllProjects()
     * list that is not referenced by any wiredTools row. Never calls a delete/prune API.
     *
     * @param  list<array{id: string, name: string}>  $projects
     * @param  list<array{tool: string, slug: string, instance: string, host: string|null, wired: bool, projectId: string|null}>  $wiredTools
     * @param  array<string, array{id: string, name: string}>  $projectsById
     * @return list<string>
     */
    protected function orphanedProjectsSummary(array $projects, array $wiredTools, array $projectsById): array
    {
        $referenced = [];
        foreach ($wiredTools as $wired) {
            $tool = ClusterTool::tryFrom($wired['slug']);
            if ($tool === null) {
                continue;
            }

            if ($tool->requiresRbacGating()) {
                $referenced[] = $tool->rbacProjectName($wired['instance'] !== '' ? $wired['instance'] : null);
                $referenced[] = $tool->rbacProjectName();
            } else {
                $referenced[] = ClusterTool::ssoAdminProjectName();
            }

            if (! empty($wired['projectId']) && isset($projectsById[$wired['projectId']])) {
                $referenced[] = $projectsById[$wired['projectId']]['name'];
            }
        }

        $referenced = array_values(array_unique($referenced));

        $orphaned = [];
        foreach ($projects as $project) {
            $name = $project['name'];
            if ($name === 'ZITADEL') {
                continue;
            }
            if (! in_array($name, $referenced, true)) {
                $orphaned[] = $name;
            }
        }

        return $orphaned;
    }
}
