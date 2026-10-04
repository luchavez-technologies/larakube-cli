<?php

namespace App\Commands\Project;

use App\Data\ConfigData;
use App\Services\Kubectl;
use App\Traits\EmitsJsonOutput;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;
use Throwable;

/**
 * The LaraKube projects in one folder (each a folder with a .larakube.json), with their
 * environments and whether the local one is running. It is how a GUI sees the projects on
 * a machine that is not its own, such as a dev box, without keeping a list of them.
 */
class ProjectListCommand extends Command
{
    use EmitsJsonOutput, LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'project:list
        {--path= : The folder that holds the projects. Default is ~/projects}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'List the LaraKube projects in a folder';

    public function handle(): int
    {
        $root = rtrim((string) ($this->flag('path') ?: home_path('projects')), '/');
        $running = $this->namespacesWithRunningPods();
        $projects = [];

        foreach (glob($root.'/*/.larakube.json') ?: [] as $file) {
            $project = $this->describe(dirname($file), $running);

            if ($project !== null) {
                $projects[] = $project;
            }
        }

        usort($projects, fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'path' => $root, 'projects' => $projects]);

            return 0;
        }

        if ($projects === []) {
            $this->laraKubeInfo("No LaraKube projects in {$root}.");

            return 0;
        }

        table(
            headers: ['Name', 'Framework', 'Environments', 'Local'],
            rows: array_map(fn (array $p): array => [$p['name'], $p['framework'] ?? '—', implode(', ', array_column($p['environments'], 'name')), $p['local']], $projects),
        );

        return 0;
    }

    /**
     * @param  array<string, true>  $running
     * @return array<string, mixed>|null null when the blueprint cannot be read
     */
    private function describe(string $path, array $running): ?array
    {
        // loadFromFile() answers an unreadable blueprint with a blank config, which would list a project that is not one.
        if (! is_array(json_decode((string) @file_get_contents($path.'/.larakube.json'), true))) {
            return null;
        }

        try {
            $config = ConfigData::loadFromFile($path);
        } catch (Throwable) {
            return null;
        }

        $environments = [];
        foreach (array_keys($config->environments) as $environment) {
            $host = $config->getWebHost($environment);
            $environments[] = ['name' => (string) $environment, 'host' => $host !== '' ? $host : null];
        }

        $namespace = $config->getNamespace('local');

        return [
            'name' => $config->getName(),
            'path' => $path,
            'framework' => $config->framework?->value,
            'environments' => $environments,
            'local' => isset($running[$namespace]) ? 'running' : 'stopped',
        ];
    }

    /**
     * Namespaces that have a Running pod, from one call to the cluster. Empty when there is none or
     * it cannot be reached, so every project then reads as stopped.
     *
     * @return array<string, true>
     */
    private function namespacesWithRunningPods(): array
    {
        $pods = Kubectl::current()->raw(['get', 'pods', '-A', '-o', 'json', '--request-timeout=8s'])->json()['items'] ?? [];
        $namespaces = [];

        foreach ($pods as $pod) {
            if (($pod['status']['phase'] ?? null) === 'Running') {
                $namespaces[(string) ($pod['metadata']['namespace'] ?? '')] = true;
            }
        }

        return $namespaces;
    }
}
