<?php

namespace App\Traits;

use App\Enums\CliTool;
use App\State;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

trait InteractsWithAws
{
    use InteractsWithGlobalConfig;

    /**
     * Prompt for + persist AWS credentials and profile, ensuring active authentication.
     */
    protected function ensureAwsCredentials(): bool
    {
        if ($flagProfile = $this->flag('aws-profile')) {
            State::$transientAwsProfile = trim($flagProfile);
        }

        if ($flagRegion = $this->flag('aws-region')) {
            State::$transientAwsRegion = trim($flagRegion);
        }

        if ($flagAccessKey = $this->flag('aws-access-key-id')) {
            State::$transientAwsAccessKeyId = trim($flagAccessKey);
        }

        if ($flagSecretKey = $this->flag('aws-secret-access-key')) {
            State::$transientAwsSecretAccessKey = trim($flagSecretKey);
            $this->registerSecret(State::$transientAwsSecretAccessKey);
        }

        // Offer aws install if missing and running interactively
        if (! CliTool::AWS->isInstalled() && ! $this->flag('no-interaction') && $this->output !== null) {
            if (confirm('AWS CLI is not installed. Would you like to install it now via larakube setup?', default: false)) {
                $this->call('setup', ['--tools' => 'aws']);
            }
        }

        $envVars = $this->buildAwsEnv();
        $awsBin = CliTool::AWS->resolveBinary() ?? 'aws';
        $profileArg = $this->getAwsProfile() ? ' --profile '.escapeshellarg($this->getAwsProfile()) : '';

        // Check active caller identity
        $identityProcess = Process::env($envVars)->run("{$awsBin} sts get-caller-identity{$profileArg} 2>/dev/null");
        $awsAuthed = $identityProcess->successful();

        // Non-interactive check
        if ($this->flag('no-interaction')) {
            if (! $awsAuthed && ! ($this->getAwsAccessKeyId() && $this->getAwsSecretAccessKey())) {
                $this->laraKubeError('No active AWS credentials detected. Pass --aws-access-key-id= and --aws-secret-access-key=, or configure via `aws configure`.');

                return false;
            }

            return true;
        }

        // Interactive authentication
        if ($awsAuthed) {
            $account = 'unknown';
            $arn = 'unknown';
            $data = json_decode($identityProcess->output(), true);
            if (is_array($data)) {
                $account = $data['Account'] ?? 'unknown';
                $arn = $data['Arn'] ?? 'unknown';
            }

            $this->line("  <fg=green>✓</> <fg=gray>Detected active AWS credentials (Account: {$account}, Arn: {$arn}).</>");

            return true;
        }

        if ($this->getAwsAccessKeyId() && $this->getAwsSecretAccessKey()) {
            return true;
        }

        // Offer running `aws configure` if AWS CLI is installed
        if (CliTool::AWS->isInstalled()) {
            $this->newLine();
            $this->laraKubeWarn('AWS CLI is not configured with active credentials.');

            if (! app()->runningUnitTests() && ! Process::isRecording() && confirm('Run `aws configure` in your terminal now?', default: true)) {
                passthru("{$awsBin} configure", $code);
                if ($code === 0) {
                    $this->line('  <fg=green>✓</> <fg=gray>AWS CLI configuration completed.</>');
                    $retry = Process::env($envVars)->run("{$awsBin} sts get-caller-identity{$profileArg} 2>/dev/null");
                    if ($retry->successful()) {
                        return true;
                    }
                }
            }
        }

        // Prompt for manual Access Key ID and Secret Access Key
        $this->line('  <fg=gray>You can provide AWS IAM Access Keys directly.</>');
        $keyId = text(
            label: 'AWS Access Key ID',
            placeholder: 'AKIA...',
            default: $this->getAwsAccessKeyId() ?? '',
            required: false,
        );

        if ($keyId !== '') {
            $secret = text(
                label: 'AWS Secret Access Key',
                placeholder: 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
                default: $this->getAwsSecretAccessKey() ?? '',
                required: true,
            );

            $region = select(
                label: 'AWS Default Region',
                options: [
                    'us-east-1' => 'us-east-1 — N. Virginia',
                    'us-east-2' => 'us-east-2 — Ohio',
                    'us-west-2' => 'us-west-2 — Oregon',
                    'eu-west-1' => 'eu-west-1 — Ireland',
                    'eu-central-1' => 'eu-central-1 — Frankfurt',
                    'ap-southeast-1' => 'ap-southeast-1 — Singapore',
                ],
                default: $this->getAwsRegion() ?? 'us-east-1',
            );

            $this->setAwsAccessKeyId($keyId);
            $this->setAwsSecretAccessKey($secret);
            $this->setAwsRegion($region);
            $this->registerSecret($secret);

            return true;
        }

        $this->laraKubeError('AWS credentials are required to continue provisioning.');

        return false;
    }

    /**
     * Build environment variables array for AWS CLI and OpenTofu executions.
     *
     * @return array<string, string>
     */
    protected function buildAwsEnv(): array
    {
        $env = [];

        if ($profile = $this->getAwsProfile()) {
            $env['AWS_PROFILE'] = $profile;
        }

        if ($accessKeyId = $this->getAwsAccessKeyId()) {
            $env['AWS_ACCESS_KEY_ID'] = $accessKeyId;
        }

        if ($secretAccessKey = $this->getAwsSecretAccessKey()) {
            $env['AWS_SECRET_ACCESS_KEY'] = $secretAccessKey;
        }

        if ($region = $this->getAwsRegion()) {
            $env['AWS_DEFAULT_REGION'] = $region;
            $env['AWS_REGION'] = $region;
        }

        return $env;
    }
}
