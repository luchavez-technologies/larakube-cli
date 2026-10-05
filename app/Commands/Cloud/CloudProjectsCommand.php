<?php

namespace App\Commands\Cloud;

use App\Enums\CliTool;
use App\Exceptions\MissingFlagException;
use App\Traits\EmitsJsonOutput;
use App\Traits\FailsWithJson;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\RequiresFlagsWhenNonInteractive;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;
use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/** The projects the signed-in Google account can use, so a GUI can offer them and `cloud:project` can pick one. */
class CloudProjectsCommand extends Command
{
    use EmitsJsonOutput, FailsWithJson, InteractsWithGlobalConfig, LaraKubeOutput, ReadsCommandOptions, RequiresFlagsWhenNonInteractive;

    protected $signature = 'cloud:projects
        {--provider= : The provider to list projects of (gcp)}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'List the Google Cloud projects the signed-in account can use';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        try {
            $provider = $this->flagOrPrompt(
                'provider',
                fn () => select('Which provider?', ['gcp' => 'Google Cloud'], default: 'gcp'),
                'the provider to list projects of',
                '--provider=gcp',
            );
        } catch (MissingFlagException $e) {
            return $this->failed($e->getMessage());
        }

        if ($provider !== 'gcp') {
            return $this->failed("Projects are listed for --provider=gcp only, not '{$provider}'.");
        }

        if (! CliTool::GCLOUD->isInstalled()) {
            return $this->failed('Google Cloud CLI (gcloud) is not installed. Run: larakube setup --tools=gcloud');
        }

        $gcloud = CliTool::GCLOUD->resolveBinary() ?? 'gcloud';
        $result = Process::timeout(60)->run("{$gcloud} projects list --format=\"json(projectId,name)\"");
        $decoded = json_decode($result->output(), true);

        if (! $result->successful() || ! is_array($decoded)) {
            return $this->failed('Could not list Google Cloud projects. Sign in first with: larakube cloud:login --provider=gcp');
        }

        $projects = [];

        foreach ($decoded as $project) {
            if (is_array($project) && is_string($project['projectId'] ?? null)) {
                $projects[] = ['id' => $project['projectId'], 'name' => is_string($project['name'] ?? null) ? $project['name'] : $project['projectId']];
            }
        }

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'provider' => 'gcp', 'selected' => $this->getGcpProjectId(), 'projects' => $projects]);

            return 0;
        }

        table(headers: ['Project', 'Name'], rows: array_map(fn (array $project): array => [$project['id'], $project['name']], $projects));

        return 0;
    }
}
