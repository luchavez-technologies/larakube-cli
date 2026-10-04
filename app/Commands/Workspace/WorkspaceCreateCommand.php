<?php

namespace App\Commands\Workspace;

use App\Data\StackData;
use App\Services\Kubectl;
use App\Services\Workspace\WorkspaceSpec;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithRemoteDeploy;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\RequiresFlagsWhenNonInteractive;
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
    use EmitsJsonOutput, InteractsWithGlobalConfig, InteractsWithRemoteDeploy, LaraKubeOutput, ReadsCommandOptions, RequiresFlagsWhenNonInteractive, TargetsWorkspaceServer;

    protected $signature = 'workspace:create
        {--stack= : The server to create it on (a server made with cloud:create)}
        {--context= : A kube-context instead of a server, for a local cluster}
        {--name= : Workspace name: lowercase letters, digits and dashes}
        {--repo= : The Git repository to clone (https or ssh URL)}
        {--branch= : The branch to work on. It is created when the repository does not have it}
        {--size= : small or standard}
        {--git-name= : Name for commits made in the workspace}
        {--git-email= : Email for commits made in the workspace}
        {--rebuild : Build and ship the workspace image again}
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

        if (! isset(WorkspaceSpec::sizes()[$size])) {
            return $this->failWith("Unknown size '{$size}'. Choose one of: ".implode(', ', array_keys(WorkspaceSpec::sizes())).'.');
        }

        $kubectl = $this->workspaceKubectl($server['context']);

        if (! $kubectl->raw(['get', '--raw=/readyz', '--request-timeout=8s'])->ok) {
            return $this->failWith("The server's Kubernetes is not answering ({$server['context']}).");
        }

        if (! $this->ensureWorkspaceImage($server['stack'], $server['context'], (bool) $this->flag('rebuild'))) {
            return $this->failWith('The workspace image could not be built or shipped to the server.');
        }

        $namespace = WorkspaceSpec::namespaceFor($name);
        $spec = new WorkspaceSpec;

        $this->laraKubeInfo("Creating workspace '{$name}'...");

        $applied = $kubectl->apply($spec->manifest([
            'name' => $name,
            'repo' => $repo,
            'branch' => $branch,
            'size' => $size,
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

    /**
     * Make sure the node can run the workspace image: build it here and, for a
     * server over SSH, stream it into the node's k3s. A local cluster shares the
     * host's images, so building is enough.
     */
    private function ensureWorkspaceImage(?StackData $stack, string $context, bool $rebuild): bool
    {
        $image = WorkspaceSpec::image();
        $ssh = $stack !== null && $stack->ip !== null && $stack->sshKey !== null && is_file($stack->sshKey)
            ? $this->sshBaseCommand('larakube', $stack->ip, 22, $stack->sshKey)
            : null;

        $present = $ssh !== null
            ? str_contains(Process::run($ssh.' '.escapeshellarg('sudo k3s ctr images ls -q'))->output(), $image)
            : trim(Process::run($this->imageQuietLookupCommand($image))->output()) !== '';

        if ($present && ! $rebuild) {
            return true;
        }

        $platform = $ssh !== null ? ($this->detectNodePlatformOverSsh($ssh) ?? 'linux/amd64') : '';
        $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
        $dockerfile = $dir->path().'/Dockerfile';
        file_put_contents($dockerfile, (new WorkspaceSpec)->dockerfile());

        $this->laraKubeInfo('Building the workspace image (a few minutes the first time)...');

        if ($this->runStreaming($this->buildImageCommand($image, $dockerfile, $dir->path(), $platform)) !== 0) {
            return false;
        }

        if ($ssh === null) {
            return true;
        }

        $this->laraKubeInfo('Sending the image to the server...');

        return $this->runStreaming($this->sideloadOverSshCommand($image, $ssh)) === 0;
    }
}
