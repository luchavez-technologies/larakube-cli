<?php

namespace App\Commands\Cloud;

use App\Services\Devbox\DevBoxBundle;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\RequiresFlagsWhenNonInteractive;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class DevboxExportCommand extends Command
{
    use EmitsJsonOutput, InteractsWithGlobalConfig, LaraKubeOutput, ReadsCommandOptions, RequiresFlagsWhenNonInteractive;

    protected $signature = 'devbox:export
        {name? : The name of the dev box to export}
        {--output= : Path to save the encrypted .devbox export file (defaults to ./<name>.devbox)}
        {--passphrase= : Passphrase for encryption (prompted if omitted)}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Export a dev box configuration and credentials as an encrypted bundle for machine migration';

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

        $name = (string) ($this->argument('name') ?: $this->chooseDevBox($devBoxes));
        if ($name === '') {
            return $this->failWithError('No dev box selected.');
        }

        $stack = $config->findStack($name);
        if ($stack === null || $stack->role !== 'dev') {
            return $this->failWithError("Dev box '{$name}' not found. List registered dev boxes with larakube cloud:stacks.");
        }

        if (! $stack->sshKey || ! file_exists($stack->sshKey)) {
            return $this->failWithError("SSH private key for '{$name}' not found at: ".($stack->sshKey ?? 'unknown'));
        }

        $passphrase = $this->resolvePassphrase();
        if ($passphrase === null || $passphrase === '') {
            return $this->failWithError('A passphrase is required to encrypt the export bundle.');
        }

        $privateKey = file_get_contents($stack->sshKey);
        $publicKey = file_exists($stack->sshKey.'.pub') ? file_get_contents($stack->sshKey.'.pub') : null;

        $payload = [
            'version' => 1,
            'exportedAt' => now()->toIso8601String(),
            'stack' => [
                'name' => $stack->name,
                'provider' => $stack->provider,
                'kind' => $stack->kind,
                'region' => $stack->region,
                'ip' => $stack->ip,
                'account' => $stack->account,
                'projectId' => $stack->projectId,
                'role' => 'dev',
            ],
            'keys' => [
                'private' => $privateKey,
                'public' => $publicKey,
            ],
        ];

        try {
            $encrypted = DevBoxBundle::encrypt($payload, $passphrase);
        } catch (RuntimeException $e) {
            return $this->failWithError($e->getMessage());
        }

        $outputPath = (string) ($this->flag('output') ?: getcwd()."/{$stack->name}.devbox");
        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        file_put_contents($outputPath, $encrypted);
        @chmod($outputPath, 0600);

        if ($this->flag('json')) {
            $this->jsonOutput([
                'success' => true,
                'file' => $outputPath,
                'stack' => $stack->name,
            ]);

            return 0;
        }

        $this->laraKubeInfo("Dev box '{$stack->name}' exported successfully.");
        $this->line("  Saved to:   <fg=cyan>{$outputPath}</>");
        $this->line('  Encrypted:  <fg=green>AES-256-GCM (PBKDF2-SHA256, 100k rounds)</>');
        $this->newLine();
        $this->line('  <fg=gray>Import on another computer with:</> <fg=yellow>larakube devbox:import '.basename($outputPath).'</>');

        return 0;
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

        return select('Which dev box do you want to export?', options: $options);
    }

    private function resolvePassphrase(): ?string
    {
        $flag = (string) $this->flag('passphrase');
        if ($flag !== '') {
            return $flag;
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        return password(
            label: 'Enter an encryption passphrase for this DevBox bundle:',
            placeholder: 'Keep this passphrase safe to decrypt the bundle',
            required: true,
        );
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
