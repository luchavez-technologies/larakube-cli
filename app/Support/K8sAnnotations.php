<?php

namespace App\Support;

class K8sAnnotations
{
    /** Records a Deployment's live replica count before scaling it to zero (Cluster Tool pause, or a project's Stop), so it can be restored exactly instead of guessed. */
    public const string PAUSED_REPLICAS_ANNOTATION = 'larakube.app/paused-replicas';
}
