<?php

namespace App\Commands\Workspace;

use App\Enums\AppFramework;
use App\Enums\WorkspaceRuntime;
use App\Services\Kubectl;
use App\Services\Workspace\WorkspaceSpec;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesContainerRuntime;
use App\Traits\TargetsWorkspaceServer;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use InvalidArgumentException;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * Creates a development workspace on a server: a namespace with a code-server
 * editor pod, the repository cloned into a persistent volume, its own deploy key
 * and password, and a network policy that keeps it away from the cluster API.
 * Safe to run again: the password and key already in the cluster are kept.
 */
class WorkspaceCreateCommand extends Command
{
    use EmitsJsonOutput, InteractsWithGlobalConfig, LaraKubeOutput, ReadsCommandOptions, RequiresFlagsWhenNonInteractive, ResolvesContainerRuntime, TargetsWorkspaceServer;

    protected $signature = 'workspace:create
        {--stack= : The server to create it on (a server made with cloud:create)}
        {--context= : A kube-context instead of a server, for a local cluster}
        {--name= : Workspace name: lowercase letters, digits and dashes}
        {--repo= : The Git repository to clone (https or ssh URL)}
        {--branch= : The branch to work on. It is created when the repository does not have it}
        {--framework= : The framework of the app (laravel, nextjs, django...); decides the runtime and the dev command. Default laravel}
        {--runtime= : The toolchain, when it differs from the framework\'s own: php, node, python, java, dotnet, go, rust}
        {--runtime-version= : The runtime version. Default is the runtime\'s own}
        {--size= : small or standard}
        {--git-name= : Name for commits made in the workspace}
        {--git-email= : Email for commits made in the workspace}
        {--image= : Run this image instead of the published one (it must follow the larakube-workspace image contract)}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Create a development workspace (browser editor + your repository) on a server';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $this->renderHeader();

        try {
            $name = $this->requireWorkspaceName();
            $repo = $this->flagOrPrompt('repo', fn (): string => text('Repository URL', hint: 'https://github.com/you/app'), 'the repository to clone', 'https://github.com/acme/app');
            $branch = (string) ($this->flag('branch') ?: 'main');
            $size = (string) ($this->flag('size') ?: WorkspaceSpec::defaultSize());
            $framework = AppFramework::tryFrom((string) ($this->flag('framework') ?: 'laravel'));
            $runtime = WorkspaceRuntime::tryFrom((string) ($this->flag('runtime') ?: $framework?->workspaceRuntime()->value));
            $runtimeVersion = (string) ($this->flag('runtime-version') ?: $runtime?->defaultVersion());
            $server = $this->workspaceServer();
        } catch (InvalidArgumentException $e) {
            return $this->failWith($e->getMessage());
        }

        if ($server === null) {
            return $this->failWith('No server to create the workspace on.');
        }

        if (! WorkspaceSpec::validRepo($repo)) {
            return $this->failWith("'{$repo}' is not a repository URL. Use https://host/owner/repo or git@host:owner/repo.");
        }

        if (! WorkspaceSpec::validBranch($branch)) {
            return $this->failWith("'{$branch}' is not a usable branch name.");
        }

        if ($framework === null) {
            return $this->failWith("Unknown framework '{$this->flag('framework')}'.");
        }

        if ($runtime === null) {
            return $this->failWith("Unknown runtime '{$this->flag('runtime')}'. Choose one of: ".implode(', ', array_column(WorkspaceRuntime::cases(), 'value')).'.');
        }

        $image = $this->flag('image') ? (string) $this->flag('image') : null;

        if ($image !== null && preg_match('#^[a-z0-9][a-z0-9._/:@-]{2,200}$#', $image) !== 1) {
            return $this->failWith("'{$image}' is not an image reference.");
        }

        if ($image === null && ! $runtime->published()) {
            return $this->failWith("There is no published {$runtime->label()} workspace image yet. Use --image to run your own.");
        }

        if (! WorkspaceSpec::validVersion($runtime, $runtimeVersion)) {
            return $this->failWith("{$runtime->label()} {$runtimeVersion} is not offered. Choose one of: ".implode(', ', $runtime->versions()).'.');
        }

        if (! isset(WorkspaceSpec::sizes()[$size])) {
            return $this->failWith("Unknown size '{$size}'. Choose one of: ".implode(', ', array_keys(WorkspaceSpec::sizes())).'.');
        }

        $kubectl = $this->workspaceKubectl($server['context']);

        if (! $kubectl->raw(['get', '--raw=/readyz', '--request-timeout=8s'])->ok) {
            return $this->failWith("The server's Kubernetes is not answering ({$server['context']}).");
        }

        $namespace = WorkspaceSpec::namespaceFor($name);
        $spec = new WorkspaceSpec;
        $pullPolicy = null;

        // A local cluster that shares the computer's Docker (OrbStack, Docker Desktop...) pulls through the
        // daemon with whatever registry logins this computer has, which can reject even a public image.
        // Pulling it here, where that works, and starting from the local copy avoids the cluster's own pull.
        if ($image === null && $this->sharesHostDocker($server['context'])) {
            $ref = WorkspaceSpec::image($runtime, $runtimeVersion);
            $this->laraKubeInfo("Pulling {$ref} on this computer...");

            if (Process::forever()->run($this->containerRuntime().' pull '.escapeshellarg($ref))->successful()) {
                $pullPolicy = 'IfNotPresent';
            } else {
                $this->laraKubeWarn('Could not pull it here; the cluster will try on its own.');
            }
        }

        $this->laraKubeInfo("Creating workspace '{$name}'...");

        $applied = $kubectl->apply($spec->manifest([
            'name' => $name,
            'repo' => $repo,
            'branch' => $branch,
            'size' => $size,
            'framework' => $framework,
            'runtime' => $runtime,
            'runtimeVersion' => $runtimeVersion,
            ...($image !== null ? ['image' => $image] : []),
            ...($pullPolicy !== null ? ['pullPolicy' => $pullPolicy] : []),
            'gitName' => (string) ($this->flag('git-name') ?: $this->gitConfig('user.name') ?: 'LaraKube Workspace'),
            'gitEmail' => (string) ($this->flag('git-email') ?: $this->gitConfig('user.email') ?: 'workspace@localhost'),
        ]));

        if (! $applied->ok) {
            return $this->failWith('Could not apply the workspace: '.trim($applied->error));
        }

        $keys = $this->workspaceKeys($kubectl, $namespace, $name);

        if ($keys === null) {
            return $this->failWith('Could not create a deploy key for the workspace (is ssh-keygen installed?).');
        }

        $secret = $kubectl->putSecret($namespace, WorkspaceSpec::SECRET, [
            'password' => $kubectl->secretValue($namespace, WorkspaceSpec::SECRET, 'password') ?? Str::random(24),
            'deploy-key' => $keys['private'],
            'deploy-key.pub' => $keys['public'],
        ]);

        if (! $secret->ok) {
            return $this->failWith('Could not store the workspace credentials: '.trim($secret->error));
        }

        // The pod may have started before its Secret existed; this makes it pick the Secret up.
        $kubectl->rolloutRestart($namespace, 'workspace');
        $ready = $kubectl->rolloutStatus($namespace, 'workspace', 180)->ok;

        $this->laraKubeInfo($ready ? "✅ Workspace '{$name}' is running." : "Workspace '{$name}' was created but is not ready yet.");
        $this->line("  Add this deploy key (with write access) to {$repo} so the workspace can clone and push:");
        $this->line('  '.trim($keys['public']));

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'name' => $name, 'namespace' => $namespace, 'ready' => $ready, 'publicKey' => trim($keys['public'])]);
        }

        return 0;
    }

    private function failWith(string $message): int
    {
        $this->laraKubeError($message);

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => false, 'error' => $message]);
        }

        return 1;
    }

    private function gitConfig(string $key): ?string
    {
        $value = trim(Process::run(['git', 'config', '--global', $key])->output());

        return $value !== '' ? $value : null;
    }

    /**
     * The deploy key already in the cluster, or a new one. Kept across runs so the key
     * the owner added to the repository keeps working.
     *
     * @return array{private: string, public: string}|null
     */
    private function workspaceKeys(Kubectl $kubectl, string $namespace, string $name): ?array
    {
        $private = $kubectl->secretValue($namespace, WorkspaceSpec::SECRET, 'deploy-key');
        $public = $kubectl->secretValue($namespace, WorkspaceSpec::SECRET, 'deploy-key.pub');

        if ($private !== null && $public !== null && $private !== '') {
            return ['private' => $private, 'public' => $public];
        }

        $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
        $file = $dir->path().'/key';

        if (! Process::run(['ssh-keygen', '-t', 'ed25519', '-N', '', '-C', "larakube-workspace-{$name}", '-f', $file])->successful()) {
            return null;
        }

        return ['private' => (string) file_get_contents($file), 'public' => (string) file_get_contents($file.'.pub')];
    }

    /** Local clusters whose container runtime is the host's own Docker; native k3s has its own containerd. */
    private function sharesHostDocker(string $context): bool
    {
        return Kubectl::isLocalContextName($context) && $context !== 'k3s-larakube';
    }
}
