<?php

namespace App\Commands\Cloud;

use App\Data\ConfigData;
use App\Data\StackData;
use App\State;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithOpenTofu;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\ResolvesEnvironmentContext;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class CloudScaleCommand extends Command
{
    use EmitsJsonOutput, InteractsWithEnvironments, InteractsWithOpenTofu, InteractsWithProjectConfig, LaraKubeOutput, ReadsCommandOptions, ResolvesEnvironmentContext;

    protected $signature = 'cloud:scale
        {environment? : Environment bound to a stack (e.g. prod) or direct stack name}
        {--size=      : Target droplet size slug (e.g. s-4vcpu-8gb, s-8vcpu-16gb)}
        {--storage=   : Scale CSI Block Storage size (e.g. 100Gi, 500Gi)}
        {--disk       : Permanently expand the disk along with CPU and RAM}
        {--no-disk    : Resize CPU and RAM only (reversible, default)}
        {--do-token=  : DigitalOcean API token for this run}
        {--force      : Skip confirmation prompt}
        {--json       : Emit machine-readable JSON result}';

    protected $description = 'Scale an existing VPS stack (CPU, RAM, and optional disk size) via OpenTofu';

    private array $result = [];

    public function handle(): int
    {
        if ($this->flag('json') || $this->isAiAgent()) {
            $this->enableJsonMode();
        }

        $exit = $this->scale();

        if (State::$jsonMode) {
            $this->jsonOutput($exit === 0
                ? array_merge(['success' => true, 'stackName' => null, 'size' => null, 'resizeDisk' => false], $this->result, ['error' => null])
                : ['success' => false, 'stackName' => $this->result['stackName'] ?? null, 'error' => State::$lastError ?? 'Scaling did not complete.']);
        }

        return $exit;
    }

    protected function ensureDoToken(): bool
    {
        $flag = $this->option('do-token');
        if ($flag) {
            State::$transientDoToken = (string) $flag;

            return true;
        }

        $envToken = getenv('DIGITALOCEAN_TOKEN') ?: getenv('DO_TOKEN');
        if ($envToken) {
            State::$transientDoToken = (string) $envToken;

            return true;
        }

        if ($this->getDoToken()) {
            return true;
        }

        if ($this->flag('no-interaction')) {
            $this->laraKubeError('DigitalOcean token missing — pass --do-token= or set DIGITALOCEAN_TOKEN in environment.');

            return false;
        }

        $token = text(
            label: 'Enter your DigitalOcean Personal Access Token',
            placeholder: 'dop_v1_...',
            required: true,
        );

        $this->setDoToken($token);

        return true;
    }

    private function scale(): int
    {
        $bin = $this->ensureTofu();
        if (! $bin) {
            return 1;
        }

        $stack = $this->resolveTargetStack();
        if (! $stack) {
            return 1;
        }

        if ($stack->kind !== 'vps') {
            $this->laraKubeError("Only 'vps' stacks can be scaled via cloud:scale currently. Stack '{$stack->name}' is a '{$stack->kind}' stack.");

            return 1;
        }

        $workdir = $this->tofuWorkdir($stack->name);
        $mainTf = $workdir.'/main.tf';
        if (! file_exists($mainTf)) {
            $this->laraKubeError("OpenTofu manifest main.tf not found for stack '{$stack->name}' at {$workdir}.");

            return 1;
        }

        if (! $this->ensureDoToken()) {
            return 1;
        }

        $newSize = $this->resolveSize();
        if (! $newSize) {
            return 1;
        }

        $resizeDisk = $this->resolveDiskOption();
        $storageArg = $this->option('storage');

        if (! $this->flag('force') && ! $this->flag('no-interaction')) {
            $diskNotice = $resizeDisk
                ? '<fg=yellow>WARNING: Disk expansion is PERMANENT and cannot be reversed!</>'
                : '<fg=gray>CPU & RAM resize only (reversible).</>';

            $this->line("Scaling stack <fg=cyan>{$stack->name}</> to <fg=blue>{$newSize}</>.");
            $this->line("Disk Expansion: {$diskNotice}");
            if ($storageArg) {
                $this->line("Block Storage: <fg=cyan>{$storageArg}</> via CSI external-provisioner.");
            }

            if (! confirm("Proceed with scaling stack '{$stack->name}'?", default: true)) {
                $this->laraKubeInfo('Scaling cancelled.');

                return 0;
            }
        }

        $tfContent = file_get_contents($mainTf);
        if ($tfContent === false) {
            $this->laraKubeError("Failed to read main.tf for stack '{$stack->name}'.");

            return 1;
        }

        // Update size in main.tf
        $tfContent = preg_replace('/size\s*=\s*"[^"]+"/', 'size     = "'.$newSize.'"', $tfContent);

        // Update or insert resize_disk in main.tf
        $diskBoolStr = $resizeDisk ? 'true' : 'false';
        if (preg_match('/resize_disk\s*=/', $tfContent)) {
            $tfContent = preg_replace('/resize_disk\s*=\s*(true|false)/', 'resize_disk = '.$diskBoolStr, $tfContent);
        } else {
            $tfContent = preg_replace('/(size\s*=\s*"[^"]+")/', "$1\n  resize_disk = ".$diskBoolStr, $tfContent);
        }

        // Update storage_size_gb if --storage is provided
        if ($storageArg) {
            $gbVal = (int) preg_replace('/[^0-9]/', '', (string) $storageArg);
            if ($gbVal > 0) {
                if (preg_match('/storage_size_gb\s*=/', $tfContent)) {
                    $tfContent = preg_replace('/storage_size_gb\s*=\s*\d+/', 'storage_size_gb = '.$gbVal, $tfContent);
                } else {
                    $tfContent = preg_replace('/(resize_disk\s*=\s*(?:true|false))/', "$1\n  storage_size_gb = ".$gbVal, $tfContent);
                }
            }
        }

        file_put_contents($mainTf, $tfContent);

        $this->withSpin("Scaling stack '{$stack->name}' via OpenTofu...", function () use ($bin, $stack) {
            $this->tofuInit($bin, $stack->name);

            return $this->tofuApply($bin, $stack->name);
        });

        $this->result = [
            'stackName' => $stack->name,
            'size' => $newSize,
            'resizeDisk' => $resizeDisk,
            'storageSize' => $storageArg,
        ];

        $this->newLine();
        $this->laraKubeInfo("✅ Stack '{$stack->name}' successfully scaled to <fg=cyan>{$newSize}</>.");
        $this->line('  <fg=gray>Disk Expansion:</> '.($resizeDisk ? '<fg=yellow>Enabled (Permanent)</>' : '<fg=green>Disabled (Reversible)</>'));
        if ($storageArg) {
            $this->line("  <fg=gray>CSI Block Storage:</> <fg=cyan>{$storageArg}</>");
        }
        $this->newLine();

        return 0;
    }

    private function resolveTargetStack(): ?StackData
    {
        $rawArg = (string) ($this->argument('environment') ?? '');
        $globalConfig = $this->getGlobalConfig();
        $stacks = $globalConfig->getStacks();

        if (empty($stacks)) {
            $this->laraKubeError('No OpenTofu infrastructure stacks found in global config. Create one first using `cloud:create`.');

            return null;
        }

        // Direct stack name match
        if ($rawArg !== '' && isset($stacks[$rawArg])) {
            return $stacks[$rawArg];
        }

        // Project environment match
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        if ($config && $rawArg !== '') {
            $targetEnv = $config->getEnvironment($rawArg);
            if ($targetEnv && $targetEnv->cloud) {
                $stackKey = $targetEnv->cloud->context ?? $targetEnv->cloud->ip;
                if ($stackKey && isset($stacks[$stackKey])) {
                    return $stacks[$stackKey];
                }
            }
        }

        // Non-interactive fallback failure
        if ($this->flag('no-interaction')) {
            $this->laraKubeError("Target stack '{$rawArg}' not found — pass a valid environment or stack name.");

            return null;
        }

        // Interactive selection
        $options = [];
        foreach ($stacks as $s) {
            $options[$s->name] = "{$s->name}  ({$s->kind}, region: ".($s->region ?? '?').', ip: '.($s->ip ?? '?').')';
        }

        $selectedName = select(
            label: 'Which infrastructure stack do you want to scale?',
            options: $options,
        );

        return $stacks[$selectedName] ?? null;
    }

    private function resolveSize(): ?string
    {
        $size = $this->flag('size');
        if ($size) {
            return $size;
        }

        if ($this->flag('no-interaction')) {
            $this->laraKubeError('No size specified — pass --size=<slug> (e.g. --size=s-4vcpu-8gb) when running non-interactively.');

            return null;
        }

        return select(
            label: 'Select new server size',
            options: [
                's-1vcpu-1gb' => 's-1vcpu-1gb   —  1 vCPU,  1 GB RAM  (~$6/mo)',
                's-1vcpu-2gb' => 's-1vcpu-2gb   —  1 vCPU,  2 GB RAM  (~$12/mo)',
                's-2vcpu-2gb' => 's-2vcpu-2gb   —  2 vCPU,  2 GB RAM  (~$18/mo)',
                's-2vcpu-4gb' => 's-2vcpu-4gb   —  2 vCPU,  4 GB RAM  (~$24/mo)',
                's-4vcpu-8gb' => 's-4vcpu-8gb   —  4 vCPU,  8 GB RAM  (~$48/mo)',
                's-8vcpu-16gb' => 's-8vcpu-16gb  —  8 vCPU, 16 GB RAM  (~$96/mo)',
                's-8vcpu-32gb' => 's-8vcpu-32gb  —  8 vCPU, 32 GB RAM  (~$192/mo)',
            ],
            default: 's-4vcpu-8gb',
            hint: 'Need a custom size? Pass --size=<slug> (e.g. c-2, m-2vcpu-16gb).',
        );
    }

    private function resolveDiskOption(): bool
    {
        if ($this->flag('disk')) {
            return true;
        }

        if ($this->flag('no-disk') || $this->flag('no-interaction')) {
            return false;
        }

        return confirm(
            label: 'Permanently expand the disk size as well? (Default: No — CPU & RAM only, which is reversible)',
            default: false,
        );
    }
}
