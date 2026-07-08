<?php

namespace App\Commands\Cluster;

use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithTeammateRbac;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class ClusterRevokeCommand extends Command
{
    use InteractsWithGlobalConfig, InteractsWithProjectConfig, InteractsWithTeammateRbac, LaraKubeOutput, ResolvesEnvironmentContext;

    protected $signature = 'cluster:revoke
        {environment? : An environment (in-project) or namespace — a deploy credential, or one app to drop a teammate from}
        {--name= : Revoke a TEAMMATE (omit the environment to off-board them entirely)}
        {--context= : Standalone: target a kube-context directly (when not in a project)}
        {--with-secret : Also delete the GitHub <ENV>_KUBECONFIG secret (deploy revoke only; best-effort)}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Revoke a deploy credential or a teammate\'s access — the kill-switch for offboarding or a leak';

    private string $sa = 'deployer';

    public function handle(): int
    {
        $this->renderHeader();

        $arg = (string) ($this->argument('environment') ?? '');

        // Teammate path (--name) vs deploy-credential path (the default).
        if ($this->option('name')) {
            return $this->revokeTeammate((string) $this->option('name'), $arg);
        }

        // Deploy-credential path — env-first (or a literal namespace standalone).
        [$namespace, $context] = $this->resolveClusterTarget($arg, $this->option('context'));
        if ($namespace === null || $context === null) {
            return 1;
        }
        $kubectl = $this->contextKubectl($context);
        $ns = escapeshellarg($namespace);
        $this->line('  <fg=gray>Cluster:</> <fg=cyan>'.$context.'</>');
        $this->laraKubeNewLine();

        $this->laraKubeWarn("This removes deploy access to '{$namespace}' — the '{$this->sa}' ServiceAccount, Role, RoleBinding, and token.");
        $this->line('  <fg=gray>Running workloads are untouched — use `cloud:nuke` to remove the app itself.</>');
        $this->laraKubeNewLine();

        if (! $this->option('force') && ! confirm("Revoke the deploy credential for '{$namespace}'?", false)) {
            $this->laraKubeInfo('Cancelled.');

            return 0;
        }

        // Delete each piece by its deterministic name (idempotent).
        foreach (["rolebinding/{$this->sa}", "role/{$this->sa}", "serviceaccount/{$this->sa}", "secret/{$this->sa}-token"] as $resource) {
            Process::run("{$kubectl} -n {$ns} delete {$resource} --ignore-not-found");
        }

        $this->laraKubeInfo("✅ Revoked the '{$this->sa}' deploy credential in '{$namespace}'. Any kubeconfig using its token is now dead.");

        if ($this->option('with-secret')) {
            $this->deleteGithubSecret($namespace);
        } else {
            $this->line('  <fg=gray>The GitHub {ENV}_KUBECONFIG secret (if any) now points at a dead token — harmless. Re-grant with `cloud:configure --only=ci`, or pass --with-secret to delete it.</>');
        }

        return 0;
    }

    /**
     * Revoke a teammate: from ONE app (delete that RoleBinding) when a namespace is
     * given, or off-board entirely (every RoleBinding cluster-wide + the SA + token)
     * when it isn't. Their kubeconfig is unchanged but its token stops working.
     */
    protected function revokeTeammate(string $name, string $arg): int
    {
        $sa = $this->teammateSaName($name);
        if ($sa === '') {
            $this->laraKubeError('Could not derive an identity from that name.');

            return 1;
        }

        // An env/namespace named → remove from just that one (env-first resolution).
        if ($arg !== '') {
            [$namespace, $context] = $this->resolveClusterTarget($arg, $this->option('context'));
            if ($namespace === null || $context === null) {
                return 1;
            }
            $kubectl = $this->contextKubectl($context);

            $this->laraKubeWarn("This removes '{$name}'s access to '{$namespace}' on '{$context}' (their other apps, if any, keep working).");
            if (! $this->option('force') && ! confirm("Revoke {$name}'s access to '{$namespace}'?", false)) {
                $this->laraKubeInfo('Cancelled.');

                return 0;
            }
            Process::run("{$kubectl} -n ".escapeshellarg($namespace).' delete rolebinding '.escapeshellarg($this->teammateBindingName($sa)).' --ignore-not-found');
            $this->laraKubeInfo("✅ Removed {$name}'s access to '{$namespace}'.");

            return 0;
        }

        // No env named → full off-board. Pick the cluster to off-board from.
        $context = $this->resolveClusterContext($this->option('context'));
        if ($context === null) {
            $this->laraKubeError('No kube-context to off-board from — pass --context or configure kubectl.');

            return 1;
        }
        $kubectl = $this->contextKubectl($context);

        // Full off-board — every binding (by label, across namespaces) + identity on this cluster.
        $this->laraKubeWarn("This OFF-BOARDS '{$name}' on '{$context}' — all their RoleBindings, the ServiceAccount, and token. Their kubeconfig for this cluster becomes inert.");
        if (! $this->option('force') && ! confirm("Off-board '{$name}' completely?", false)) {
            $this->laraKubeInfo('Cancelled.');

            return 0;
        }

        $list = Process::run("{$kubectl} get rolebinding -A -l larakube.dev/access-user=".escapeshellarg($sa).' -o jsonpath='.escapeshellarg('{range .items[*]}{.metadata.namespace}{" "}{.metadata.name}{"\n"}{end}'))->output();
        foreach (array_filter(array_map('trim', explode("\n", $list))) as $line) {
            [$bns, $bname] = array_pad(explode(' ', $line, 2), 2, '');
            if ($bns !== '' && $bname !== '') {
                Process::run("{$kubectl} -n ".escapeshellarg($bns).' delete rolebinding '.escapeshellarg($bname).' --ignore-not-found');
            }
        }

        $accessNs = escapeshellarg($this->accessNamespace());
        Process::run("{$kubectl} -n {$accessNs} delete serviceaccount ".escapeshellarg($sa).' secret '.escapeshellarg($sa.'-token').' --ignore-not-found');

        $this->laraKubeInfo("✅ Off-boarded '{$name}'.");

        return 0;
    }

    /**
     * Best-effort deletion of the env's GitHub kubeconfig secret. Derives the env
     * from the namespace's last segment ({app}-{env}; env names don't contain
     * hyphens) and the repo from the local git remote, so it must run inside the
     * project. A failure is non-fatal — the secret only points at a dead token.
     */
    protected function deleteGithubSecret(string $namespace): void
    {
        $env = str_contains($namespace, '-') ? substr((string) strrchr($namespace, '-'), 1) : $namespace;
        $secret = strtoupper($env).'_KUBECONFIG';

        $repoFlag = '';
        $repo = trim(Process::run('git remote get-url origin')->output());
        if ($repo !== '' && preg_match('/(?:github\.com|github)[:\/](.*?)(?:\.git)?$/', $repo, $m)) {
            $repoFlag = '-R '.escapeshellarg($m[1]);
        }

        $gh = $this->getGhCommand();
        $success = Process::run("{$gh} secret delete ".escapeshellarg($secret)." {$repoFlag}")->successful();

        if ($success) {
            $this->laraKubeInfo("✅ Deleted GitHub secret {$secret}.");
        } else {
            $this->laraKubeWarn("Could not delete GitHub secret {$secret} — run inside the repo with `gh` authenticated. (It only points at a now-dead token, so it's harmless.)");
        }
    }
}
