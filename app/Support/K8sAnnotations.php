<?php

namespace App\Support;

class K8sAnnotations
{
    /** Records a Deployment's live replica count before scaling it to zero (Cluster Tool pause, or a project's Stop), so it can be restored exactly instead of guessed. */
    public const string PAUSED_REPLICAS_ANNOTATION = 'larakube.app/paused-replicas';

    /**
     * Whether a Deployment (its `kubectl get -o json` item) is paused: scaled to
     * zero with the pause marker set. The marker alone isn't enough: anything
     * that scales the Deployment up without tool:resume leaves it in place.
     *
     * @param  array<string, mixed>  $deployment
     */
    public static function isPausedDeployment(array $deployment): bool
    {
        return isset($deployment['metadata']['annotations'][self::PAUSED_REPLICAS_ANNOTATION])
            && (int) ($deployment['spec']['replicas'] ?? 1) === 0;
    }
}
