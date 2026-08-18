<?php

namespace App\Commands\Cluster;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class ClusterUsersCommand extends Command
{
    use InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

    protected $signature = 'cluster:users
        {environment? : An environment (in-project) — lists who has access. Omit to pick from the project\'s envs}
        {--scope : Audit the deploy SA\'s live RBAC rules instead of listing people}
        {--context= : Standalone: target a kube-context directly (when not in a project)}';

    protected $description = 'List who has access to a namespace (or every LaraKube identity cluster-wide); --scope audits the deploy SA rules';

    /** Deterministic name of the scoped deploy ServiceAccount/Role/RoleBinding. */
    private string $sa = 'deployer';

    public function handle(): int
    {
        $this->renderHeader();

        $config = $this->getProjectConfig(getcwd());
        $arg = (string) ($this->argument('environment') ?? '');

        // Inside a project, drive everything by ENVIRONMENT — no one memorizes
        // context or namespace names. Pick from the project's envs (or name one),
        // and target that env's OWN cluster context automatically.
        if ($config !== null && ($arg === '' || $config->getEnvironment($arg) !== null)) {
            $env = $arg !== '' ? $arg : $this->pickEnvironment($config);
            if ($env === null) {
                $this->laraKubeWarn('This project has no cloud environments yet — add one with `larakube env <name>`.');

                return 0;
            }

            $namespace = $config->getNamespace($env);
            $context = $this->environmentContextOrCurrent($config, $env);
            $kubectl = $this->contextKubectl($context);

            $this->line('  <fg=gray>Environment:</> <fg=cyan>'.$env.'</>  <fg=gray>·</> <fg=cyan>'.$namespace.'</>  <fg=gray>·</> <fg=cyan>'.($context ?? 'current context').'</>');
            $this->laraKubeNewLine();

            return $this->option('scope') ? $this->showScope($kubectl, $namespace) : $this->showAccess($kubectl, $namespace);
        }

        // Standalone (outside a project, or an explicit literal namespace) — pick
        // a context rather than silently defaulting to whatever kubectl points at.
        $context = $this->pickContext($this->option('context'));
        if ($context === null) {
            $this->laraKubeError('No kube-contexts found — is kubectl configured?');

            return 1;
        }
        $this->line('  <fg=gray>Context:</> <fg=cyan>'.$context.'</>');
        $this->laraKubeNewLine();
        $kubectl = $this->contextKubectl($context);

        if ($arg === '') {
            return $this->listUsers($kubectl);
        }

        return $this->option('scope') ? $this->showScope($kubectl, $arg) : $this->showAccess($kubectl, $arg);
    }

    /** List every identity (teammates + deploy SA) bound to one namespace. */
    protected function showAccess(string $kubectl, string $namespace): int
    {
        $bindings = $this->kubectlItems($kubectl.' get rolebinding -n '.escapeshellarg($namespace).' -l app.kubernetes.io/managed-by=larakube -o json 2>/dev/null');

        if (empty($bindings)) {
            $this->laraKubeInfo("No LaraKube access grants in '{$namespace}'.");
            $this->line('  <fg=gray>Grant a teammate:</> <fg=yellow>larakube cluster:grant '.$namespace.' --name <person></>');
            $this->line('  <fg=gray>Audit the deploy SA rules:</> <fg=yellow>larakube cluster:users '.$namespace.' --scope</>');

            return 0;
        }

        // One lookup of teammate SAs → friendly person names for the rows.
        $people = [];
        foreach ($this->kubectlItems($kubectl.' get sa -A -l larakube.dev/access-user -o json 2>/dev/null') as $sa) {
            $n = $sa['metadata']['name'] ?? '';
            $people[$n] = $sa['metadata']['annotations']['larakube.dev/person'] ?? $n;
        }

        $rows = [];
        foreach ($bindings as $rb) {
            $role = $rb['roleRef']['name'] ?? '?';
            $teammate = isset($rb['metadata']['labels']['larakube.dev/access-user']);
            foreach ($rb['subjects'] ?? [] as $s) {
                if (($s['kind'] ?? '') !== 'ServiceAccount') {
                    continue;
                }
                $saName = $s['name'] ?? '?';
                $who = $teammate ? ($people[$saName] ?? $saName) : $saName;
                $rows[] = [$who, $role, $teammate ? 'teammate' : 'deploy (CI)'];
            }
        }

        $this->laraKubeInfo("Access to '{$namespace}'");
        table(['Who', 'Role', 'Type'], $rows);
        $this->line('  <fg=gray>Grant:</> <fg=yellow>larakube cluster:grant '.$namespace.' --name <person></>  <fg=gray>· Revoke:</> <fg=yellow>larakube cluster:revoke '.$namespace.' --name <person></>');
        $this->line('  <fg=gray>Deploy SA rules:</> <fg=yellow>larakube cluster:users '.$namespace.' --scope</>');

        return 0;
    }

    /** List larakube-managed deploy SAs and teammate identities across the cluster. */
    protected function listUsers(string $kubectl): int
    {
        $items = $this->kubectlItems($kubectl.' get sa -A -l app.kubernetes.io/managed-by=larakube -o json 2>/dev/null');

        // Teammates carry an access-user label; everything else is a deploy SA.
        $deployers = [];
        $teammates = [];
        foreach ($items as $sa) {
            if (isset($sa['metadata']['labels']['larakube.dev/access-user'])) {
                $teammates[] = $sa;
            } else {
                $deployers[] = $sa;
            }
        }

        if (empty($deployers) && empty($teammates)) {
            $this->laraKubeInfo('No LaraKube ServiceAccounts on this cluster yet.');
            $this->line('  Deploy SAs come from `cloud:deploy` / `cloud:configure --only=ci`; teammates from `cluster:grant`.');

            return 0;
        }

        if (! empty($deployers)) {
            $rows = [];
            foreach ($deployers as $sa) {
                $ns = $sa['metadata']['namespace'] ?? '';
                $name = $sa['metadata']['name'] ?? '';
                $labels = $sa['metadata']['labels'] ?? [];
                $rows[] = [$ns, $name, $labels['larakube.dev/app'] ?? '—', $labels['larakube.dev/env'] ?? '—', $this->tokenStatus($kubectl, $ns, $name)];
            }
            $this->line('  <fg=green>Deploy credentials</>');
            table(['Namespace', 'ServiceAccount', 'App', 'Env', 'CI token'], $rows);
            $this->line('  <fg=gray>Who has access to one:</> <fg=yellow>larakube cluster:users '.$rows[0][0].'</>  <fg=gray>· its rules:</> <fg=yellow>… '.$rows[0][0].' --scope</>');
        }

        if (! empty($teammates)) {
            $rows = [];
            foreach ($teammates as $sa) {
                $name = $sa['metadata']['name'] ?? '';
                $person = $sa['metadata']['annotations']['larakube.dev/person'] ?? $name;
                $rows[] = [$person, $name, $this->teammateAccess($kubectl, $name)];
            }
            $this->laraKubeNewLine();
            $this->line('  <fg=green>Teammates</>');
            table(['Person', 'Identity', 'Access (namespace:role)'], $rows);
        }

        return 0;
    }

    /** A teammate's bindings as a "namespace:role" summary, read live — including any cluster-wide grant. */
    protected function teammateAccess(string $kubectl, string $sa): string
    {
        $parts = [];

        $clusterOut = trim(Process::run(
            "{$kubectl} get clusterrolebinding -l larakube.dev/access-user=".escapeshellarg($sa)
            .' -o jsonpath='.escapeshellarg('{range .items[*]}{.roleRef.name}{"  "}{end}'),
        )->output());
        foreach (array_filter(explode(' ', $clusterOut)) as $role) {
            $parts[] = "cluster:{$role}";
        }

        $nsOut = trim(Process::run(
            "{$kubectl} get rolebinding -A -l larakube.dev/access-user=".escapeshellarg($sa)
            .' -o jsonpath='.escapeshellarg('{range .items[*]}{.metadata.namespace}{":"}{.roleRef.name}{"  "}{end}'),
        )->output());
        if ($nsOut !== '') {
            $parts[] = $nsOut;
        }

        return $parts !== [] ? implode('  ', $parts) : '— (no apps)';
    }

    /** Show the LIVE Role rules + binding/token state for one namespace's deployer. */
    protected function showScope(string $kubectl, string $namespace): int
    {
        $role = json_decode(Process::run($kubectl.' get role '.escapeshellarg($this->sa).' -n '.escapeshellarg($namespace).' -o json')->output(), true);

        if (! is_array($role) || ($role['kind'] ?? '') !== 'Role') {
            $this->laraKubeError("No '{$this->sa}' Role in namespace '{$namespace}'.");
            $this->line('  Run `larakube cluster:users` (no arguments) to list namespaces that have one.');

            return 1;
        }

        $this->laraKubeInfo("RBAC scope — {$this->sa} @ {$namespace}");
        $this->line('  <fg=gray>(read live from the cluster, so any drift shows here)</>');
        $this->laraKubeNewLine();

        $rows = [];
        foreach ($role['rules'] ?? [] as $rule) {
            $rows[] = [
                implode(', ', array_map(fn ($g) => $g === '' ? '(core)' : $g, $rule['apiGroups'] ?? [])),
                implode(', ', $rule['resources'] ?? []),
                implode(',', $rule['verbs'] ?? []),
            ];
        }
        table(['API group', 'Resources', 'Verbs'], $rows);

        // Binding — an SA with no RoleBinding has no scope, so call it out.
        $rb = json_decode(Process::run($kubectl.' get rolebinding '.escapeshellarg($this->sa).' -n '.escapeshellarg($namespace).' -o json')->output(), true);
        $bound = is_array($rb)
            && ($rb['roleRef']['name'] ?? '') === $this->sa
            && collect($rb['subjects'] ?? [])->contains(fn ($s) => ($s['kind'] ?? '') === 'ServiceAccount' && ($s['name'] ?? '') === $this->sa);

        $this->line('  <fg=gray>Binding:</> '.($bound
            ? '<fg=green>✅ RoleBinding "'.$this->sa.'" → ServiceAccount "'.$this->sa.'"</>'
            : '<fg=red>⚠ not bound — the ServiceAccount has no scope!</>'));

        // CI token state (the long-lived bound-token Secret, if minted).
        $token = $this->tokenStatus($kubectl, $namespace, $this->sa);
        $minted = trim(Process::run($kubectl.' -n '.escapeshellarg($namespace).' get secret '.escapeshellarg($this->sa.'-token').' -o jsonpath='.escapeshellarg('{.metadata.creationTimestamp}'))->output());
        $this->line('  <fg=gray>CI token:</> '.$token.($minted !== '' ? '  <fg=gray>minted:</> '.$minted : ''));

        return 0;
    }

    /** Decode a kubectl `-o json` list into its items array. */
    protected function kubectlItems(string $command): array
    {
        $data = json_decode(Process::run($command)->output(), true);

        return is_array($data) ? ($data['items'] ?? []) : [];
    }

    /** Is a long-lived bound-token Secret present + populated for this SA? */
    protected function tokenStatus(string $kubectl, string $namespace, string $sa): string
    {
        $b64 = trim(Process::run($kubectl.' -n '.escapeshellarg($namespace).' get secret '.escapeshellarg($sa.'-token').' -o jsonpath='.escapeshellarg('{.data.token}'))->output());

        return $b64 !== '' ? '✅ active' : '—';
    }
}
