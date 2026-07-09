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
     */
    protected function resolveAccessRole(): string
    {
        if ($this->option('read') || $this->option('edit') || $this->option('admin')) {
            return $this->presetClusterRole((bool) $this->option('read'), (bool) $this->option('edit'), (bool) $this->option('admin'));
        }

        if ($this->flag('no-interaction')) {
            return 'edit';
        }

        return select(
            label: 'Access level',
            options: [
                'view' => 'Read-only — logs + status (no exec, no secrets)',
                'edit' => 'Operate the app — edit (default)',
                'admin' => 'Namespace-admin — edit + manage access within the namespace',
            ],
            default: 'edit',
        );
    }

    protected function applyManifest(string $adminContext, string $manifest, array &$output = []): bool
    {
        $file = tempnam(sys_get_temp_dir(), 'lk_grant_');
        file_put_contents($file, $manifest);
        $result = Process::run('kubectl --context '.escapeshellarg($adminContext).' apply -f '.escapeshellarg($file));
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

        $role = $this->resolveAccessRole();
        $accessNs = $this->accessNamespace();
        $ctx = escapeshellarg($adminContext);

        $this->laraKubeInfo("Granting '{$name}' [{$role}] on '{$appNs}'...");
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
        $nsExists = Process::run("kubectl --context {$ctx} get namespace ".escapeshellarg($appNs))->successful();
        if (! $nsExists) {
            $this->laraKubeInfo("Namespace '{$appNs}' doesn't exist yet — creating it.");
            Process::run("kubectl --context {$ctx} create namespace ".escapeshellarg($appNs));
        }

        // 2. RoleBinding in the app namespace. roleRef is immutable, so to support
        //    upgrade/downgrade we delete any existing binding for this user first,
        //    then recreate with the chosen role.
        Process::run("kubectl --context {$ctx} -n ".escapeshellarg($appNs).' delete rolebinding '.escapeshellarg($this->teammateBindingName($sa)).' --ignore-not-found');
        $bindOut = [];
        if (! $this->applyManifest($adminContext, $this->teammateBindingManifest($appNs, $accessNs, $sa, $role), $bindOut)) {
            $this->laraKubeError("Failed to bind access in '{$appNs}':\n  ".implode("\n  ", array_slice($bindOut, -3)));

            return 1;
        }

        // 3. Token + CA + server → a teammate kubeconfig.
        $token = $this->pollSecretToken($adminContext, $accessNs, $sa.'-token');
        $server = trim(Process::run($this->clusterServerCommand($adminContext))->output());
        $ca = $this->readSecretCaData($adminContext, $accessNs, $sa.'-token');

        if ($token === null || $token === '' || $server === '' || $ca === '') {
            $this->laraKubeError('Could not mint the teammate token (Secret never populated, or server/CA unreadable).');

            return 1;
        }

        $contextName = $this->teammateContextName($appNs);
        $kubeconfig = $this->assembleTeammateKubeconfig($contextName, $server, $ca, $appNs, $token, $sa);

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
            'namespace' => $appNs,
            'context' => $contextName,
            'identity' => $accessNs.'/'.$sa,
            'kubeconfigPath' => $file,
            'kubeconfig' => $kubeconfig,
        ];

        $this->laraKubeInfo("✅ Granted '{$name}' [{$role}] on '{$appNs}'.");
        $this->line("  <fg=gray>Identity:</> {$accessNs}/{$sa}  <fg=gray>· context they'll see:</> <fg=cyan>{$contextName}</>");
        $this->line('  <fg=gray>Kubeconfig:</> <fg=cyan>'.$file.'</> <fg=gray>(0600)</>');
        $this->laraKubeWarn('Deliver this file SECURELY — not committed, not pasted in chat.');
        $this->line('  They run: <fg=yellow>larakube context:import '.basename($file).'</>');
        $this->laraKubeNewLine();
        $this->line("  <fg=gray>To add another app later:</> <fg=yellow>larakube cluster:grant <other-ns> --name {$name}</> <fg=gray>(same identity — no new file).</>");

        return 0;
    }
}
