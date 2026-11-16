<?php

namespace App\Commands\Tool;

use App\Enums\ClusterTool;
use App\Services\Kubectl;
use App\Services\Tools\InitOption;
use App\Services\Tools\ToolInitSpec;
use App\Traits\InteractsWithToolRevisions;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesStandaloneEnvironment;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;
use Throwable;

/**
 * Brings installed Cluster Tools up to this CLI build: re-runs each one's
 * `:init` (idempotent: PVCs, secrets and Commons tenants are kept) with the
 * options it was installed with. That applies new image pins, manifest
 * changes and new label keys alike.
 *
 * An instance installed before options were recorded falls back on what a
 * re-run can still find out: its host, `proxied`, `noPlex` and admin email
 * from the registry, and `vpn-only` from its live Ingress, so a VPN-only tool
 * never comes back public.
 */
class ToolUpdateCommand extends Command
{
    use InteractsWithToolRevisions, LaraKubeOutput, ResolvesStandaloneEnvironment;

    protected $signature = 'tool:update
        {environment? : The environment the tools run in}
        {--context= : Target a specific kube-context}
        {--tool=    : The tool to update (e.g. n8n)}
        {--domain=  : With --tool, the instance\'s host, when the tool has several}
        {--all      : Update every tool that tool:outdated lists}
        {--dry-run  : Print each tool:init this would run, and run nothing}
        {--json     : Emit one machine-readable JSON summary on stdout}';

    protected $description = 'Re-apply installed cluster tools with this CLI build, keeping their options';

    public function handle(): int
    {
        $all = (bool) $this->option('all');
        $toolOption = (string) $this->option('tool');

        if ($all === ($toolOption !== '')) {
            $this->laraKubeError('Pass either --tool=NAME or --all.');

            return self::FAILURE;
        }

        if (! $this->option('json')) {
            $this->renderHeader();
        }

        [, $kubectl] = $this->resolveStandaloneEnvironmentAndKubectl();
        $targets = $this->targets($this->outdatedRows($kubectl), $all, $toolOption, (string) $this->option('domain'));

        if ($targets === null) {
            return self::FAILURE;
        }

        if ($targets === []) {
            $this->option('json')
                ? $this->line('[]')
                : $this->laraKubeInfo('Every installed tool is up to date.');

            return self::SUCCESS;
        }

        $results = [];

        foreach ($targets as $row) {
            $tool = ClusterTool::from($row['tool']);
            $parameters = $this->initParameters($tool, $row, $kubectl);

            if ($this->option('dry-run')) {
                $results[] = $this->result($row, 'planned', $this->describe($parameters));

                continue;
            }

            if (! $this->option('json')) {
                $this->newLine();
                $this->laraKubeInfo("Updating {$row['label']} ({$row['host']})...");
            }

            // One instance's failure (a missing flag, an unreachable service) must not
            // abort the rest of an --all run.
            try {
                $exit = $this->call('tool:init', $parameters);
                $results[] = $this->result($row, $exit === self::SUCCESS ? 'updated' : 'failed', $this->describe($parameters));
            } catch (Throwable $e) {
                $this->laraKubeError("{$row['label']} ({$row['host']}): {$e->getMessage()}");
                $results[] = $this->result($row, 'failed', $this->describe($parameters), $e->getMessage());
            }
        }

        return $this->report($results);
    }

    /**
     * The rows to update, or null after printing why the request can't be served.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return ?list<array<string, mixed>>
     */
    private function targets(array $rows, bool $all, string $toolOption, string $domain): ?array
    {
        if ($all) {
            return array_values(array_filter($rows, fn (array $row): bool => $row['status'] !== 'current'));
        }

        $tool = ClusterTool::tryFrom($toolOption);

        if ($tool === null) {
            $this->laraKubeError("Unknown tool '{$toolOption}'.");

            return null;
        }

        // An explicit --tool re-applies even an up-to-date instance: that's
        // how to repair drift on a live cluster without guessing its options.
        $matches = array_values(array_filter($rows, fn (array $row): bool => $row['tool'] === $tool->value
            && ($domain === '' || $row['host'] === $domain)));

        if ($matches === []) {
            $this->laraKubeError("{$tool->brandName()} isn't installed".($domain !== '' ? " at {$domain}" : '').'.');

            return null;
        }

        if (count($matches) > 1) {
            $this->laraKubeError("{$tool->brandName()} has several instances. Pick one with --domain=: "
                .implode(', ', array_column($matches, 'host')));

            return null;
        }

        return $matches;
    }

    /**
     * Arguments for `tool:init`: the recorded options when there are any,
     * otherwise the fallbacks, then the target and non-interactive mechanics.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function initParameters(ClusterTool $tool, array $row, string $kubectl): array
    {
        $accepted = array_map(fn (InitOption $option): string => $option->name, ToolInitSpec::for($tool));
        $options = $row['installOptions'] ?? $this->fallbackOptions($tool, $row, $kubectl);
        ksort($options);

        if (($row['host'] ?? null) !== null) {
            $options['domain'] = $row['host'];
        }

        $parameters = [];

        if ($this->argument('environment') !== null) {
            $parameters['environment'] = $this->argument('environment');
        }

        $parameters['--tool'] = $tool->value;

        foreach ($options as $name => $value) {
            if (in_array($name, $accepted, true)) {
                $parameters['--'.$name] = $value;
            }
        }

        if ((string) $this->option('context') !== '') {
            $parameters['--context'] = (string) $this->option('context');
        }

        $parameters['--force'] = true;
        $parameters['--no-interaction'] = true;

        return $parameters;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function fallbackOptions(ClusterTool $tool, array $row, string $kubectl): array
    {
        $registered = $row['registered'] ?? [];
        $options = [];

        if (($registered['noPlex'] ?? false) === true) {
            $options['no-plex'] = true;
        }

        if (($registered['proxied'] ?? false) === true) {
            $options['proxied'] = true;
        }

        if (isset($registered['adminEmail']) && $registered['adminEmail'] !== '') {
            $options['admin-email'] = $registered['adminEmail'];
        }

        if (($row['host'] ?? null) !== null && $this->ingressIsVpnOnly($tool, (string) $row['host'], $kubectl)) {
            $options['vpn-only'] = true;
        }

        return $options;
    }

    /** Whether the live Ingress serving $host is restricted to the VPN. */
    private function ingressIsVpnOnly(ClusterTool $tool, string $host, string $kubectl): bool
    {
        $result = Kubectl::fromPrefix($kubectl)->raw(['get', 'ingress', '-n', $tool->namespace(), '-o', 'json']);

        foreach ($result->ok ? ($result->json()['items'] ?? []) : [] as $ingress) {
            $hosts = array_column($ingress['spec']['rules'] ?? [], 'host');

            if (in_array($host, $hosts, true)) {
                return str_contains((string) json_encode($ingress['metadata']['annotations'] ?? []), 'vpn-only');
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $parameters */
    private function describe(array $parameters): string
    {
        $parts = ['larakube tool:init'];

        foreach ($parameters as $key => $value) {
            if ($key === '--no-interaction') {
                continue;
            }

            $parts[] = match (true) {
                $key === 'environment' => (string) $value,
                $value === true => $key,
                is_array($value) => implode(' ', array_map(fn (mixed $v): string => "{$key}={$v}", $value)),
                default => "{$key}={$value}",
            };
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function result(array $row, string $outcome, string $command, ?string $error = null): array
    {
        return [
            'tool' => $row['tool'],
            'label' => $row['label'],
            'host' => $row['host'],
            'was' => $row['status'],
            'outcome' => $outcome,
            'command' => $command,
            ...($error !== null ? ['error' => $error] : []),
        ];
    }

    /** @param  list<array<string, mixed>>  $results */
    private function report(array $results): int
    {
        $failed = count(array_filter($results, fn (array $r): bool => $r['outcome'] === 'failed'));

        if ($this->option('json')) {
            $this->line((string) json_encode($results, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $failed > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->newLine();

        if ($this->option('dry-run')) {
            foreach ($results as $result) {
                $this->line("  {$result['command']}");
            }

            $this->newLine();
            $this->line('  <fg=gray>Dry run: nothing was applied.</>');

            return self::SUCCESS;
        }

        table(
            headers: ['Tool', 'Host', 'Result'],
            rows: array_map(fn (array $r): array => [$r['label'], $r['host'] ?? '—', $r['outcome'] === 'updated' ? 'Updated' : 'Failed'], $results),
        );

        if ($failed > 0) {
            $this->laraKubeError("{$failed} of ".count($results).' failed. Their output is above; re-run with --tool= once fixed.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
