<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Enums\LaravelFeature;

/**
 * Compares a pre-wizard ConfigData snapshot against the post-wizard result
 * and replays each change through the same add/remove/update methods
 * `larakube add`/`larakube remove` use, so a re-run of `larakube init` gets
 * identical side effects (K8s updates, .env sync, swap/migrate-first
 * confirms, fallback-promotion) instead of a blind config overwrite.
 */
trait DiffsProjectConfig
{
    use ManagesArchitecturalComponents;

    /**
     * @return array<string, array{old?: mixed, new?: mixed, changed?: bool, add?: array, remove?: array}>
     */
    protected function diffConfigs(ConfigData $old, ConfigData $new): array
    {
        return [
            'blueprints' => $this->diffList($old->getBlueprints(), $new->getBlueprints()),
            'features' => $this->diffList($old->getFeatures(), $new->getFeatures()),
            'database' => $this->diffScalar($old->getDatabase(), $new->getDatabase()),
            // Raw nullable properties, not the defaulting getCacheDriver() getter
            // (which substitutes the "database" cache driver for null) — a
            // genuine null→null vs value→value distinction would otherwise be lost.
            'cache' => $this->diffScalar($old->cacheDriver, $new->cacheDriver),
            'storage' => $this->diffScalar($old->objectStorage, $new->objectStorage),
            'scout' => $this->diffScalar($old->scoutDriver, $new->scoutDriver),
            'phpVersion' => $this->diffScalar($old->getPhpVersion(), $new->getPhpVersion()),
            'os' => $this->diffScalar($old->getOs(), $new->getOs()),
            'serverVariation' => $this->diffScalar($old->getServerVariation(), $new->getServerVariation()),
        ];
    }

    /**
     * Apply a diff produced by diffConfigs(). Per-item confirms are suppressed
     * throughout (skipConfirm: true) since the caller already confirmed the
     * whole batch once via the diff preview — see InitCommand::handle().
     */
    protected function replayDiff(array $diff, ConfigData $config, bool $install): void
    {
        // 1. Blueprint removals first, then 2. feature removals. Scout-driver
        // clearing (when the SCOUT feature itself is removed) already happened
        // upstream in gatherConfig(), so removeFeature()'s own Scout-clearing
        // branch is a no-op here — no double-apply.
        foreach ($diff['blueprints']['remove'] as $blueprint) {
            $this->removeBlueprint($blueprint, $config);
        }

        foreach ($diff['features']['remove'] as $feature) {
            $this->removeFeature($feature, $config, skipConfirm: true);
        }

        // 3. Database swap (DB has no "None" option in the wizard, so this is
        // always a swap between two non-null values, never a removal).
        if ($diff['database']['changed']) {
            $config->database = $diff['database']['old'];
            $this->addDatabase($diff['database']['new'], $config, skipConfirm: true);
        }

        // 4-5. Cache / storage: three-way (remove / add / swap).
        $this->replayDriverChange($diff['cache'], $config, 'cacheDriver', 'addCacheDriver', 'removeCache');
        $this->replayDriverChange($diff['storage'], $config, 'objectStorage', 'addStorage', 'removeStorage');

        // 6. Scout has no "None" option in the wizard, so a scout-diff can only
        // ever be an add or a swap, never a standalone remove — removal only
        // ever happens via the SCOUT feature itself (step 2 above).
        if ($diff['scout']['changed'] && $diff['scout']['new'] !== null) {
            if ($diff['scout']['old'] !== null) {
                $config->scoutDriver = $diff['scout']['old'];
            }

            $this->addScoutDriver($diff['scout']['new'], $config, skipConfirm: true);
        }

        // 7. Feature additions, excluding SCOUT — already applied as a side
        // effect of addScoutDriver() above (which itself calls addFeature()).
        foreach ($diff['features']['add'] as $feature) {
            if ($feature === LaravelFeature::SCOUT) {
                continue;
            }

            $this->addFeature($feature, $config, skipConfirm: true);
        }

        // 8. Blueprint additions.
        foreach ($diff['blueprints']['add'] as $blueprint) {
            $this->addBlueprint($blueprint, $config, skipConfirm: true);
        }

        // 9. Architectural pivot: collapse PHP version / OS / server variation
        // into a single finishArchitecturalPivot() call (and a single "rebuild
        // image?" confirm) instead of three independent ones.
        if ($diff['phpVersion']['changed'] || $diff['os']['changed'] || $diff['serverVariation']['changed']) {
            $changes = array_filter([
                $diff['phpVersion']['changed'] ? 'PHP Version to '.$diff['phpVersion']['new']->getLabel() : null,
                $diff['os']['changed'] ? 'Operating System to '.$diff['os']['new']->getLabel() : null,
                $diff['serverVariation']['changed'] ? 'Server Variation to '.$diff['serverVariation']['new']->getLabel() : null,
            ]);

            $this->laraKubeInfo('Pivoting '.implode(', ', $changes));
            $this->finishArchitecturalPivot($config);
        }

        // 10. Final catch-all — cheap/idempotent even if step 9 already ran its
        // own save+orchestrate, and guarantees manifests reflect the union of
        // every change even for a pure add/remove diff with no pivot.
        $this->withSpin('Finalizing project DNA...', function () use ($config, $install) {
            $this->saveProjectConfig($config->getPath(), $config);
            $this->orchestrateProjectScaffolding($config, installFeatures: $install, buildImage: false);

            return true;
        });
    }

    /** @return array{add: array, remove: array} */
    private function diffList(array $old, array $new): array
    {
        return [
            'add' => array_values(array_filter($new, fn ($item) => ! in_array($item, $old, true))),
            'remove' => array_values(array_filter($old, fn ($item) => ! in_array($item, $new, true))),
        ];
    }

    /** @return array{old: mixed, new: mixed, changed: bool} */
    private function diffScalar(mixed $old, mixed $new): array
    {
        return ['old' => $old, 'new' => $new, 'changed' => $old !== $new];
    }

    /**
     * Three-way replay for a single-primary driver field (cache/storage):
     * old→null removes; null→new adds; old→new (both set) restores the old
     * value onto $config first, then adds the new one — reusing addXxx()'s
     * existing swap-confirm logic, which reads the CURRENT primary off
     * $config at call time (that's why the old value must be restored first).
     *
     * @param  array{old: mixed, new: mixed, changed: bool}  $fieldDiff
     */
    private function replayDriverChange(array $fieldDiff, ConfigData $config, string $property, string $addMethod, string $removeMethod): void
    {
        if (! $fieldDiff['changed']) {
            return;
        }

        if ($fieldDiff['new'] === null) {
            $this->{$removeMethod}($fieldDiff['old'], $config);

            return;
        }

        if ($fieldDiff['old'] !== null) {
            $config->{$property} = $fieldDiff['old'];
        }

        $this->{$addMethod}($fieldDiff['new'], $config, skipConfirm: true);
    }
}
