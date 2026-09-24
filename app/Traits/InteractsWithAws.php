<?php

namespace App\Traits;

use App\Enums\CliTool;
use App\Facades\State;
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
            State::setTransientAwsProfile($flagProfile);
        }

        if ($flagRegion = $this->flag('aws-region')) {
            State::setTransientAwsRegion($flagRegion);
        }

        if ($flagAccessKey = $this->flag('aws-access-key-id')) {
            State::setTransientAwsAccessKeyId($flagAccessKey);
        }

        if ($flagSecretKey = $this->flag('aws-secret-access-key')) {
            State::setTransientAwsSecretAccessKey($flagSecretKey);
            $this->registerSecret(State::transientAwsSecretAccessKey());
        }

        // Offer aws install if missing and running interactively
        if (! CliTool::AWS->isInstalled() && ! $this->flag('no-interaction') && $this->output !== null) {
            if (confirm('AWS CLI is not installed. Would you like to install it now via larakube setup?', default: false)) {
                $this->call('setup', ['--tools' => 'aws']);
            }
        }

        // Step 1: Multi-profile discovery & selection
        $hasExplicitKeys = ($this->getAwsAccessKeyId() && $this->getAwsSecretAccessKey());
        $profiles = $this->listAwsProfiles();

        if (! $hasExplicitKeys && ! empty($profiles)) {
            if (count($profiles) > 1 && ! $this->flag('aws-profile') && ! State::transientAwsProfile()) {
                if ($this->flag('no-interaction')) {
                    $this->laraKubeError('Multiple AWS profiles detected ('.implode(', ', $profiles).'). Pass --aws-profile= when running non-interactively.');

                    return false;
                }

                $activeProfile = getenv('AWS_PROFILE') ?: ($this->getGlobalConfig()->getAwsProfile() ?: (in_array('default', $profiles, true) ? 'default' : $profiles[0]));

                $options = [];
                foreach ($profiles as $prof) {
                    $isActive = ($prof === $activeProfile);
                    $identity = $this->getAwsProfileIdentity($prof);
                    $meta = $identity ? "(Account: {$identity['account']}, arn: {$identity['arn']})" : '(unverified)';
                    $options[$prof] = "{$prof}  {$meta}".($isActive ? ' [active]' : '');
                }
                $options['__add__'] = '+ Add new AWS profile';

                $chosen = select(
                    label: 'Which AWS profile would you like to use?',
                    options: $options,
                    default: isset($options[$activeProfile]) ? $activeProfile : array_key_first($options),
                );

                if ($chosen === '__add__') {
                    $newProfile = text(
                        label: 'Enter new AWS profile name',
                        placeholder: 'e.g. staging, client-prod',
                        required: true,
                        validate: fn ($v) => preg_match('/^[a-zA-Z0-9_-]+$/', $v) ? null : 'Profile name may only contain alphanumeric characters, hyphens, and underscores.',
                    );

                    $awsBin = CliTool::AWS->resolveBinary() ?? 'aws';
                    if (! app()->runningUnitTests() && ! Process::isRecording()) {
                        passthru("{$awsBin} configure --profile ".escapeshellarg($newProfile), $code);
                        if ($code === 0) {
                            $this->line("  <fg=green>✓</> <fg=gray>AWS profile '{$newProfile}' configured.</>");
                        }
                    }
                    $chosen = $newProfile;
                }

                State::setTransientAwsProfile($chosen);
                $this->setAwsProfile($chosen);
            } elseif (count($profiles) === 1 && ! State::transientAwsProfile() && ! $this->flag('aws-profile')) {
                State::setTransientAwsProfile($profiles[0]);
                $this->setAwsProfile($profiles[0]);
            }
        }

        $envVars = $this->buildAwsEnv();
        $awsBin = CliTool::AWS->resolveBinary() ?? 'aws';
        $profile = $this->getAwsProfile();
        $profileArg = $profile ? ' --profile '.escapeshellarg($profile) : '';

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

            $profileLabel = $profile ? " (Profile: <fg=cyan>{$profile}</>)" : '';
            $this->line("  <fg=green>✓</> <fg=gray>Detected active AWS credentials{$profileLabel} (Account: {$account}, Arn: {$arn}).</>");

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
                $configureArg = $profile ? ' --profile '.escapeshellarg($profile) : '';
                passthru("{$awsBin} configure{$configureArg}", $code);
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
     * List configured AWS profiles from the local AWS CLI.
     *
     * @return array<int, string>
     */
    protected function listAwsProfiles(): array
    {
        if (! CliTool::AWS->isInstalled()) {
            return [];
        }

        $awsBin = CliTool::AWS->resolveBinary() ?? 'aws';
        $result = Process::run("{$awsBin} configure list-profiles 2>/dev/null");
        if (! $result->successful()) {
            return [];
        }

        $lines = explode("\n", trim($result->output()));
        $profiles = [];
        foreach ($lines as $line) {
            $profile = trim($line);
            if ($profile !== '') {
                $profiles[] = $profile;
            }
        }

        return $profiles;
    }

    /**
     * Query caller identity for a specific AWS profile.
     *
     * @return array{account: string, arn: string}|null
     */
    protected function getAwsProfileIdentity(string $profile): ?array
    {
        $awsBin = CliTool::AWS->resolveBinary() ?? 'aws';
        $result = Process::run("{$awsBin} sts get-caller-identity --profile ".escapeshellarg($profile).' 2>/dev/null');
        if (! $result->successful()) {
            return null;
        }

        $data = json_decode($result->output(), true);
        if (! is_array($data)) {
            return null;
        }

        return [
            'account' => $data['Account'] ?? 'unknown',
            'arn' => $data['Arn'] ?? 'unknown',
        ];
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
