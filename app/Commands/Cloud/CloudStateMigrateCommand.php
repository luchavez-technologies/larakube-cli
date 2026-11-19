<?php

namespace App\Commands\Cloud;

use App\Services\Cloudflare\CloudflareR2Service;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithOpenTofu;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

use LaravelZero\Framework\Commands\Command;

class CloudStateMigrateCommand extends Command
{
    use InteractsWithGlobalConfig, InteractsWithOpenTofu, LaraKubeOutput, RequiresFlagsWhenNonInteractive;

    protected $signature = 'cloud:state:migrate
        {stack? : Stack name to migrate from local tfstate to R2}
        {--all : Migrate all stacks that currently use local tfstate}
        {--bucket='.CloudflareR2Service::DEFAULT_BUCKET.' : R2 bucket name}
        {--cloudflare-token= : Cloudflare API token (if R2 remote state is not yet configured)}
        {--force : Skip confirmation prompt}
        {--json : Output result as JSON}';

    protected $description = 'Upload existing local OpenTofu state to Cloudflare R2 remote state';

    public function handle(CloudflareR2Service $r2): int
    {
        $isJson = (bool) $this->option('json');

        $bin = $this->resolveTofuBinary();
        if ($bin === null) {
            return $this->failResult($isJson, 'OpenTofu or Terraform binary is required.');
        }

        if (($oldVersion = $this->outdatedTofuVersion($bin['path'])) !== null) {
            return $this->failResult(
                $isJson,
                "OpenTofu >= 1.10.0 is required for R2 remote state (found v{$oldVersion}). Run `brew upgrade opentofu` or `larakube setup:reinstall tofu`.",
            );
        }

        $config = $this->getGlobalConfig();
        if (! $config->tofuStateEnabled || ! $config->tofuStateBucket || ! $config->tofuStateEndpoint) {
            $token = (string) ($this->option('cloudflare-token') ?: ($config->cloudflareToken ?? ''));
            if ($token === '') {
                return $this->failResult($isJson, 'Cloudflare R2 remote state is not configured. Run `larakube cloud:state:r2` first.');
            }

            $bucket = trim((string) ($this->option('bucket') ?: CloudflareR2Service::DEFAULT_BUCKET)) ?: CloudflareR2Service::DEFAULT_BUCKET;
            $probe = $r2->detectAndConfigure($token, $bucket);
            if (! $probe['configured']) {
                return $this->failResult($isJson, $probe['reason'] ?? 'Could not configure Cloudflare R2 remote state.');
            }
        }

        $targets = $this->resolveTargetStacks($isJson);
        if ($targets === []) {
            if ($isJson) {
                $this->line((string) json_encode(['success' => true, 'migrated' => [], 'skipped' => []]));
            } else {
                $this->laraKubeInfo('No local-state stacks found to migrate.');
            }

            return 0;
        }

        if (! (bool) $this->option('force') && ! $isJson && ! $this->cannotPrompt()) {
            $list = implode(', ', $targets);
            if (! confirm("Migrate OpenTofu state for [{$list}] to Cloudflare R2?", true)) {
                return 0;
            }
        }

        $migrated = [];
        $failed = [];

        foreach ($targets as $stack) {
            if ($this->migrateSingleStack($bin, $stack, $isJson)) {
                $migrated[] = $stack;
            } else {
                $failed[] = $stack;
            }
        }

        if ($isJson) {
            $this->line((string) json_encode([
                'success' => $failed === [],
                'migrated' => $migrated,
                'failed' => $failed,
            ]));

            return $failed === [] ? 0 : 1;
        }

        if ($migrated !== []) {
            $this->laraKubeInfo('Migrated to Cloudflare R2: '.implode(', ', $migrated));
            $this->line('  <fg=gray>Back up ~/.larakube/config.json (`tofuPassphrases`) to decrypt state on another machine.</>');
        }

        return $failed === [] ? 0 : 1;
    }

    /** @return list<string> */
    private function resolveTargetStacks(bool $isJson): array
    {
        $requested = (string) ($this->argument('stack') ?? '');
        if ($requested !== '') {
            return [$requested];
        }

        $localStacks = $this->discoverLocalStacks();
        if ($localStacks === []) {
            return [];
        }

        if ((bool) $this->option('all') || $this->cannotPrompt() || $isJson) {
            return $localStacks;
        }

        /** @var list<string> */
        return multiselect(
            label: 'Select stack(s) to migrate to Cloudflare R2',
            options: array_combine($localStacks, $localStacks),
            default: $localStacks,
        );
    }

    /** @return list<string> */
    private function discoverLocalStacks(): array
    {
        $base = home_path('.larakube/tofu');
        if (! is_dir($base)) {
            return [];
        }

        $stacks = [];
        foreach (scandir($base) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $dir = "{$base}/{$entry}";
            if (! is_dir($dir)) {
                continue;
            }

            if (file_exists("{$dir}/terraform.tfstate") && ! file_exists("{$dir}/backend.tf")) {
                $stacks[] = $entry;
            }
        }

        sort($stacks);

        return $stacks;
    }

    /** @param array{path: string, isOpenTofu: bool} $bin */
    private function migrateSingleStack(array $bin, string $stack, bool $isJson): bool
    {
        $dir = $this->tofuWorkdir($stack);
        $hasLocalFile = file_exists("{$dir}/terraform.tfstate") && filesize("{$dir}/terraform.tfstate") > 0;
        $dotState = "{$dir}/.terraform/terraform.tfstate";
        $dotDecoded = is_file($dotState) ? json_decode((string) file_get_contents($dotState), true) : null;
        $alreadyRemoteInDot = is_array($dotDecoded) && (($dotDecoded['backend']['type'] ?? null) === 's3');

        if (! $hasLocalFile && ! $alreadyRemoteInDot) {
            if (! $isJson) {
                $this->laraKubeWarn("Skipping '{$stack}': no local terraform.tfstate found.");
            }

            return false;
        }

        $remote = $this->remoteStateConfig($stack, ignoreLocalState: true);
        if ($remote === null) {
            if (! $isJson) {
                $this->laraKubeError("Remote R2 state configuration is missing for '{$stack}'.");
            }

            return false;
        }

        if (file_exists("{$dir}/.terraform.tfstate.lock.info")) {
            @unlink("{$dir}/.terraform.tfstate.lock.info");
        }

        file_put_contents("{$dir}/backend.tf", view('tofu.backend-s3', $remote + ['stack' => $stack])->render());

        if ($alreadyRemoteInDot && ! $hasLocalFile) {
            return true;
        }

        $env = $this->tofuEnv($stack, $bin['isOpenTofu']);
        $cmd = escapeshellarg($bin['path']).' -chdir='.escapeshellarg($dir).' init -migrate-state -force-copy -input=false -no-color';

        $result = Process::env($env)->timeout(120)->run($cmd);
        if (! $result->successful()) {
            // Roll back backend.tf and any lock file so local state remains intact on failure.
            @unlink("{$dir}/backend.tf");
            @unlink("{$dir}/.terraform.tfstate.lock.info");
            if (! $isJson) {
                $this->laraKubeError("Failed migrating '{$stack}': ".trim($result->errorOutput() ?: $result->output()));
            }

            return false;
        }

        // Archive local tfstate out of the workdir root so local runs always use R2.
        $backupDir = "{$dir}/.local-backup";
        if (! is_dir($backupDir)) {
            @mkdir($backupDir, 0700, true);
        }
        @rename("{$dir}/terraform.tfstate", "{$backupDir}/terraform.tfstate");
        if (file_exists("{$dir}/terraform.tfstate.backup")) {
            @rename("{$dir}/terraform.tfstate.backup", "{$backupDir}/terraform.tfstate.backup");
        }

        return true;
    }

    private function failResult(bool $isJson, string $message): int
    {
        if ($isJson) {
            $this->line((string) json_encode(['success' => false, 'message' => $message]));
        } else {
            $this->laraKubeError($message);
        }

        return 1;
    }
}
