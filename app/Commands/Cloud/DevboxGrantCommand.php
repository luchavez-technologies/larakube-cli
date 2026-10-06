<?php

namespace App\Commands\Cloud;

use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithRemoteSsh;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\RequiresFlagsWhenNonInteractive;
use Illuminate\Support\Facades\Http;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class DevboxGrantCommand extends Command
{
    use EmitsJsonOutput, InteractsWithGlobalConfig, InteractsWithRemoteSsh, LaraKubeOutput, ReadsCommandOptions, RequiresFlagsWhenNonInteractive;

    protected $signature = 'devbox:grant
        {box? : Name of the dev box}
        {--github= : GitHub username to fetch public keys from}
        {--pubkey= : Raw SSH public key string or path to a public key file}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Grant a collaborator SSH access to a dev box using their public key';

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

        $resolved = $this->resolvePublicKeys();
        if ($resolved === null) {
            return $this->failWithError('No public key provided or found.');
        }

        [$identifier, $keys] = $resolved;

        if (empty($keys)) {
            return $this->failWithError("No valid SSH public keys found for {$identifier}.");
        }

        $this->laraKubeInfo("Granting access on {$stack->name} ({$stack->ip}) to {$identifier}...");

        $escapedLines = [];
        foreach ($keys as $key) {
            $tagged = trim($key).' # larakube:collaborator:'.$identifier;
            $escapedLines[] = escapeshellarg($tagged);
        }

        $script = 'mkdir -p ~/.ssh && chmod 700 ~/.ssh && touch ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys';
        foreach ($escapedLines as $line) {
            // Idempotent: don't duplicate key
            $cleanKey = explode(' ', trim($line, "'"))[1] ?? '';
            if ($cleanKey !== '') {
                $script .= ' && (grep -q '.escapeshellarg($cleanKey).' ~/.ssh/authorized_keys || printf "%s\n" '.$line.' >> ~/.ssh/authorized_keys)';
            }
        }

        $ok = $this->runRemoteUserCommand('larakube', $stack->ip, 22, $stack->sshKey, $script);

        if (! $ok) {
            return $this->failWithError("Failed to update authorized_keys on {$stack->name}. Check server connectivity.");
        }

        if ($this->flag('json')) {
            $this->jsonOutput([
                'success' => true,
                'box' => $stack->name,
                'collaborator' => $identifier,
                'keysAdded' => count($keys),
            ]);

            return 0;
        }

        $this->laraKubeInfo("✅ Granted SSH access to {$identifier} on {$stack->name}.");
        $this->line('  Added keys:  <fg=cyan>'.count($keys).' key(s)</>');
        $this->line('  Connect via: <fg=yellow>ssh larakube@'.$stack->ip.'</>');

        return 0;
    }

    /**
     * @return array{0: string, 1: list<string>}|null
     */
    private function resolvePublicKeys(): ?array
    {
        $github = (string) $this->flag('github');
        if ($github !== '') {
            $response = Http::get("https://github.com/{$github}.keys");
            if (! $response->successful()) {
                return null;
            }
            $keys = array_values(array_filter(array_map('trim', explode("\n", $response->body())), fn ($k): bool => str_starts_with($k, 'ssh-') || str_starts_with($k, 'ecdsa-')));

            return ["github:{$github}", $keys];
        }

        $pubkey = (string) $this->flag('pubkey');
        if ($pubkey !== '') {
            if (file_exists($pubkey) && is_readable($pubkey)) {
                $pubkey = (string) file_get_contents($pubkey);
            }
            $keys = array_values(array_filter(array_map('trim', explode("\n", $pubkey)), fn ($k): bool => str_starts_with($k, 'ssh-') || str_starts_with($k, 'ecdsa-')));

            return ['key', $keys];
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        $method = select(
            label: 'How would you like to provide the collaborator\'s public key?',
            options: [
                'github' => 'GitHub Username (automatically fetch public keys)',
                'raw' => 'Paste raw SSH public key or file path',
            ],
        );

        if ($method === 'github') {
            $username = text('Enter GitHub username:', required: true);
            $response = Http::get("https://github.com/{$username}.keys");
            if (! $response->successful()) {
                return null;
            }
            $keys = array_values(array_filter(array_map('trim', explode("\n", $response->body())), fn ($k): bool => str_starts_with($k, 'ssh-') || str_starts_with($k, 'ecdsa-')));

            return ["github:{$username}", $keys];
        }

        $input = text('Paste the SSH public key (or path to .pub file):', required: true);
        if (file_exists($input) && is_readable($input)) {
            $input = (string) file_get_contents($input);
        }
        $keys = array_values(array_filter(array_map('trim', explode("\n", $input)), fn ($k): bool => str_starts_with($k, 'ssh-') || str_starts_with($k, 'ecdsa-')));

        return ['key', $keys];
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

        return select('Which dev box do you want to grant access to?', options: $options);
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
