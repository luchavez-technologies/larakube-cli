<?php

namespace App\Traits;

use App\Enums\CliTool;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\warning;

/**
 * kubectl is the single binary every cluster command shells out to — a bare
 * `kubectl` on PATH, via Kubectl::prefix() — and the only third-party
 * dependency the CLI can install unattended on every platform it supports. So
 * a command that needs it offers the install instead of handing back a URL.
 *
 * Provisioning commands call this BEFORE they create anything: kubectl is used
 * at the END of those flows, to merge the new kubeconfig and apply Traefik, and
 * finding it missing there leaves real infrastructure running that nothing can
 * reach.
 */
trait EnsuresKubectl
{
    protected function ensureKubectl(): bool
    {
        if (CliTool::KUBECTL->isInstalled()) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            error('kubectl is not installed, and this command needs it to reach the cluster it creates.');
            error('Install it with `larakube setup --tools=kubectl`, or see https://kubernetes.io/docs/tasks/tools/');

            return false;
        }

        warning('kubectl is not installed, and this command needs it to reach the cluster it creates.');

        if (! confirm('Install kubectl now?', default: true)) {
            error('Cannot continue without kubectl — nothing has been provisioned.');

            return false;
        }

        if (! CliTool::KUBECTL->install()) {
            error('Could not install kubectl. See https://kubernetes.io/docs/tasks/tools/');

            return false;
        }

        return true;
    }
}
