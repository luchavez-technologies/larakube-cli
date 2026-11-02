<?php

namespace App\Traits;

use App\Data\ResourceRef;
use App\Data\ToolInstance;
use App\Enums\SecretKind;
use App\Services\Kubectl;

/**
 * The custom-env overlay `tool:env`/`tool:env:set` share: one ConfigMap +
 * one Secret per tool instance, named via SecretKind::CUSTOM, holding ONLY
 * vars a person added themselves — never a key a tool's own `:init`/`:wire`
 * logic manages. Wired into the Deployment's envFrom lazily, the first time
 * a var is set (Kubectl::ensureDeploymentEnvFrom()), so a tool nobody has
 * customized yet carries no extra resources at all.
 */
trait ManagesToolCustomEnv
{
    use SyncsClusterSecrets;

    protected function customEnvConfigMapName(ToolInstance $names): string
    {
        return $names->configMap('custom');
    }

    protected function customEnvSecretName(ToolInstance $names): string
    {
        return $names->secret(SecretKind::CUSTOM);
    }

    /** Null when the ConfigMap doesn't exist yet. @return array<string, string>|null */
    protected function readCustomConfigMap(Kubectl $kubectl, string $namespace, string $name): ?array
    {
        $resource = $kubectl->get(new ResourceRef('ConfigMap', $name, $namespace));

        return $resource === null ? null : (array) ($resource['data'] ?? []);
    }

    /** Null when the Secret doesn't exist yet. @return array<string, string>|null */
    protected function readCustomSecret(Kubectl $kubectl, string $namespace, string $name): ?array
    {
        $resource = $kubectl->get(new ResourceRef('Secret', $name, $namespace));
        if ($resource === null) {
            return null;
        }

        $data = (array) ($resource['data'] ?? []);

        return array_map(fn (string $value): string => (string) base64_decode($value, true), $data);
    }

    /**
     * Apply a batch of changes to the custom env overlay: upserts, secret
     * upserts, and removals. A key moved between $set and $setSecret (or
     * removed) is dropped from whichever side it previously lived on, so it
     * never lingers readable on the wrong side.
     *
     * Creates the ConfigMap/Secret on first use (`putConfigMap`/`putSecret`);
     * every later call only patches the delta (`patchConfigMap`/
     * `patchSecret`), leaving keys this call doesn't mention untouched.
     *
     * @param  array<string, string>  $set
     * @param  array<string, string>  $setSecret
     * @param  list<string>  $unset
     * @return array{configMap: string, secret: string, changed: bool}
     */
    protected function applyCustomEnvChanges(
        Kubectl $kubectl,
        ToolInstance $names,
        array $set,
        array $setSecret,
        array $unset,
    ): array {
        $namespace = $names->namespace();
        $configMapName = $this->customEnvConfigMapName($names);
        $secretName = $this->customEnvSecretName($names);
        $labels = $names->labels();

        $existingConfigMap = $kubectl->get(new ResourceRef('ConfigMap', $configMapName, $namespace));
        $existingSecret = $kubectl->get(new ResourceRef('Secret', $secretName, $namespace));
        $existingConfigMapKeys = $existingConfigMap !== null ? array_keys((array) ($existingConfigMap['data'] ?? [])) : [];
        $existingSecretKeys = $existingSecret !== null ? array_keys((array) ($existingSecret['data'] ?? [])) : [];

        // Moving a key to the other side, or removing it outright, deletes
        // it from wherever it currently lives — a key only ever lives on one
        // side at a time.
        $configMapRemovals = array_unique([...array_keys($setSecret), ...$unset]);
        $secretRemovals = array_unique([...array_keys($set), ...$unset]);

        $configMapPatch = [
            ...$set,
            ...array_fill_keys(array_intersect($configMapRemovals, $existingConfigMapKeys), null),
        ];
        $secretPatch = [
            ...$setSecret,
            ...array_fill_keys(array_intersect($secretRemovals, $existingSecretKeys), null),
        ];

        if ($configMapPatch !== []) {
            if ($existingConfigMap === null) {
                $kubectl->putConfigMap($namespace, $configMapName, array_filter($configMapPatch, fn (?string $v) => $v !== null), $labels);
            } else {
                $kubectl->patchConfigMap($namespace, $configMapName, $configMapPatch);
            }
        }

        if ($secretPatch !== []) {
            if ($existingSecret === null) {
                $kubectl->putSecret($namespace, $secretName, array_filter($secretPatch, fn (?string $v) => $v !== null), $labels);
            } else {
                $kubectl->patchSecret($namespace, $secretName, $secretPatch);
            }
        }

        return [
            'configMap' => $configMapName,
            'secret' => $secretName,
            'changed' => $configMapPatch !== [] || $secretPatch !== [],
        ];
    }

    /**
     * Idempotently wire the custom ConfigMap/Secret into the instance's
     * Deployment envFrom — a no-op once they're already referenced.
     */
    protected function ensureCustomEnvWired(Kubectl $kubectl, ToolInstance $names, string $configMapName, string $secretName): bool
    {
        return $kubectl->ensureDeploymentEnvFrom(
            $names->namespace(),
            $names->deployment(),
            [
                ['kind' => 'configMapRef', 'name' => $configMapName],
                ['kind' => 'secretRef', 'name' => $secretName],
            ],
        )->ok;
    }
}
