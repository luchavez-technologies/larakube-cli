<?php

namespace App\Commands\Cloud;

use App\Enums\CliTool;
use App\Exceptions\MissingFlagException;
use App\Services\Cloud\AwsCredentialsFile;
use App\Traits\EmitsJsonOutput;
use App\Traits\FailsWithJson;
use App\Traits\InteractsWithGcp;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\RequiresFlagsWhenNonInteractive;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

/**
 * Saves AWS access keys or Google Cloud Service Account JSON credentials.
 * Secrets are read from the environment (AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY or
 * GCP_SERVICE_ACCOUNT_JSON / GOOGLE_APPLICATION_CREDENTIALS), never from flags.
 */
class CloudCredentialsCommand extends Command
{
    use EmitsJsonOutput, FailsWithJson, InteractsWithGcp, InteractsWithGlobalConfig, LaraKubeOutput, ReadsCommandOptions, RequiresFlagsWhenNonInteractive;

    protected $signature = 'cloud:credentials
        {--provider= : The provider the keys are for (aws, gcp)}
        {--region= : The default AWS region, such as us-east-1}
        {--profile= : AWS CLI profile name (default: default)}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Save cloud credentials (AWS access keys or GCP Service Account JSON)';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        try {
            $provider = $this->flagOrPrompt(
                'provider',
                fn () => select('Which provider?', ['aws' => 'Amazon Web Services', 'gcp' => 'Google Cloud'], default: 'aws'),
                'the provider the keys are for',
                '--provider=aws',
            );

            if ($provider !== 'aws' && $provider !== 'gcp') {
                return $this->failed("Keys are saved for --provider=aws or --provider=gcp only, not '{$provider}'.");
            }

            if ($provider === 'gcp') {
                return $this->handleGcp();
            }

            $region = $this->flagOrPrompt(
                'region',
                fn () => text('Default AWS region', default: 'us-east-1', required: true),
                'the default AWS region',
                '--region=us-east-1',
            );
        } catch (MissingFlagException $e) {
            return $this->failed($e->getMessage());
        }

        $keyId = trim((string) getenv('AWS_ACCESS_KEY_ID'));
        $secret = trim((string) getenv('AWS_SECRET_ACCESS_KEY'));

        if (preg_match('/^[A-Z0-9]{16,32}$/', $keyId) !== 1 || strlen($secret) < 16) {
            return $this->failed('Set AWS_ACCESS_KEY_ID (16-32 capital letters and digits) and AWS_SECRET_ACCESS_KEY in the environment.');
        }

        if (preg_match('/^[a-z0-9-]+$/', $region) !== 1) {
            return $this->failed("'{$region}' is not an AWS region.");
        }

        $this->registerSecret($secret);

        $profile = $this->option('profile') ?: 'default';
        AwsCredentialsFile::save(home_path(), $keyId, $secret, $region, $profile);

        $verified = null;

        if (CliTool::AWS->isInstalled()) {
            $bin = CliTool::AWS->resolveBinary() ?? 'aws';
            $verified = Process::timeout(20)->env(['AWS_ACCESS_KEY_ID' => $keyId, 'AWS_SECRET_ACCESS_KEY' => $secret])->run("{$bin} sts get-caller-identity --profile ".escapeshellarg($profile))->successful();
        }

        if ($this->flag('json')) {
            $data = ['success' => true, 'provider' => 'aws', 'region' => $region, 'verified' => $verified];
            if ($profile !== 'default') {
                $data['profile'] = $profile;
            }
            $this->jsonOutput($data);
        } else {
            $this->laraKubeInfo("AWS keys saved as profile '{$profile}'".($verified === false ? ', but AWS did not accept them.' : '.'));
        }

        return 0;
    }

    protected function handleGcp(): int
    {
        $rawJson = getenv('GCP_SERVICE_ACCOUNT_JSON') ?: getenv('GOOGLE_APPLICATION_CREDENTIALS');
        if (! $rawJson) {
            return $this->failed('Set GCP_SERVICE_ACCOUNT_JSON or GOOGLE_APPLICATION_CREDENTIALS in the environment.');
        }

        $rawJson = trim((string) $rawJson);
        $filePath = null;
        $data = null;
        $candidatePath = str_replace('~', home_path(), $rawJson);

        if (is_file($candidatePath)) {
            $filePath = $candidatePath;
            $content = file_get_contents($filePath);
            $data = is_string($content) ? json_decode($content, true) : null;
        } else {
            $data = json_decode($rawJson, true);
        }

        if (! is_array($data) || empty($data['type']) || ($data['type'] !== 'service_account') || empty($data['project_id']) || empty($data['client_email'])) {
            return $this->failed('The provided credentials do not appear to be a valid Google Cloud Service Account JSON key.');
        }

        $projectId = (string) $data['project_id'];
        $clientEmail = (string) $data['client_email'];

        if (! $filePath) {
            $configDir = home_path().'/.larakube';
            if (! is_dir($configDir)) {
                mkdir($configDir, 0755, true);
            }
            $filePath = $configDir."/gcp-credentials-{$projectId}.json";
            file_put_contents($filePath, $rawJson);
            chmod($filePath, 0600);
        }

        $this->setGcpCredentials($filePath);
        $this->setGcpProjectId($projectId);
        $this->setGcpAccount($clientEmail);

        $activated = null;
        if (CliTool::GCLOUD->isInstalled()) {
            $gcloud = CliTool::GCLOUD->resolveBinary() ?? 'gcloud';
            $proc = Process::run("{$gcloud} auth activate-service-account --key-file=".escapeshellarg($filePath).' 2>/dev/null');
            $activated = $proc->successful();
            if ($activated) {
                Process::run("{$gcloud} config set project ".escapeshellarg($projectId).' 2>/dev/null');
            }
        }

        if ($this->flag('json')) {
            $this->jsonOutput([
                'success' => true,
                'provider' => 'gcp',
                'project_id' => $projectId,
                'account' => $clientEmail,
                'file' => $filePath,
                'activated' => $activated,
            ]);
        } else {
            $this->laraKubeInfo("Google Cloud Service Account '{$clientEmail}' saved for project '{$projectId}'.");
        }

        return 0;
    }
}
