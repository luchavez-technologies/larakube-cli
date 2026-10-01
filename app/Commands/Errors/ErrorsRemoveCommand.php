<?php

namespace App\Commands\Errors;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;
use App\Traits\ReadsClusterSecrets;

class ErrorsRemoveCommand extends AbstractToolRemoveCommand
{
    use ReadsClusterSecrets;

    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'errors:remove' is deprecated. Please use 'glitchtip:remove' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::ERRORS;
    }

    /**
     * GlitchTip is the one tool whose bundled-vs-Commons state isn't visible
     * from a Deployment name — both modes deploy the same workloads. The
     * authoritative signal is its own database-url, which points at the
     * in-namespace glitchtip-db Service only in `--no-plex` mode.
     */
    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        $names = ToolInstance::forInstance(ClusterTool::ERRORS, (string) $this->resolveInstance($kubectl));
        $url = $this->readClusterSecretKey($kubectl, $namespace, $names->secret(), 'database-url');

        return $url !== null && str_contains($url, $names->deployment('db'));
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $names = ToolInstance::forInstance(ClusterTool::ERRORS, (string) $this->resolveInstance($kubectl));
        [$web, $worker, $db, $cache] = [$names->deployment(), $names->deployment('worker'), $names->deployment('db'), $names->deployment('cache')];

        return $this->removeResources(
            'Removing GlitchTip resources...',
            "{$kubectl} delete deploy/{$web} deploy/{$worker} deploy/{$db} deploy/{$cache} "
            ."pvc/{$names->volume('storage', 'db')} svc/{$web} svc/{$db} svc/{$cache} "
            ."ingress/{$web} secret/{$names->secret()} secret/{$names->secret(SecretKind::SMTP)} job/{$names->name('migrations')} "
            ."-n {$namespace} --ignore-not-found",
        );
    }
}
