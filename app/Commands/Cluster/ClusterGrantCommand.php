<?php

namespace App\Commands\Cluster;

use App\State;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithScopedRbac;
use App\Traits\InteractsWithTeammateRbac;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\ResolvesEnvironmentContext;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class ClusterGrantCommand extends Command
{
    use EmitsJsonOutput, InteractsWithProjectConfig, InteractsWithScopedRbac, InteractsWithTeammateRbac, LaraKubeOutput, ReadsCommandOptions, ResolvesEnvironmentContext;

    protected $signature = 'cluster:grant
        {environment? : An environment (in-project) or a literal namespace (standalone) to grant access on}
        {--name= : The teammate (their identity — reused across apps)}
        {--read : Read-only (view): logs + status, no exec/secrets}
        {--edit : Operate the app (edit) — the DEFAULT}
        {--admin : Namespace-admin (edit + manage access within the namespace)}
        {--namespaces= : Comma-separated namespaces to bind (omit to pick them interactively)}
        {--cluster : Grant across EVERY namespace (ClusterRoleBinding) — last resort; prefer --namespaces}
        {--context= : Standalone: target a kube-context directly (when not in a project)}
        {--json : Emit one machine-readable JSON result (incl. the minted kubeconfig) on stdout}';

    protected $description = 'Grant a teammate scoped access to an environment (re-run to upgrade/downgrade their role or add another app)';

    /**
     * Fields for the --json result, filled in as the grant proceeds so the
     * wrapper in handle() can report them (crucially the minted kubeconfig
     * content, which a headless caller needs back — it can't read the file
     * from a disposable job container's cwd) without threading a value
     * through every return path.
     *
     * @var array<string, mixed>
     */
    private array $result = [];

    public function handle(): int
    {
        if ($this->flag('json') || $this->isAiAgent()) {
            $this->enableJsonMode();
        }

        $exit = $this->grant();

        if (State::$jsonMode) {
            $this->jsonOutput($exit === 0
                ? array_merge(['success' => true], $this->result, ['error' => null])
                : ['success' => false, 'error' => State::$lastError ?? 'Grant did not complete.']);
        }

        return $exit;
    }

    /**
     * Make sure the minted kubeconfig can't be committed. Appends `*.kubeconfig`
     * to the project's .gitignore (creating it for a git repo that lacks one).
     * Idempotent, and a no-op outside a git repo — so it never litters non-repos.
     */
    protected function ensureKubeconfigIgnored(string $dir): void
    {
        $pattern = '*.kubeconfig';
        $gitignore = $dir.'/.gitignore';

        if (! is_dir($dir.'/.git') && ! is_file($gitignore)) {
            return;
        }

        $existing = is_file($gitignore) ? (string) file_get_contents($gitignore) : '';
        if (in_array($pattern, array_map('trim', preg_split('/\R/', $existing) ?: []), true)) {
            return;
        }

        $prefix = ($existing !== '' && ! str_ends_with($existing, "\n")) ? "\n" : '';
        file_put_contents($gitignore, $prefix."\n# LaraKube teammate credentials — never commit these\n{$pattern}\n", FILE_APPEND);

        $this->line('  <fg=gray>Added</> <fg=cyan>'.$pattern.'</> <fg=gray>to .gitignore so the credential is never committed.</>');
    }

    /**
     * Resolve the access level. An explicit --read/--edit/--admin flag always
     * wins. Otherwise ask (rather than silently granting write access) — falling
     * back to the documented `edit` default when non-interactive (e.g. CI).
     *
     * $clusterWide changes what "admin" means: bound cluster-wide it resolves to
     * the built-in `cluster-admin` ClusterRole instead of `admin` — see
     * presetClusterRole()'s docblock for why `admin` alone doesn't actually reach
     * every namespace-scoped role.
     */
    protected function resolveAccessRole(bool $clusterWide): string
    {
        if ($this->option('read') || $this->option('edit') || $this->option('admin')) {
            return $this->presetClusterRole((bool) $this->option('read'), (bool) $this->option('edit'), (bool) $this->option('admin'), $clusterWide);
        }

        if ($this->flag('no-interaction')) {
            return 'edit';
        }

        return select(
            label: 'Access level',
            options: $clusterWide ? [
                'view' => 'Read-only — logs + status (no exec, no secrets)',
                'edit' => 'Operate every app — edit (default)',
                'cluster-admin' => 'Full cluster-admin — nodes, storage, RBAC, everything',
            ] : [
                'view' => 'Read-only — logs + status (no exec, no secrets)',
                'edit' => 'Operate the app — edit (default)',
                'admin' => 'Namespace-admin — edit + manage access within the namespace',
            ],
            default: 'edit',
        );
    }

    /**
     * Gate the cluster-wide grant behind an informed yes.
     *
     * `view` cluster-wide is broad but bounded. `edit` and `admin` are not: the
     * built-in roles carry Secret access and the ability to run a pod as ANY
     * ServiceAccount in the namespace — cluster-wide that includes kube-system,
     * so it is a documented escalation path to cluster-admin, not merely "edit
     * everywhere". On this cluster it also exposes the Commons Postgres
     * superuser, the secrets-backend bootstrap and Zitadel's machine PAT.
     *
     * That can be exactly right for a DevOps operator — it just should never be
     * something someone discovers after the fact.
     */
    protected function confirmClusterScope(string $name, string $role, string $adminContext): bool
    {
        $this->laraKubeWarn("Cluster-wide grant: '{$name}' gets [{$role}] in EVERY namespace on '{$adminContext}'.");

        if ($role === 'cluster-admin') {
            $this->line('  <fg=gray>This IS full cluster-admin — root on the cluster. Nodes, StorageClasses,</>');
            $this->line('  <fg=gray>CustomResourceDefinitions, every Secret, other people\'s RBAC — everything.</>');
            $this->line('  <fg=gray>Scope it to `admin` instead with:</> <fg=yellow>larakube cluster:grant <env> --admin --name '.$name.'</>');
        } elseif ($role !== 'view') {
            $this->line('  <fg=gray>That includes reading every Secret in every namespace — Commons Postgres</>');
            $this->line('  <fg=gray>superuser, secrets-backend bootstrap, Zitadel machine PAT — and running pods</>');
            $this->line('  <fg=gray>as any ServiceAccount, which is an escalation path to cluster-admin.</>');
            $this->line('  <fg=gray>Scope it per namespace instead with:</> <fg=yellow>larakube cluster:grant <env> --name '.$name.'</>');
        }

        if ($this->flag('no-interaction')) {
            // Explicit --cluster in a script is the operator's decision; don't
            // deadlock CI on a prompt, but the warning above is still emitted.
            return true;
        }

        return confirm(label: "Grant '{$name}' [{$role}] across the entire cluster?", default: false);
    }

    /**
     * Which namespaces this grant binds.
     *
     * --namespaces wins; otherwise offer a multiselect of what's actually on the
     * cluster, pre-checking the resolved target. This is the least-privilege path
     * and should be preferred over --cluster: a DevOps operator usually needs
     * several namespaces, not kube-system.
     *
     * @return list<string>
     */
    protected function resolveGrantNamespaces(string $adminContext, string $defaultNs): array
    {
        $raw = (string) ($this->option('namespaces') ?? '');
        if ($raw !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $raw))));
        }

        if ($this->flag('no-interaction')) {
            return [$defaultNs];
        }

        $listed = trim(Process::run(
            $this->contextKubectl($adminContext).' get namespace -o name',
        )->output());

        $available = array_values(array_filter(array_map(
            fn (string $l) => trim(str_replace('namespace/', '', $l)),
            preg_split('/\R/', $listed) ?: [],
        )));

        if ($available === []) {
            return [$defaultNs];
        }

        $chosen = multiselect(
            label: 'Which namespaces should they have this access on?',
            options: array_combine($available, $available),
            default: in_array($defaultNs, $available, true) ? [$defaultNs] : [],
            hint: 'Pick only what they need — each one is a separate RoleBinding you can revoke individually.',
            scroll: max(10, min(20, count($available))),
        );

        return array_values($chosen);
    }

    protected function applyManifest(string $adminContext, string $manifest, array &$output = []): bool
    {
        $file = tempnam(sys_get_temp_dir(), 'lk_grant_');
        file_put_contents($file, $manifest);
        $result = Process::run($this->contextKubectl($adminContext).' apply -f '.escapeshellarg($file));
        @unlink($file);

        $output = explode("\n", trim($result->output().$result->errorOutput()));

        return $result->successful();
    }

    private function grant(): int
    {
        $this->renderHeader();

        [$appNs, $adminContext] = $this->resolveClusterTarget((string) ($this->argument('environment') ?? ''), $this->option('context'));
        if ($appNs === null || $adminContext === null) {
            return 1;
        }

        $name = (string) $this->option('name');
        if ($name === '') {
            if ($this->flag('no-interaction')) {
                $this->laraKubeError('No teammate name — pass --name= when running non-interactively.');

                return 1;
            }
            $name = (string) text(label: 'Teammate name', placeholder: 'lloyd', required: true);
        }
        $sa = $this->teammateSaName($name);

        if ($sa === '') {
            $this->laraKubeError('Could not derive a valid identity from that name.');

            return 1;
        }

        if (! $this->kubectlSupportsTokens()) {
            $this->laraKubeError('kubectl >= 1.24 is required to mint a token. Please upgrade kubectl.');

            return 1;
        }

        $clusterWide = (bool) $this->option('cluster');
        $role = $this->resolveAccessRole($clusterWide);
        $accessNs = $this->accessNamespace();
        $ctx = $this->contextKubectl($adminContext);

        if ($clusterWide && ! $this->confirmClusterScope($name, $role, $adminContext)) {
            return 1;
        }

        $grantNamespaces = $clusterWide ? [] : $this->resolveGrantNamespaces($adminContext, $appNs);

        if (! $clusterWide && $grantNamespaces === []) {
            $this->laraKubeError('No namespaces selected — nothing to grant.');

            return 1;
        }

        $scopeLabel = $clusterWide
            ? 'the whole cluster'
            : (count($grantNamespaces) === 1 ? "'{$grantNamespaces[0]}'" : count($grantNamespaces).' namespaces');

        $this->laraKubeInfo("Granting '{$name}' [{$role}] on {$scopeLabel}...");
        $this->line("  <fg=gray>Cluster:</> <fg=cyan>{$adminContext}</>");

        // 1. Identity — namespace + SA + bound-token Secret (idempotent; an
        //    existing teammate keeps the same token).
        if (! $this->applyManifest($adminContext, $this->teammateIdentityManifest($accessNs, $sa, $name))) {
            $this->laraKubeError('Failed to create the teammate identity.');

            return 1;
        }

        // The RoleBinding lives IN the app namespace, so it must exist first
        // (admin creates it — same as cloud:deploy). A missing namespace is the
        // usual cause of a bind failure on a fresh cluster.
        $nsExists = Process::run("{$ctx} get namespace ".escapeshellarg($appNs))->successful();
        if (! $nsExists) {
            $this->laraKubeInfo("Namespace '{$appNs}' doesn't exist yet — creating it.");
            Process::run("{$ctx} create namespace ".escapeshellarg($appNs));
        }

        // 2. The binding. roleRef is immutable, so to support upgrade/downgrade we
        //    delete any existing binding for this user first, then recreate with
        //    the chosen role. Cluster-wide uses a ClusterRoleBinding under its own
        //    name, so a person can hold both scopes without either clobbering the
        //    other — and `cluster:revoke` can find each by its label.
        $bindOut = [];

        if ($clusterWide) {
            Process::run("{$ctx} delete clusterrolebinding ".escapeshellarg($this->teammateClusterBindingName($sa)).' --ignore-not-found');

            if (! $this->applyManifest($adminContext, $this->teammateClusterBindingManifest($accessNs, $sa, $role), $bindOut)) {
                $this->laraKubeError("Failed to bind access on {$scopeLabel}:\n  ".implode("\n  ", array_slice($bindOut, -3)));

                return 1;
            }
        } else {
            foreach ($grantNamespaces as $ns) {
                // Each namespace is its own RoleBinding under the same name, so
                // revoking one leaves the others intact.
                if (! Process::run("{$ctx} get namespace ".escapeshellarg($ns))->successful()) {
                    $this->laraKubeWarn("Namespace '{$ns}' doesn't exist — skipping.");

                    continue;
                }

                Process::run("{$ctx} -n ".escapeshellarg($ns).' delete rolebinding '.escapeshellarg($this->teammateBindingName($sa)).' --ignore-not-found');

                if (! $this->applyManifest($adminContext, $this->teammateBindingManifest($ns, $accessNs, $sa, $role), $bindOut)) {
                    $this->laraKubeError("Failed to bind access in '{$ns}':\n  ".implode("\n  ", array_slice($bindOut, -3)));

                    return 1;
                }

                $this->line("  <fg=gray>bound</> <fg=cyan>{$ns}</> <fg=gray>→ {$role}</>");
            }
        }

        // 3. Token + CA + server → a teammate kubeconfig.
        $token = $this->pollSecretToken($adminContext, $accessNs, $sa.'-token');
        $server = trim(Process::run($this->clusterServerCommand($adminContext))->output());
        $ca = $this->readSecretCaData($adminContext, $accessNs, $sa.'-token');

        if ($token === null || $token === '' || $server === '' || $ca === '') {
            $this->laraKubeError('Could not mint the teammate token (Secret never populated, or server/CA unreadable).');

            return 1;
        }

        // Cluster-wide: the context isn't pinned to one app, so name it for the
        // cluster and default to `default` — they can switch namespace freely,
        // which is the point of the grant.
        $contextName = $clusterWide ? $this->teammateContextName('') : $this->teammateContextName($grantNamespaces[0]);
        $defaultNs = $clusterWide ? 'default' : $grantNamespaces[0];
        $kubeconfig = $this->assembleTeammateKubeconfig($contextName, $server, $ca, $defaultNs, $token, $sa);

        $file = getcwd().'/'.$sa.'.kubeconfig';
        file_put_contents($file, $kubeconfig);
        @chmod($file, 0600);
        $this->ensureKubeconfigIgnored(getcwd());

        // A headless caller (LaraKube Cloud) can't read the file from a
        // disposable job container's cwd — hand back the kubeconfig content
        // itself, plus the identity metadata, as the deliverable.
        $this->registerSecret($token);
        $this->result = [
            'name' => $name,
            'role' => $role,
            'scope' => $clusterWide ? 'cluster' : 'namespace',
            'namespace' => $clusterWide ? null : $grantNamespaces[0],
            'namespaces' => $clusterWide ? null : $grantNamespaces,
            'context' => $contextName,
            'identity' => $accessNs.'/'.$sa,
            'kubeconfigPath' => $file,
            'kubeconfig' => $kubeconfig,
        ];

        $this->laraKubeInfo("✅ Granted '{$name}' [{$role}] on {$scopeLabel}.");
        $this->line("  <fg=gray>Identity:</> {$accessNs}/{$sa}  <fg=gray>· context they'll see:</> <fg=cyan>{$contextName}</>");
        $this->line('  <fg=gray>Kubeconfig:</> <fg=cyan>'.$file.'</> <fg=gray>(0600)</>');
        $this->laraKubeWarn('Deliver this file SECURELY — not committed, not pasted in chat.');
        $this->line('  They run: <fg=yellow>larakube context:import '.basename($file).'</>');
        $this->laraKubeNewLine();
        $this->line("  <fg=gray>To add another app later:</> <fg=yellow>larakube cluster:grant <other-ns> --name {$name}</> <fg=gray>(same identity — no new file).</>");

        return 0;
    }
}
