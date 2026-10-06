<?php

namespace App\Commands\Cloud;

use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithRemoteSsh;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\RequiresFlagsWhenNonInteractive;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class DevboxRevokeCommand extends Command
{
    use EmitsJsonOutput, InteractsWithGlobalConfig, InteractsWithRemoteSsh, LaraKubeOutput, ReadsCommandOptions, RequiresFlagsWhenNonInteractive;

    protected $signature = 'devbox:revoke
        {box? : Name of the dev box}
        {--github= : GitHub username to revoke}
        {--pubkey= : SSH public key string or identifier to remove}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Revoke a collaborator\'s SSH access to a dev box';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $this->renderHeader();

        $config = $this->getGlobalConfig();
        $devBoxes = array_filter($config->getStacks(), fn ($s): bool => $s->role === 'dev');

        if (empty($devBoxes)) {
            return $this->failWithError('No dev boxes registered on this machine.');
        }

        $boxName = (string) ($this->argument('box') ?: $this->chooseDevBox($devBoxes));
        $stack = $config->findStack($boxName);

        if ($stack === null || $stack->role !== 'dev' || ! $stack->ip || ! $stack->sshKey) {
            return $this->failWithError("Dev box '{$boxName}' not found or missing credentials.");
        }

        $search = $this->resolveSearchPattern();
        if ($search === null || $search === '') {
            return $this->failWithError('No collaborator or key specified to revoke.');
        }

        $this->laraKubeInfo("Revoking access on {$stack->name} matching '{$search}'...");

        $escaped = escapeshellarg($search);
        $script = "grep -v {$escaped} ~/.ssh/authorized_keys > ~/.ssh/authorized_keys.tmp && mv ~/.ssh/authorized_keys.tmp ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys";

        $ok = $this->runRemoteUserCommand('larakube', $stack->ip, 22, $stack->sshKey, $script);

        if (! $ok) {
            return $this->failWithError("Failed to update authorized_keys on {$stack->name}.");
        }

        if ($this->flag('json')) {
            $this->jsonOutput([
                'success' => true,
                'box' => $stack->name,
                'revoked' => $search,
            ]);

            return 0;
        }

        $this->laraKubeInfo("✅ Revoked SSH access matching '{$search}' on {$stack->name}.");

        return 0;
    }

    private function resolveSearchPattern(): ?string
    {
        $github = (string) $this->flag('github');
        if ($github !== '') {
            return "github:{$github}";
        }

        $pubkey = (string) $this->flag('pubkey');
        if ($pubkey !== '') {
            $parts = explode(' ', trim($pubkey));

            return $parts[1] ?? $parts[0];
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        $method = select(
            label: 'How would you like to identify the collaborator to revoke?',
            options: [
                'github' => 'GitHub Username',
                'custom' => 'Key snippet or identifier',
            ],
        );

        if ($method === 'github') {
            $username = text('Enter GitHub username to revoke:', required: true);

            return "github:{$username}";
        }

        return text('Enter key snippet or collaborator comment to remove:', required: true);
    }

    /**
     * @param  array<string, mixed>  $boxes
     */
    private function chooseDevBox(array $boxes): string
    {
        if (! $this->input->isInteractive()) {
            return '';
        }

        $options = [];
        foreach ($boxes as $s) {
            $options[$s->name] = "{$s->name} ({$s->ip})";
        }

        return select('Which dev box do you want to revoke access from?', options: $options);
    }

    private function failWithError(string $message): int
    {
        $this->laraKubeError($message);

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => false, 'error' => $message]);
        }

        return 1;
    }
}
