<?php

namespace App\Traits;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;

/** What cloud:apps:install and cloud:apps:remove share: the runner's short-lived kubeconfig, and a short error. */
trait InstallsForLaraKubeCloud
{
    /**
     * Merges the given kubeconfig into the default one and returns its context, so the app's own
     * init command can target it with --context like any other cluster.
     */
    protected function useKubeconfig(string $kubeconfig): string
    {
        if ($kubeconfig === '' || ! is_file($kubeconfig)) {
            throw new InvalidArgumentException('A kubeconfig for the cluster is required.');
        }

        $context = trim(Process::run(['kubectl', 'config', 'view', '--kubeconfig', $kubeconfig, '-o', 'jsonpath={.current-context}'])->output());
        if ($context === '') {
            throw new InvalidArgumentException('The kubeconfig has no current context.');
        }

        $default = home_path('.kube/config');
        File::ensureDirectoryExists(dirname($default), 0700);

        $merged = Process::env(['KUBECONFIG' => file_exists($default) ? "{$kubeconfig}:{$default}" : $kubeconfig])
            ->run(['kubectl', 'config', 'view', '--flatten']);
        if (! $merged->successful() || trim($merged->output()) === '') {
            throw new RuntimeException('The kubeconfig could not be read.');
        }

        File::put($default, $merged->output());
        @chmod($default, 0600);

        return $context;
    }

    protected function lastLines(string $output, string $fallback = 'The install did not finish.'): string
    {
        $lines = array_values(array_filter(array_map(trim(...), preg_split('/\R/u', mb_scrub(preg_replace('/\e\[[0-9;]*m/', '', $output) ?? '')) ?: [])));

        return implode("\n", array_slice($lines, -6)) ?: $fallback;
    }
}
