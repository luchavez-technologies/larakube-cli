<?php

namespace App\Commands\Cloud;

use App\Exceptions\MissingFlagException;
use App\Traits\EmitsJsonOutput;
use App\Traits\FailsWithJson;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\RequiresFlagsWhenNonInteractive;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

/** Chooses the Google Cloud project that `cloud:create` uses, and remembers it. */
class CloudProjectCommand extends Command
{
    use EmitsJsonOutput, FailsWithJson, InteractsWithGlobalConfig, LaraKubeOutput, ReadsCommandOptions, RequiresFlagsWhenNonInteractive;

    protected $signature = 'cloud:project
        {--provider= : The provider the project belongs to (gcp)}
        {--project= : The project ID to use (list them with cloud:projects)}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Choose the Google Cloud project that servers are created in';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        try {
            $provider = $this->flagOrPrompt(
                'provider',
                fn () => select('Which provider?', ['gcp' => 'Google Cloud'], default: 'gcp'),
                'the provider the project belongs to',
                '--provider=gcp',
            );

            if ($provider !== 'gcp') {
                return $this->failed("Projects are chosen for --provider=gcp only, not '{$provider}'.");
            }

            $project = $this->flagOrPrompt(
                'project',
                fn () => text('Google Cloud project ID', required: true),
                'the Google Cloud project to use',
                '--project=my-project-123',
            );
        } catch (MissingFlagException $e) {
            return $this->failed($e->getMessage());
        }

        if (preg_match('/^[a-z][a-z0-9-]{4,28}[a-z0-9]$/', $project) !== 1) {
            return $this->failed("'{$project}' is not a Google Cloud project ID (lowercase letters, digits and dashes, 6 to 30 characters).");
        }

        $this->setGcpProjectId($project);

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'provider' => 'gcp', 'project' => $project]);
        } else {
            $this->laraKubeInfo("Servers on Google Cloud will use project {$project}.");
        }

        return 0;
    }
}
