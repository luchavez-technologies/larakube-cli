<?php

namespace App\Commands\Backup;

use App\Traits\InteractsWithBackup;
use App\Traits\InteractsWithClusterContext;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

/**
 * Configure where backups are shipped.
 *
 * Deliberately requires an OFF-CLUSTER destination. The cluster's own SeaweedFS
 * is on the same block device as every volume it would protect, so backing up
 * to it survives a bad migration and nothing else.
 */
class BackupInitCommand extends Command
{
    use InteractsWithBackup, InteractsWithClusterContext, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment;

    protected $signature = 'backup:init
        {environment=local : Environment whose cluster to configure}
        {--endpoint=   : S3-compatible endpoint (e.g. https://s3.us-west-004.backblazeb2.com)}
        {--bucket=     : Destination bucket}
        {--access-key= : Access key ID}
        {--secret-key= : Secret access key}
        {--region=auto : Region. Cloudflare R2 requires "auto"; most others accept it.}
        {--context=    : Target a specific kube-context}';

    protected $description = 'Configure the off-site destination backups are shipped to';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $context = (string) $this->option('context') ?: null;
        $kubectl = $this->backupKubectl($context);
        $ns = $this->backupNamespace();

        $endpoint = (string) ($this->option('endpoint') ?: ($this->cannotPrompt() ? '' : text(
            label: 'S3-compatible endpoint for backups',
            placeholder: 'https://<account-id>.r2.cloudflarestorage.com',
            hint: 'Cloudflare R2 (10GB free, no egress fees), Backblaze B2, or DO Spaces. Must NOT be this cluster.',
        )));

        if ($endpoint === '') {
            $this->laraKubeError('An endpoint is required. Run with --endpoint= or interactively.');

            return 1;
        }

        if ($this->backupDestinationIsOnCluster($endpoint)) {
            $this->laraKubeError('That endpoint is inside this cluster.');
            $this->newLine();
            $this->line('  <fg=gray>The cluster\'s own storage sits on the same disk as every volume it would');
            $this->line('  be protecting — a disk or droplet loss destroys the data and the backups');
            $this->line('  together. Backups have to leave the machine to be backups.</>');
            $this->newLine();

            return 1;
        }

        $bucket = (string) ($this->option('bucket') ?: ($this->cannotPrompt() ? '' : text(
            label: 'Destination bucket', placeholder: 'luchtech-backups',
        )));
        $accessKey = (string) ($this->option('access-key') ?: ($this->cannotPrompt() ? '' : text(label: 'Access key ID')));
        $secretKey = (string) ($this->option('secret-key') ?: ($this->cannotPrompt() ? '' : password(label: 'Secret access key')));

        if ($bucket === '' || $accessKey === '' || $secretKey === '') {
            $this->laraKubeError('Bucket and credentials are all required.');

            return 1;
        }

        // Reuse an existing passphrase: regenerating it would orphan every
        // backup already in the bucket, since none of them could be decrypted.
        $existing = $this->readBackupConfig($kubectl, $ns);
        $passphrase = $existing['passphrase'] ?? Str::random(40);
        $isNew = ($existing['passphrase'] ?? '') === '';

        $ok = $this->withSpin('Storing backup destination...', fn () => Process::run(
            "{$kubectl} create secret generic larakube-backup-config -n {$ns} "
            .'--from-literal=endpoint='.escapeshellarg($endpoint).' '
            .'--from-literal=bucket='.escapeshellarg($bucket).' '
            .'--from-literal=access-key='.escapeshellarg($accessKey).' '
            .'--from-literal=secret-key='.escapeshellarg($secretKey).' '
            .'--from-literal=region='.escapeshellarg((string) $this->option('region')).' '
            .'--from-literal=passphrase='.escapeshellarg($passphrase).' '
            ."--dry-run=client -o yaml | {$kubectl} apply -f -",
        )->successful());

        if (! $ok) {
            $this->laraKubeError('Failed to write the backup config Secret.');

            return 1;
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Backup destination configured.');
        $this->newLine();
        $this->line("  <fg=gray>Endpoint:</>  <fg=blue>{$endpoint}</>");
        $this->line("  <fg=gray>Bucket:</>    <fg=blue>{$bucket}</>");
        $this->newLine();

        if ($isNew) {
            // The passphrase lives in a Secret on the very cluster these
            // backups exist to survive. If the cluster is gone, so is the only
            // copy — and the backups become undecryptable noise. This is the
            // one thing the operator must physically carry off the machine.
            $this->laraKubeWarn('WRITE THIS DOWN SOMEWHERE OFF THIS SERVER — it is the only key to your backups.');
            $this->newLine();
            $this->line("      <fg=yellow;options=bold>{$passphrase}</>");
            $this->newLine();
            $this->line('  <fg=gray>It is stored in a Secret on this cluster, which is exactly what a');
            $this->line('  total-loss restore will not have. A password manager on another machine,');
            $this->line('  or paper, is the point.</>');
            $this->newLine();
        }

        $this->line("  <fg=gray>Next:</> <fg=blue>larakube backup:run {$env}</>");
        $this->newLine();

        return 0;
    }
}
