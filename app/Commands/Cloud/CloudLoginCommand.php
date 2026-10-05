<?php

namespace App\Commands\Cloud;

use App\Enums\CliTool;
use App\Exceptions\MissingFlagException;
use App\Traits\EmitsJsonOutput;
use App\Traits\FailsWithJson;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\StreamsProcessOutput;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Signs in to a cloud provider's own CLI. In a terminal that is the provider's normal browser sign-in. Under --json (a
 * GUI, with no terminal) it prints the sign-in address on stderr, waits for the verification code on stdin, and ends with
 * one JSON result, so LaraKube Desktop can open the address, ask for the code and pass it back.
 */
class CloudLoginCommand extends Command
{
    use EmitsJsonOutput, FailsWithJson, LaraKubeOutput, ReadsCommandOptions, RequiresFlagsWhenNonInteractive, StreamsProcessOutput;

    protected $signature = 'cloud:login
        {--provider= : The provider to sign in to (gcp)}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Sign in to a cloud provider (Google Cloud), with the verification code read from stdin under --json';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        try {
            $provider = $this->flagOrPrompt(
                'provider',
                fn () => select('Which provider?', ['gcp' => 'Google Cloud'], default: 'gcp'),
                'the provider to sign in to',
                '--provider=gcp',
            );
        } catch (MissingFlagException $e) {
            return $this->failed($e->getMessage());
        }

        if ($provider !== 'gcp') {
            return $this->failed("Signing in from the CLI supports --provider=gcp only, not '{$provider}'.");
        }

        if (! CliTool::GCLOUD->isInstalled()) {
            return $this->failed('Google Cloud CLI (gcloud) is not installed. Run: larakube setup --tools=gcloud');
        }

        $gcloud = CliTool::GCLOUD->resolveBinary() ?? 'gcloud';

        // A person at a terminal gets the browser sign-in; a caller with no terminal gets the address and the code prompt.
        $code = $this->flag('json') || ! SymfonyProcess::isTtySupported()
            ? $this->runWithStdinLine("{$gcloud} auth login --no-launch-browser --update-adc")
            : $this->runInteractive("{$gcloud} auth login --update-adc");

        if ($code !== 0) {
            return $this->failed('Google Cloud sign-in did not finish.');
        }

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'provider' => 'gcp']);
        } else {
            $this->laraKubeInfo('Signed in to Google Cloud.');
        }

        return 0;
    }
}
