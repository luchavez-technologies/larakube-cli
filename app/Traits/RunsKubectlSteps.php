<?php

namespace App\Traits;

use App\Data\KubectlResult;
use Closure;

trait RunsKubectlSteps
{
    /**
     * Run one typed kubectl call behind a spinner. On failure, kubectl's own
     * error is shown and false returned, so the caller never reports success
     * over a failed step.
     *
     * @param  Closure(): KubectlResult  $call
     */
    protected function kubectlStep(string $label, Closure $call): bool
    {
        $result = null;
        $this->withSpin($label, function () use ($call, &$result): bool {
            $result = $call();

            return $result->ok;
        });

        if ($result === null || ! $result->ok) {
            $this->laraKubeError(trim($result->error ?? '') ?: "{$label} failed.");

            return false;
        }

        return true;
    }
}
