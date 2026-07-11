<?php

namespace App\Commands\Pipeline;

use App\Traits\InteractsWithPipelines;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class PipelineListCommand extends Command
{
    use InteractsWithPipelines, LaraKubeOutput;

    protected $signature = 'pipeline:list';

    protected $description = 'List all generated CI/CD pipelines and workflows';

    public function handle(): int
    {
        $this->renderHeader();

        $workflows = $this->discoverWorkflows(getcwd());

        if (empty($workflows)) {
            $this->warn('  No LaraKube workflows/pipelines found in this project.');
            $this->line('  Run <fg=yellow>larakube cloud:configure</> to configure an environment and generate one.');

            return 0;
        }

        $rows = [];
        foreach ($workflows as $w) {
            $platform = match ($w['platform']) {
                'github' => 'GitHub Actions',
                'gitea' => 'Gitea Actions',
                'gitlab' => 'GitLab CI/CD',
                default => ucfirst($w['platform']),
            };

            $trigger = $this->parseWorkflowTrigger(getcwd().'/'.$w['file']);

            $rows[] = [
                $platform,
                $w['file'],
                $w['env'],
                $trigger,
            ];
        }

        table(['Platform', 'Pipeline File', 'Environment', 'Trigger'], $rows);

        return 0;
    }
}
