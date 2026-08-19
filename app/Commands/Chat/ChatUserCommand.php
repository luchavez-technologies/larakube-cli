<?php

namespace App\Commands\Chat;

use App\Data\ConfigData;
use App\Traits\InteractsWithChat;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMatrixApi;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

/**
 * Create or update a Matrix account on the existing shared homeserver —
 * e.g. for a partner team's people (see chat:room to then invite them into
 * a shared room). There is no per-domain Matrix identity here: Synapse has
 * one server_name per process, so every account's user id is scoped to the
 * shared homeserver's own domain, regardless of which organization the
 * person belongs to.
 */
class ChatUserCommand extends Command
{
    use InteractsWithChat, InteractsWithClusterContext, InteractsWithMatrixApi, LaraKubeOutput;

    protected $signature = 'chat:user
        {environment=local : Environment whose chat server to target}
        {--context=      : Target a specific kube-context}
        {--username=      : Local part of the Matrix ID, e.g. alice (full id becomes @alice:<server_name>)}
        {--password=      : Account password (auto-generated if omitted)}
        {--display-name=  : Display name for the account}';

    protected $description = 'Create or update a Matrix account on the shared chat homeserver';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = (string) $this->option('context') ?: null;
        if (! $context && $config && $env !== 'local') {
            $context = $this->environmentContextOrCurrent($config, $env);
        }

        $kubectl = $this->chatKubectl($context);
        $ns = $this->chatNamespace();

        if (! $this->isChatInstalled($kubectl, $ns)) {
            $this->laraKubeError('Chat is not installed. Run `larakube chat:init` first.');

            return 1;
        }

        $host = $this->resolveChatHostReadOnly($env, $config);
        if ($host === null) {
            $this->laraKubeError("No host is configured for Chat in '{$env}'.");

            return 1;
        }

        $adminToken = $this->matrixAdminToken($kubectl, $ns, $host);
        if ($adminToken === null) {
            $this->laraKubeError('Could not reach Matrix\'s automation credentials — check chat-secrets/registration-secret exists (re-run `larakube chat:init` if needed).');

            return 1;
        }

        $username = (string) ($this->option('username') ?: text(
            label: 'Local part of the Matrix ID',
            placeholder: 'alice',
            required: true,
        ));

        $displayName = (string) ($this->option('display-name') ?: text(
            label: 'Display name',
            placeholder: $username,
        ));

        $password = (string) ($this->option('password') ?: Str::password(24));
        $userId = "@{$username}:{$host}";

        $result = null;
        $this->withSpin("Creating/updating {$userId}...", function () use (&$result, $host, $adminToken, $userId, $password, $displayName): void {
            $result = $this->matrixSetUserAccount($host, $adminToken, $userId, $password, $displayName !== '' ? $displayName : null);
        });

        if ($result === null) {
            $this->laraKubeError('Failed to create/update the account. Check the Synapse Admin API connection.');

            return 1;
        }

        $this->newLine();
        $this->laraKubeInfo($result['created'] ? "✅ Account created: {$userId}" : "✅ Account updated: {$userId}");
        $this->newLine();
        $this->line("  <fg=gray>User ID:</>  <fg=blue>{$userId}</>");
        $this->line("  <fg=gray>Password:</> <fg=yellow>{$password}</>");
        $this->line("  <fg=gray>Homeserver:</> <fg=blue>https://{$host}</>");
        $this->newLine();
        $this->line('  <fg=gray>Invite into a room:</>');
        $this->line("  <fg=blue>larakube chat:room {$env} --invite={$userId}</>");
        $this->newLine();

        return 0;
    }
}
