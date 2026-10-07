<?php

namespace App\Commands\Cloud;

use App\Enums\CliTool;
use App\Exceptions\MissingFlagException;
use App\Services\Cloud\AwsCredentialsFile;
use App\Traits\EmitsJsonOutput;
use App\Traits\FailsWithJson;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\RequiresFlagsWhenNonInteractive;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

/**
 * Saves AWS access keys as the `default` profile of the AWS CLI's own files. The keys are read from AWS_ACCESS_KEY_ID and
 * AWS_SECRET_ACCESS_KEY, never from flags, so they cannot reach a command line or a process list. Other profiles in the
 * files are kept.
 */
class CloudCredentialsCommand extends Command
{
    use EmitsJsonOutput, FailsWithJson, LaraKubeOutput, ReadsCommandOptions, RequiresFlagsWhenNonInteractive;

    protected $signature = 'cloud:credentials
        {--provider= : The provider the keys are for (aws)}
        {--region= : The default AWS region, such as us-east-1}
        {--profile= : AWS CLI profile name (default: default)}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Save AWS access keys (read from AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY) as the default AWS profile';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        try {
            $provider = $this->flagOrPrompt(
                'provider',
                fn () => select('Which provider?', ['aws' => 'Amazon Web Services'], default: 'aws'),
                'the provider the keys are for',
                '--provider=aws',
            );

            if ($provider !== 'aws') {
                return $this->failed("Keys are saved for --provider=aws only, not '{$provider}'.");
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
}
