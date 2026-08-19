<?php

namespace App\Commands\Chat;

use App\Data\ConfigData;
use App\Traits\InteractsWithChat;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMatrixApi;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

/**
 * Create (or reuse) a room on the shared chat homeserver and invite the
 * given users — e.g. a shared room for a partner team once their accounts
 * exist (see chat:user). Idempotent by alias: re-running only invites
 * members not already in the room.
 *
 * Invite IDs must use the shared homeserver's OWN server_name (the same
 * host chat:init deployed, e.g. @alice:luchtech.dev) — Synapse has no
 * federation identity for a partner's own domain (e.g. partner.example); their
 * people are chat:user-created accounts on this same homeserver.
 */
class ChatRoomCommand extends Command
{
    use InteractsWithChat, InteractsWithClusterContext, InteractsWithMatrixApi, LaraKubeOutput, RequiresFlagsWhenNonInteractive;

    protected $signature = 'chat:room
        {environment=local : Environment whose chat server to target}
        {--context=  : Target a specific kube-context}
        {--name=     : Room display name}
        {--alias=    : Local room alias, e.g. partner-team (full alias becomes #partner-team:<server_name>)}
        {--topic=    : Room topic}
        {--invite=*  : Full Matrix user IDs to invite, e.g. --invite=@alice:luchtech.dev (must use the shared homeserver\'s own server_name — see chat:user)}';

    protected $description = 'Create or reuse a room on the shared chat homeserver and invite members into it';

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

        $aliasLocalPart = $this->resolveAlias();
        $alias = "#{$aliasLocalPart}:{$host}";
        $name = (string) ($this->option('name') ?: $aliasLocalPart);
        $topic = (string) $this->option('topic') ?: null;
        /** @var list<string> $invites */
        $invites = array_values(array_filter((array) $this->option('invite')));

        $roomId = $this->matrixFindRoomByAlias($host, $adminToken, $alias);
        $created = false;

        if ($roomId === null) {
            $this->withSpin("Creating room {$alias}...", function () use (&$roomId, $host, $adminToken, $name, $aliasLocalPart, $topic, $invites): void {
                $roomId = $this->matrixCreateRoom($host, $adminToken, $name, $aliasLocalPart, $topic, $invites);
            });
            $created = true;

            if ($roomId === null) {
                $this->laraKubeError('Failed to create the room. Check the Matrix Client-Server API connection.');

                return 1;
            }
        } elseif ($invites !== []) {
            $alreadyIn = $this->matrixRoomMembers($host, $adminToken, $roomId);
            $toInvite = array_values(array_diff($invites, $alreadyIn));

            foreach ($toInvite as $userId) {
                if (! $this->matrixInviteToRoom($host, $adminToken, $roomId, $userId)) {
                    $this->laraKubeError("Failed to invite {$userId}.");
                }
            }
        }

        $this->newLine();
        $this->laraKubeInfo($created ? "✅ Room created: {$alias}" : "✅ Room already exists: {$alias}");
        $this->newLine();
        $this->line("  <fg=gray>Alias:</>   <fg=blue>{$alias}</>");
        $this->line("  <fg=gray>Room ID:</> <fg=blue>{$roomId}</>");
        $this->line("  <fg=gray>Link:</>    <fg=blue>https://matrix.to/#/{$alias}</>");
        if ($invites !== []) {
            $this->line('  <fg=gray>Invited:</> <fg=blue>'.implode(', ', $invites).'</>');
        }
        $this->newLine();
        $this->line('  <fg=gray>Note:</> invitees must accept the invite in their own client — inviting them here');
        $this->line('  does not add them to the room automatically.');
        $this->newLine();

        return 0;
    }

    /** The room's local alias. Required — no default, since a room's alias is permanent once created. */
    protected function resolveAlias(): string
    {
        return $this->flagOrPrompt(
            'alias',
            fn () => text(
                label: 'Local room alias (becomes #alias:server_name)',
                placeholder: 'partner-team',
                required: true,
            ),
            'the room\'s local alias',
            'larakube chat:room production --alias=partner-team --invite=@alice:luchtech.dev',
        );
    }
}
