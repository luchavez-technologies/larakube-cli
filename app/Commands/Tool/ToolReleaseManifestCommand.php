<?php

namespace App\Commands\Tool;

use App\Enums\ClusterTool;
use App\Support\Templates\ReleaseManifestBuilder;
use App\Traits\EmitsJsonOutput;
use App\Traits\FailsWithJson;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;
use RuntimeException;

/**
 * Describe one Cluster Tool's template release as JSON so LaraKube Cloud can import it.
 */
class ToolReleaseManifestCommand extends Command
{
    use EmitsJsonOutput, FailsWithJson, LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'tool:release-manifest
        {tool : The tool slug, e.g. openbao}
        {--engine= : The engine, for multi-engine tools}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = "Describe a Cluster Tool's template release as JSON for LaraKube Cloud import";

    public function handle(ReleaseManifestBuilder $builder): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $toolSlug = strtolower(trim((string) $this->argument('tool')));
        $tool = ClusterTool::tryFrom($toolSlug);

        if ($tool === null) {
            return $this->failed("Unknown tool '{$toolSlug}'.");
        }

        $engines = $tool->engines();
        $engineOption = trim((string) ($this->option('engine') ?? ''));

        if ($engineOption !== '') {
            $isValidEngine = $engines === []
                ? $engineOption === 'default'
                : array_key_exists($engineOption, $engines);

            if (! $isValidEngine) {
                return $this->failed("Unknown engine '{$engineOption}' for tool '{$tool->value}'.");
            }

            $engine = $engineOption;
        } else {
            $engine = $tool->defaultEngine();
        }

        try {
            $payload = $builder->describe($tool, $engine);
        } catch (RuntimeException $e) {
            return $this->failed($e->getMessage());
        }

        if ($this->flag('json')) {
            $this->jsonOutput($payload);

            return self::SUCCESS;
        }

        $this->renderHeader();

        table(
            headers: ['Service', 'Role'],
            rows: array_map(
                static fn (array $service): array => [$service['key'], $service['role']],
                $payload['services'],
            ),
        );

        $this->line(sprintf(
            '  %d service(s), %d image(s), %d requirement(s), %d file(s)',
            count($payload['services']),
            count((array) $payload['images']),
            count($payload['requirements']),
            count($payload['files']),
        ));

        return self::SUCCESS;
    }
}
