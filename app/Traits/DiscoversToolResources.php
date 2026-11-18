<?php

namespace App\Traits;

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Services\Kubectl;

/**
 * Every ConfigMap and Secret one Cluster Tool instance uses, found from what
 * its workloads actually reference (env, envFrom, volumes) plus anything
 * carrying the instance's labels. References are the source of truth: a
 * Secret a wire command wrote is found whether or not it is labelled.
 *
 * Each key is tagged with who owns it:
 *  - `rotated`: an ExternalSecret maps it, so OpenBao rewrites it;
 *  - `custom`: the user's own overlay, editable with `tool:env:set`;
 *  - `managed`: written by the tool's `:init` or a `:wire` command.
 *
 * Needs DeploysClusterTool, InteractsWithToolRegistry and ManagesToolCustomEnv.
 */
trait DiscoversToolResources
{
    /**
     * The instance a `--tool`/`--domain` pair names, or an error message.
     */
    protected function resolveResourceInstance(string $kubectl): ToolInstance|string
    {
        $toolSlug = (string) ($this->option('tool') ?? '');
        $tool = ClusterTool::tryFrom($toolSlug);

        if ($tool === null) {
            return "Unknown tool '{$toolSlug}'. Pass --tool=<name>.";
        }

        $instancePreference = $this->resolveInstanceForTool($tool, $kubectl, (string) ($this->option('domain') ?: ''));
        if ($instancePreference === false) {
            return "Could not pick an instance of '{$tool->value}'. Pass --domain=<host>.";
        }

        $entry = $this->findToolInstanceEntry($kubectl, $tool, $instancePreference);
        if ($entry === null) {
            return "'{$tool->value}' is not registered on this cluster.";
        }

        return ToolInstance::forInstance($tool, (string) ($entry['instance'] ?? ''), (string) ($entry['engine'] ?? '') ?: null);
    }

    /**
     * `custom` marks the user's own overlay (a ConfigMap and a Secret per
     * instance), which exists before anything is set in it.
     *
     * @return list<array{kind: string, name: string, custom: bool, keys: list<array{key: string, owner: string, value?: string}>}>
     */
    protected function toolResources(Kubectl $kubectl, ToolInstance $names): array
    {
        $namespace = $names->namespace();
        $selector = $names->selector();

        $workloads = $kubectl->raw(['get', 'deployment,statefulset,daemonset', '-n', $namespace, '-l', $selector, '-o', 'json'])->json()['items'] ?? [];
        $referenced = ['ConfigMap' => [], 'Secret' => []];
        foreach ($workloads as $workload) {
            foreach ($this->podSpecReferences($workload['spec']['template']['spec'] ?? []) as [$kind, $name]) {
                $referenced[$kind][$name] = true;
            }
        }

        $objects = [];
        foreach ($kubectl->raw(['get', 'configmap,secret', '-n', $namespace, '-l', $selector, '-o', 'json'])->json()['items'] ?? [] as $item) {
            $objects[($item['kind'] ?? '').'/'.($item['metadata']['name'] ?? '')] = $item;
        }

        foreach (['ConfigMap' => 'configmap', 'Secret' => 'secret'] as $kind => $resource) {
            $missing = array_values(array_filter(array_keys($referenced[$kind]), fn (string $name): bool => ! isset($objects["{$kind}/{$name}"])));
            if ($missing === []) {
                continue;
            }

            $fetched = $kubectl->raw(['get', $resource, ...$missing, '-n', $namespace, '-o', 'json', '--ignore-not-found'])->json();
            $items = $fetched === null ? [] : (isset($fetched['items']) ? $fetched['items'] : [$fetched]);
            foreach ($items as $item) {
                $objects[$kind.'/'.($item['metadata']['name'] ?? '')] = $item + ['kind' => $kind];
            }
        }

        $rotated = $this->externalSecretKeys($kubectl, $namespace);
        $customNames = [$this->customEnvConfigMapName($names), $this->customEnvSecretName($names)];

        $resources = [];
        foreach ($objects as $object) {
            $kind = (string) ($object['kind'] ?? '');
            $name = (string) ($object['metadata']['name'] ?? '');

            if ($name === '' || ($kind === 'Secret' && ($object['type'] ?? 'Opaque') === 'kubernetes.io/service-account-token')) {
                continue;
            }

            $keys = [];
            $data = (array) ($object['data'] ?? []);
            $keyNames = array_merge(array_keys($data), array_keys((array) ($object['binaryData'] ?? [])));
            sort($keyNames);

            foreach ($keyNames as $key) {
                $owner = match (true) {
                    in_array($name, $customNames, true) => 'custom',
                    ($rotated[$name] ?? null) === true, isset($rotated[$name][$key]) => 'rotated',
                    default => 'managed',
                };

                $row = ['key' => (string) $key, 'owner' => $owner];
                if ($kind === 'ConfigMap' && array_key_exists($key, $data)) {
                    $row['value'] = (string) $data[$key];
                }
                $keys[] = $row;
            }

            $resources[] = ['kind' => $kind, 'name' => $name, 'custom' => in_array($name, $customNames, true), 'keys' => $keys];
        }

        usort($resources, fn (array $a, array $b): int => [$a['kind'], $a['name']] <=> [$b['kind'], $b['name']]);

        return $resources;
    }

    /**
     * The ConfigMaps and Secrets a pod spec reads from.
     *
     * @param  array<string, mixed>  $podSpec
     * @return list<array{0: string, 1: string}>
     */
    private function podSpecReferences(array $podSpec): array
    {
        $refs = [];

        foreach ([...($podSpec['containers'] ?? []), ...($podSpec['initContainers'] ?? [])] as $container) {
            foreach ($container['envFrom'] ?? [] as $source) {
                if (isset($source['configMapRef']['name'])) {
                    $refs[] = ['ConfigMap', (string) $source['configMapRef']['name']];
                }
                if (isset($source['secretRef']['name'])) {
                    $refs[] = ['Secret', (string) $source['secretRef']['name']];
                }
            }

            foreach ($container['env'] ?? [] as $env) {
                if (isset($env['valueFrom']['configMapKeyRef']['name'])) {
                    $refs[] = ['ConfigMap', (string) $env['valueFrom']['configMapKeyRef']['name']];
                }
                if (isset($env['valueFrom']['secretKeyRef']['name'])) {
                    $refs[] = ['Secret', (string) $env['valueFrom']['secretKeyRef']['name']];
                }
            }
        }

        foreach ($podSpec['volumes'] ?? [] as $volume) {
            if (isset($volume['configMap']['name'])) {
                $refs[] = ['ConfigMap', (string) $volume['configMap']['name']];
            }
            if (isset($volume['secret']['secretName'])) {
                $refs[] = ['Secret', (string) $volume['secret']['secretName']];
            }
            foreach ($volume['projected']['sources'] ?? [] as $source) {
                if (isset($source['configMap']['name'])) {
                    $refs[] = ['ConfigMap', (string) $source['configMap']['name']];
                }
                if (isset($source['secret']['name'])) {
                    $refs[] = ['Secret', (string) $source['secret']['name']];
                }
            }
        }

        return $refs;
    }

    /**
     * Target Secret name => the keys an ExternalSecret writes into it, or true
     * when it maps the whole remote secret (dataFrom). A namespace without the
     * ExternalSecret CRD has none.
     *
     * @return array<string, array<string, true>|true>
     */
    private function externalSecretKeys(Kubectl $kubectl, string $namespace): array
    {
        $owned = [];

        foreach ($kubectl->raw(['get', 'externalsecret', '-n', $namespace, '-o', 'json'])->json()['items'] ?? [] as $externalSecret) {
            $target = (string) ($externalSecret['spec']['target']['name'] ?? $externalSecret['metadata']['name'] ?? '');
            if ($target === '') {
                continue;
            }

            if (! empty($externalSecret['spec']['dataFrom'])) {
                $owned[$target] = true;

                continue;
            }

            foreach ($externalSecret['spec']['data'] ?? [] as $mapping) {
                if (isset($mapping['secretKey']) && ($owned[$target] ?? null) !== true) {
                    $owned[$target][(string) $mapping['secretKey']] = true;
                }
            }
        }

        return $owned;
    }
}
