<?php

namespace App\Commands\Cloud;

use App\Data\ConfigData;
use App\Data\StackData;
use App\Enums\CloudProvider;
use App\State;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithOpenTofu;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\ResolvesEnvironmentContext;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class CloudScaleCommand extends Command
{
    use EmitsJsonOutput, InteractsWithEnvironments, InteractsWithOpenTofu, InteractsWithProjectConfig, LaraKubeOutput, ReadsCommandOptions, ResolvesEnvironmentContext;

    protected $signature = 'cloud:scale
        {environment? : Environment bound to a stack (e.g. prod) or direct stack name}
        {--size=            : Target server size slug (e.g. s-4vcpu-8gb, e2-medium)}
        {--storage=         : Scale CSI Block Storage size (e.g. 100Gi, 500Gi)}
        {--disk             : Permanently expand the disk along with CPU and RAM}
        {--no-disk          : Resize CPU and RAM only (reversible, default)}
        {--do-token=        : DigitalOcean API token for this run}
        {--gcp-project=     : Google Cloud project ID}
        {--gcp-credentials= : Path to GCP Service Account JSON key or raw JSON}
        {--force            : Skip confirmation prompt}
        {--json             : Emit machine-readable JSON result}';

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

    protected function ensureGcpCredentials(): bool
    {
        if ($flagProject = $this->flag('gcp-project')) {
            State::$transientGcpProject = trim($flagProject);
        }

        if ($flagCreds = $this->flag('gcp-credentials')) {
            $path = str_replace('~', home_path(), trim($flagCreds));
            if (! file_exists($path)) {
                $this->laraKubeError("GCP credentials file not found at: {$path}");

                return false;
            }
            State::$transientGcpCredentials = $path;
            $this->registerSecret(State::$transientGcpCredentials);
        }

        $projectId = $this->getGcpProjectId();
        if (! $projectId) {
            if ($this->flag('no-interaction')) {
                $this->laraKubeError('No Google Cloud Project ID found. Pass --gcp-project= or set GOOGLE_PROJECT when running non-interactively.');

                return false;
            }

            $projectId = text(
                label: 'Google Cloud Project ID',
                placeholder: 'my-project-12345',
                required: true,
                hint: 'Find this in your Google Cloud Console dashboard.',
            );
            $this->setGcpProjectId($projectId);
            $this->laraKubeInfo('Saved GCP project ID to your global LaraKube config.');
        }

        $credentials = $this->getGcpCredentials();
        if (! $credentials) {
            $gcloudAuthed = Process::run('gcloud auth print-access-token 2>/dev/null')->successful();
            if ($gcloudAuthed) {
                $this->line('  <fg=green>✓</> <fg=gray>Detected active authentication via local</> <fg=cyan>gcloud</> <fg=gray>CLI.</>');

                return true;
            }

            if ($this->flag('no-interaction')) {
                $this->laraKubeError('No GCP credentials detected. Pass --gcp-credentials= or authenticate via `gcloud auth application-default login`.');

                return false;
            }

            $this->newLine();
            $this->laraKubeWarn('No active gcloud login or GCP service account credentials found.');
            $this->line('  <fg=gray>Options: (1) Run `gcloud auth application-default login` in another terminal, or</>');
            $this->line('  <fg=gray>         (2) Provide a path to a downloaded Service Account JSON key.</>');
            $credsPath = text(
                label: 'Path to Service Account JSON key (or leave blank if using default credentials)',
                required: false,
                hint: 'Leave blank if you logged in via gcloud auth application-default login.',
            );

            if ($credsPath !== '') {
                $resolvedPath = str_replace('~', home_path(), trim($credsPath));
                if (! file_exists($resolvedPath)) {
                    $this->laraKubeError("GCP credentials file not found at: {$resolvedPath}");

                    return false;
                }
                $this->setGcpCredentials($resolvedPath);
                $this->laraKubeInfo('Saved GCP credentials path to your global LaraKube config.');
            }
        }

        return true;
    }

    /** Ensure we have valid API credentials for the stack provider. */
    protected function ensureProviderToken(string $provider): bool
    {
        return match ($provider) {
            'gcp' => $this->ensureGcpCredentials(),
            default => $this->ensureDoToken(),
        };
    }

    protected function resolveSize(string $provider = 'do'): ?string
    {
        $size = $this->flag('size');
        if ($size) {
            return $size;
        }

        $cloud = CloudProvider::tryFrom($provider) ?? CloudProvider::DO;
        $defaultSize = $cloud->defaultVpsSize();

        if ($this->flag('no-interaction')) {
            $this->laraKubeError("No size specified — pass --size=<slug> (e.g. --size={$defaultSize}) when running non-interactively.");

            return null;
        }

        return select(
            label: 'Select new server size',
            options: $cloud->vpsSizes(),
            default: $defaultSize,
            hint: 'Need a custom size? Pass --size=<slug>.',
        );
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

        $provider = $stack->provider ?? 'do';
        if (! $this->ensureProviderToken($provider)) {
            return 1;
        }

        $newSize = $this->resolveSize($provider);
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

        // Update size or machine_type in main.tf
        if ($provider === 'gcp') {
            $tfContent = preg_replace('/machine_type\s*=\s*"[^"]+"/', 'machine_type = "'.$newSize.'"', $tfContent);
        } else {
            $tfContent = preg_replace('/size\s*=\s*"[^"]+"/', 'size     = "'.$newSize.'"', $tfContent);
        }

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
