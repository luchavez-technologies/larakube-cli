<?php

namespace App\Commands\Cloud;

use App\Data\StackData;
use App\Services\Devbox\DevBoxBundle;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithRemoteSsh;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\RequiresFlagsWhenNonInteractive;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class DevboxImportCommand extends Command
{
    use EmitsJsonOutput, InteractsWithGlobalConfig, InteractsWithRemoteSsh, LaraKubeOutput, ReadsCommandOptions, RequiresFlagsWhenNonInteractive;

    protected $signature = 'devbox:import
        {file : Path to the encrypted .devbox export file}
        {--name= : Rename the dev box on this machine (defaults to original name)}
        {--passphrase= : Passphrase to decrypt the bundle}
        {--skip-test : Skip testing the SSH connection after importing}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Import an encrypted dev box bundle onto this machine';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $this->renderHeader();

        $file = (string) $this->argument('file');
        if (! file_exists($file) || ! is_readable($file)) {
            return $this->failWithError("Export bundle file not found: {$file}");
        }

        $bundleJson = file_get_contents($file);
        $passphrase = $this->resolvePassphrase();

        if ($passphrase === null || $passphrase === '') {
            return $this->failWithError('A passphrase is required to decrypt the export bundle.');
        }

        try {
            $payload = DevBoxBundle::decrypt($bundleJson, $passphrase);
        } catch (RuntimeException $e) {
            return $this->failWithError($e->getMessage());
        }

        $stack = $payload['stack'] ?? null;
        $keys = $payload['keys'] ?? null;

        if (! is_array($stack) || empty($stack['name']) || empty($stack['ip']) || empty($keys['private'])) {
            return $this->failWithError('Malformed DevBox bundle: missing required stack or SSH credentials.');
        }

        $config = $this->getGlobalConfig();
        $targetName = (string) ($this->flag('name') ?: $stack['name']);

        $existing = $config->findStack($targetName);
        if ($existing !== null) {
            if (! $this->input->isInteractive()) {
                return $this->failWithError("A stack named '{$targetName}' already exists. Use --name= to specify an alternate name.");
            }

            if (! confirm("A stack named '{$targetName}' already exists. Overwrite it?", default: false)) {
                $targetName = text('Enter a new name for this dev box:', required: true);
            }
        }

        $sshDir = home_path('.ssh');
        if (! is_dir($sshDir)) {
            mkdir($sshDir, 0700, true);
        }

        $keyPath = "{$sshDir}/devbox-{$targetName}";
        file_put_contents($keyPath, (string) $keys['private']);
        chmod($keyPath, 0600);

        if (! empty($keys['public'])) {
            file_put_contents("{$keyPath}.pub", (string) $keys['public']);
            chmod("{$keyPath}.pub", 0644);
        }

        $stackData = new StackData(
            name: $targetName,
            provider: (string) ($stack['provider'] ?? 'do'),
            kind: 'vps',
            region: $stack['region'] ?? null,
            context: null,
            ip: $stack['ip'] ?? null,
            account: $stack['account'] ?? null,
            projectId: $stack['projectId'] ?? null,
            createdAt: $stack['createdAt'] ?? now()->toIso8601String(),
            sshKey: $keyPath,
            role: 'dev',
        );

        $config->putStack($stackData);
        $config->save();

        $tested = false;
        $reachable = false;

        if (! $this->flag('skip-test') && $stackData->ip) {
            $this->laraKubeInfo("Testing SSH connectivity to {$stackData->name} ({$stackData->ip})...");
            $tested = true;
            $reachable = $this->testSsh('larakube', $stackData->ip, 22, $keyPath);
        }

        if ($this->flag('json')) {
            $this->jsonOutput([
                'success' => true,
                'stack' => $stackData->name,
                'ip' => $stackData->ip,
                'sshKey' => $keyPath,
                'tested' => $tested,
                'reachable' => $reachable,
            ]);

            return 0;
        }

        $this->laraKubeInfo("Dev box '{$targetName}' imported successfully.");
        $this->line("  Address:    <fg=cyan>{$stackData->ip}</>");
        $this->line("  SSH Key:    <fg=cyan>{$keyPath}</>");

        if ($tested) {
            if ($reachable) {
                $this->line('  Status:     <fg=green>Reachable & verified</>');
            } else {
                $this->laraKubeWarn('Dev box imported, but SSH test failed. Check the server is running and firewall rules permit your current IP.');
            }
        }

        $this->newLine();
        $this->line('  <fg=gray>Connect now:</>       <fg=yellow>larakube devbox:connect --stack-name='.$targetName.'</>');
        $this->line('  <fg=gray>SSH shell:</>         <fg=cyan>ssh -i '.$keyPath.' larakube@'.$stackData->ip.'</>');

        return 0;
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
            label: 'Enter the passphrase to decrypt this DevBox bundle:',
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
