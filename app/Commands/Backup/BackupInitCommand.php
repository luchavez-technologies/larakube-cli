<?php

namespace App\Commands\Backup;

use App\Exceptions\MissingFlagException;
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
        {--cloudflare-token= : Cloudflare API token, to create the R2 bucket for you}
        {--create-bucket : Create the bucket before configuring (Cloudflare R2 only)}
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

        // Optional: create the bucket first. Only R2 — the account ID is already
        // in the endpoint, so this asks for nothing the user has not supplied.
        if ($this->option('create-bucket') || $this->option('cloudflare-token')) {
            if (! $this->provisionR2Bucket($endpoint, $bucket)) {
                return 1;
            }
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

        // Everything needed to restore from a bare machine, written OFF the
        // cluster. The k8s Secret above is useless in the disaster this exists
        // for — it dies with the cluster, and so does OpenBao, which is itself
        // inside the encrypted archive. This file is the way out of that loop.
        $card = $this->writeRecoveryCard([
            'endpoint' => $endpoint,
            'bucket' => $bucket,
            'access_key' => $accessKey,
            'secret_key' => $secretKey,
            'passphrase' => $passphrase,
            'region' => (string) $this->option('region'),
        ]);

        if ($card !== null) {
            $this->line("  <fg=gray>Recovery card:</> <fg=blue>{$card}</>");
            $this->newLine();
        }

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

    /**
     * Create the destination bucket via the Cloudflare API.
     *
     * Mirrors dns:init's token handling: prompted for with the exact scope
     * required, and never persisted — it is used for this one call and
     * discarded. The backup itself authenticates with the S3 access keys, which
     * are a different, narrower credential.
     */
    protected function provisionR2Bucket(string $endpoint, string $bucket): bool
    {
        $accountId = $this->r2AccountId($endpoint);

        if ($accountId === null) {
            $this->laraKubeError('Bucket creation is only supported for Cloudflare R2.');
            $this->line('  <fg=gray>The endpoint must look like https://<account-id>.r2.cloudflarestorage.com.');
            $this->line('  For other providers, create the bucket yourself and drop the flag.</>');

            return false;
        }

        $token = (string) ($this->option('cloudflare-token') ?? '');

        if ($token === '') {
            if ($this->cannotPrompt()) {
                throw new MissingFlagException(
                    'cloudflare-token',
                    'a Cloudflare API token that can create R2 buckets',
                    'larakube backup:init production --create-bucket --cloudflare-token=…',
                );
            }

            $this->newLine();
            $this->info('Create a Cloudflare API token that can make R2 buckets:');
            $this->line('  1. <fg=blue>https://dash.cloudflare.com/profile/api-tokens</>');
            $this->line('  2. Create Token → Create Custom Token');
            $this->line('  3. Permissions: <fg=yellow>Account</> · <fg=yellow>Workers R2 Storage</> · <fg=yellow>Edit</>');
            $this->line('     <fg=gray>This is only used to create the bucket and is never stored. The');
            $this->line('     backups themselves authenticate with the S3 keys below, which are a');
            $this->line('     narrower credential.</>');
            $this->newLine();

            $token = (string) text(label: 'Cloudflare API token', required: true);
        }

        $result = ['ok' => false, 'message' => ''];
        $this->withSpin("Creating R2 bucket '{$bucket}'...", function () use ($accountId, $bucket, $token, &$result) {
            $result = $this->createR2Bucket($accountId, $bucket, $token);
        });

        if (! $result['ok']) {
            $this->laraKubeError($result['message']);

            return false;
        }

        $this->line("  <fg=gray>{$result['message']}</>");

        return true;
    }

    /**
     * Write the five values a bare-machine restore needs to a 0600 file in the
     * user's home directory. Returns the path, or null if it could not be
     * written — never fatal, since the destination itself is already stored.
     *
     * @param  array<string, string>  $config
     */
    protected function writeRecoveryCard(array $config): ?string
    {
        $dir = home_path('.larakube');

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            return null;
        }

        $path = $dir.'/backup-recovery.txt';

        $body = <<<TXT
        LaraKube CLI — backup recovery card
        Written {$this->now()}

        Everything below is what `backup:restore` needs when there is no cluster
        left to read the destination from. Keep a copy somewhere that is not this
        machine and not that server — a password manager on another device, or
        printed. Without the passphrase the archives are unreadable noise.

          endpoint    {$config['endpoint']}
          bucket      {$config['bucket']}
          region      {$config['region']}
          access key  {$config['access_key']}
          secret key  {$config['secret_key']}
          passphrase  {$config['passphrase']}

        To restore from a machine with nothing else:

          larakube backup:restore \\
            --endpoint={$config['endpoint']} \\
            --bucket={$config['bucket']} \\
            --access-key={$config['access_key']} \\
            --secret-key=<secret key above> \\
            --passphrase=<passphrase above>

        TXT;

        if (@file_put_contents($path, $body) === false) {
            return null;
        }

        @chmod($path, 0600);

        return $path;
    }

    protected function now(): string
    {
        return date('Y-m-d H:i');
    }
}
