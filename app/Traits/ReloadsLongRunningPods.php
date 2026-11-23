<?php

namespace App\Traits;

use App\Contracts\HasReloadCommand;
use App\Data\ConfigData;
use App\Facades\State;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;

/**
 * Restarts the long-running PHP processes that keep old code in memory
 * (Horizon, queue workers, Octane, Reverb) without dropping work: each one's
 * own graceful command, run inside its pod. Shared by `reload` and `watch`.
 */
trait ReloadsLongRunningPods
{
    use InteractsWithEnvironments, ResolvesEnvironmentContext, StreamsProcessOutput;

    /**
     * What a project has to reload: pod name => graceful reload command.
     *
     * @return array<string, string>
     */
    public static function reloadTargets(ConfigData $config): array
    {
        $targets = [];

        foreach ([$config->getServerVariation(), ...$config->getFeatures()] as $candidate) {
            if (! $candidate instanceof HasReloadCommand) {
                continue;
            }

            $command = $candidate->getReloadCommand();
            if ($command !== null) {
                $targets[$candidate->getPodName($config)] = $command;
            }
        }

        return $targets;
    }

    /**
     * A target's pods: those labelled `app=<service>` (or `laravel-<service>`,
     * and `queue` for queues). Running pods first; any pod at all as a fallback
     * (one mid-restart still takes the command once it is up).
     *
     * @param  list<array{name: string, app: string, phase: string}>  $pods
     * @return list<string>
     */
    public static function podsFor(string $service, array $pods): array
    {
        $apps = [$service, "laravel-{$service}"];
        if ($service === 'queues') {
            $apps = [...$apps, 'queue', 'laravel-queue'];
        }

        foreach ([true, false] as $runningOnly) {
            foreach ($apps as $app) {
                $names = array_values(array_map(
                    fn (array $pod): string => $pod['name'],
                    array_filter($pods, fn (array $pod): bool => $pod['app'] === $app && (! $runningOnly || $pod['phase'] === 'Running')),
                ));

                if ($names !== []) {
                    return $names;
                }
            }
        }

        return [];
    }

    /**
     * Reload every long-running process the project has in an environment, all
     * at once: one pod lookup, then each pod's reload command in parallel.
     *
     * @return list<array{target: string, pods: list<string>, ok: bool, error: string|null}>
     */
    protected function reloadLongRunningPods(ConfigData $config, string $environment): array
    {
        // The env's own context (local → current context, unchanged).
        return $this->reloadTargetsIn(self::reloadTargets($config), $this->getNamespace($environment), $this->environmentKubectl($config, $environment));
    }

    /**
     * @param  array<string, string>  $targets  pod name => reload command
     * @return list<array{target: string, pods: list<string>, ok: bool, error: string|null}>
     */
    protected function reloadTargetsIn(array $targets, string $namespace, string $kubectl): array
    {
        $pods = $this->listPods($namespace, $kubectl);
        $found = [];
        $commands = [];

        foreach ($targets as $service => $command) {
            $found[$service] = self::podsFor($service, $pods);

            if ($found[$service] === []) {
                $this->laraKubeWarn("Pod for '{$service}' not found in '{$namespace}'. Skipping.");

                continue;
            }

            foreach ($found[$service] as $podName) {
                $this->laraKubeInfo("↻ {$service} ({$podName}): {$command}");
                $commands["{$service}|{$podName}"] = "{$kubectl} exec -n {$namespace} -c php {$podName} -- {$command}";
            }
        }

        $finished = $commands === [] ? [] : Process::pool(function (Pool $pool) use ($commands): void {
            foreach ($commands as $key => $command) {
                $pool->as($key)->forever()->command($command);
            }
        })->start()->wait();

        $results = [];

        foreach ($targets as $service => $command) {
            if ($found[$service] === []) {
                $results[] = ['target' => $service, 'pods' => [], 'ok' => false, 'error' => "No {$service} pod found in {$namespace}."];

                continue;
            }

            $error = null;

            foreach ($found[$service] as $podName) {
                $result = $finished["{$service}|{$podName}"];
                $output = $result->output().$result->errorOutput();
                $this->writeReloadOutput($output);

                if (! $result->successful()) {
                    $lines = array_values(array_filter(array_map(trim(...), explode("\n", $output))));
                    $error = $lines === [] ? "{$command} exited with {$result->exitCode()}." : (string) end($lines);
                }
            }

            $results[] = ['target' => $service, 'pods' => $found[$service], 'ok' => $error === null, 'error' => $error];
        }

        return $results;
    }

    /**
     * @return list<array{name: string, app: string, phase: string}>
     */
    private function listPods(string $namespace, string $kubectl): array
    {
        $items = json_decode(Process::run("{$kubectl} get pods -n {$namespace} -o json")->output(), true)['items'] ?? [];
        $pods = [];

        foreach (is_array($items) ? $items : [] as $item) {
            $name = $item['metadata']['name'] ?? null;
            $app = $item['metadata']['labels']['app'] ?? null;

            if (is_string($name) && is_string($app)) {
                $pods[] = ['name' => $name, 'app' => $app, 'phase' => (string) ($item['status']['phase'] ?? '')];
            }
        }

        return $pods;
    }

    private function writeReloadOutput(string $output): void
    {
        if (trim($output) !== '') {
            State::isJsonMode() ? State::writeStderr($output) : State::writeStdout($output);
        }
    }
}
