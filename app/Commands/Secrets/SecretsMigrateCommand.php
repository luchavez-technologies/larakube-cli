<?php

namespace App\Commands\Secrets;

use App\Enums\SecretsBackend;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithSecrets;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class SecretsMigrateCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithSecrets, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment;

    protected $signature = 'secrets:migrate
        {environment=local      : Target environment}
        {--from=                : Source engine ("openbao")}
        {--to=                  : Target engine ("openbao")}
        {--dry-run              : Show what would be migrated without writing anything}
        {--output=              : Keep the export file at this path after migration (default: deleted after import)}
        {--force                : Skip confirmation prompts}
        {--context=             : Target a specific kube-context}';

    protected $description = 'Migrate secrets from one engine to another (export → diff → import)';

    public function handle(): int
    {
        $this->renderHeader();

        $fromBackend = $this->resolveSourceEngine();
        $toBackend = $this->resolveTargetEngine();

        if ($fromBackend === null || $toBackend === null) {
            return 1;
        }

        $from = $fromBackend->value;
        $to = $toBackend->value;
        $dryRun = (bool) $this->option('dry-run');
        $keepOutput = (string) ($this->option('output') ?: '');
        $context = (string) ($this->option('context') ?: '');
        $environment = (string) $this->argument('environment');

        // ── 1. Export from source engine ──────────────────────────────────────
        $exportFile = $keepOutput !== '' ? $keepOutput : tempnam(sys_get_temp_dir(), 'larakube_migrate_');

        $this->laraKubeInfo("Step 1/3: Exporting secrets from {$from}...");

        $exportArgs = [
            'environment' => $environment,
            '--engine' => $from,
            '--output' => $exportFile,
            '--no-interaction' => true,
        ];

        if ($context !== '') {
            $exportArgs['--context'] = $context;
        }

        $exitCode = $this->call('secrets:export', $exportArgs);

        if ($exitCode !== 0) {
            $this->laraKubeError('Export failed — migration aborted.');
            $this->cleanupTempFile($exportFile, $keepOutput);

            return 1;
        }

        // ── 2. Read export + show diff ─────────────────────────────────────────
        $this->laraKubeInfo('Step 2/3: Reviewing secrets to migrate...');

        $data = json_decode((string) file_get_contents($exportFile), true);
        $environments = $data['environments'] ?? [];

        $rows = [];
        $totalKeys = 0;

        foreach ($environments as $envSlug => $secrets) {
            foreach ($secrets as $key => $_value) {
                $rows[] = [$envSlug, $key, "→ {$to} secret/{$envSlug}/{$key}"];
                $totalKeys++;
            }
        }

        if (empty($rows)) {
            $this->laraKubeWarn('No secrets found in the export file. Nothing to migrate.');
            $this->cleanupTempFile($exportFile, $keepOutput);

            return 0;
        }

        $this->newLine();
        table(['Environment', 'Secret Key', 'Destination'], $rows);
        $this->newLine();
        $this->laraKubeInfo(sprintf('Total: %d secret(s) from %d environment(s).', $totalKeys, count($environments)));

        if ($dryRun) {
            $this->laraKubeInfo('Dry-run complete. No secrets were written.');
            $this->cleanupTempFile($exportFile, $keepOutput);

            return 0;
        }

        // ── 3. Confirm + import ───────────────────────────────────────────────
        if (! $this->option('force') && ! $this->cannotPrompt()) {
            $confirmed = confirm(
                label: "Write {$totalKeys} secret(s) to {$to}?",
                default: false,
            );

            if (! $confirmed) {
                $this->laraKubeInfo('Migration cancelled.');
                $this->cleanupTempFile($exportFile, $keepOutput);

                return 0;
            }
        }

        $this->laraKubeInfo("Step 3/3: Importing secrets into {$to}...");

        $importArgs = [
            'environment' => $environment,
            '--engine' => $to,
            '--input' => $exportFile,
            '--force' => true,
            '--no-interaction' => true,
        ];

        if ($context !== '') {
            $importArgs['--context'] = $context;
        }

        $exitCode = $this->call('secrets:import', $importArgs);

        $this->cleanupTempFile($exportFile, $keepOutput);

        if ($exitCode !== 0) {
            $this->laraKubeError('Import failed — check the errors above and retry.');

            return 1;
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ Migration from {$from} → {$to} complete.");

        if ($keepOutput !== '') {
            $this->line("  <fg=gray>Export file kept at:</> <fg=blue>{$keepOutput}</>");
            $this->line('  <fg=yellow>⚠  Delete it when no longer needed — it contains raw secret values.</>');
        }

        return 0;
    }

    protected function resolveSourceEngine(): ?SecretsBackend
    {
        return SecretsBackend::OPENBAO;
    }

    protected function resolveTargetEngine(): ?SecretsBackend
    {
        return SecretsBackend::OPENBAO;
    }

    private function cleanupTempFile(string $file, string $keepOutput): void
    {
        if ($keepOutput === '' && file_exists($file)) {
            @unlink($file);
        }
    }
}
