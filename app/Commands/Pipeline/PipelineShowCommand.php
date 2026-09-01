<?php

namespace App\Commands\Pipeline;

use App\Traits\InteractsWithPipelines;
use App\Traits\LaraKubeOutput;
use Exception;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Yaml\Yaml;

class PipelineShowCommand extends Command
{
    use InteractsWithPipelines, LaraKubeOutput;

    protected $signature = 'pipeline:show
        {environment? : Target environment to show the workflow for}';

    protected $description = 'Show structural layout and required secrets of a workflow';

    public function handle(): int
    {
        $this->renderHeader();

        $workflows = $this->discoverWorkflows(getcwd());

        if (empty($workflows)) {
            $this->warn('  No LaraKube workflows/pipelines found in this project.');

            return 1;
        }

        $environment = $this->argument('environment');
        if (! $environment) {
            $envs = array_unique(array_column($workflows, 'env'));
            $environment = select(
                label: 'Which environment workflow do you want to show?',
                options: array_combine($envs, $envs),
            );
        }

        $matches = array_filter($workflows, fn ($w) => $w['env'] === $environment || ($w['platform'] === 'gitlab' && $environment === 'all'));

        if (empty($matches)) {
            $this->laraKubeError("No workflow found matching environment '{$environment}'.");

            return 1;
        }

        // If multiple matches (e.g. both GitHub and Forgejo exist for this env), let the user choose
        $selected = reset($matches);
        if (count($matches) > 1) {
            $options = [];
            foreach ($matches as $idx => $m) {
                $options[$idx] = "{$m['platform']} ({$m['file']})";
            }
            $choice = select(
                label: 'Multiple workflows found. Select one to show:',
                options: $options,
            );
            $selected = $matches[$choice];
        }

        $path = getcwd().'/'.$selected['file'];
        try {
            $yaml = Yaml::parseFile($path);
        } catch (Exception $e) {
            $this->laraKubeError("Failed to parse YAML file '{$selected['file']}': ".$e->getMessage());

            return 1;
        }

        $platformName = match ($selected['platform']) {
            'github' => 'GitHub Actions',
            'forgejo' => 'Forgejo Actions',
            'gitlab' => 'GitLab CI/CD',
            default => ucfirst($selected['platform']),
        };

        $this->laraKubeInfo("Pipeline Layout: {$platformName} ({$selected['file']})");
        $this->newLine();

        $trigger = $this->parseWorkflowTrigger($path);
        $this->line("  <fg=gray>Trigger Event:</>        <fg=green>{$trigger}</>");

        $secrets = $this->extractSecretsFromYaml($yaml);
        if (! empty($secrets)) {
            $this->line('  <fg=gray>Required Secrets:</>     <fg=cyan>'.implode(', ', $secrets).'</>');
        } else {
            $this->line('  <fg=gray>Required Secrets:</>     <fg=gray>None</>');
        }

        $this->newLine();

        // Print jobs and steps
        if ($selected['platform'] === 'gitlab') {
            // GitLab YAML structure
            $this->line('  <fg=yellow;options=bold>Jobs by Stage:</>');

            foreach ($yaml as $key => $job) {
                if (is_array($job) && (isset($job['script']) || isset($job['stage']))) {
                    $stage = $job['stage'] ?? 'deploy';
                    $this->line("    <fg=magenta>● {$key}</> <fg=gray>(stage: {$stage})</>");
                    $scripts = (array) ($job['script'] ?? []);
                    foreach ($scripts as $idx => $script) {
                        $this->line('      <fg=gray>Step '.($idx + 1).':</> '.trim($script));
                    }
                }
            }
        } else {
            // GitHub / Forgejo Actions YAML structure
            $jobs = $yaml['jobs'] ?? [];
            $this->line('  <fg=yellow;options=bold>Jobs:</>');

            foreach ($jobs as $jobKey => $job) {
                $jobName = $job['name'] ?? $jobKey;
                $runsOn = $job['runs-on'] ?? 'ubuntu-latest';
                $this->line("    <fg=magenta>● {$jobName}</> <fg=gray>(runs-on: {$runsOn})</>");

                $steps = $job['steps'] ?? [];
                foreach ($steps as $idx => $step) {
                    $stepName = $step['name'] ?? ($step['uses'] ?? ($step['run'] ?? 'Step'));
                    $stepName = explode("\n", trim($stepName))[0]; // first line only if multiline run command
                    $this->line('      <fg=gray>Step '.($idx + 1).':</> '.$stepName);
                }
            }
        }

        $this->newLine();

        return 0;
    }
}
