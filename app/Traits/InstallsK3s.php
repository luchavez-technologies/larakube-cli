<?php

namespace App\Traits;

use App\Data\ConfigData;
use Exception;
use Illuminate\Support\Facades\Http;

/**
 * One place for how k3s is installed, shared by the local installer (cluster:setup) and
 * the remote installer (cloud:provision). Both pipe the official get.k3s.io script; only
 * the execution context differs (local passthru vs SSH) and their own pre/post steps
 * (kubeconfig merge locally; swap + IP-forward remotely). Centralising the URL, the pinned
 * version, and the base invocation keeps the two from silently drifting apart.
 */
trait InstallsK3s
{
    /**
     * The pinned k3s version — single source of truth (ConfigData::DEFAULT_K3S_VERSION).
     * Honors a project override when a config is passed, else the default.
     */
    protected function k3sVersion(?ConfigData $config = null): string
    {
        return $config?->k3sVersion ?? ConfigData::DEFAULT_K3S_VERSION;
    }

    /**
     * The canonical k3s install command:
     *   curl -sfL https://get.k3s.io | [sudo env] INSTALL_K3S_VERSION=<v> sh [-s - <flags>]
     * Callers wrap it in their own execution context (local passthru vs remote SSH).
     *
     * @param  array<int, string>  $flags  install args appended after `sh -s -` (e.g. --disable=traefik). Static/controlled — not escaped.
     * @param  array<string, string>  $env  extra env assignments prepended (e.g. K3S_KUBECONFIG_MODE => 644)
     * @param  bool  $sudo  prefix the shell with `sudo` so the installer runs as root explicitly
     */
    protected function k3sInstallCommand(string $version, array $flags = [], array $env = [], bool $sudo = false): string
    {
        $assignments = 'INSTALL_K3S_VERSION='.escapeshellarg($version).' ';
        foreach ($env as $key => $value) {
            $assignments .= $key.'='.escapeshellarg($value).' ';
        }

        $sh = $flags === [] ? 'sh -' : 'sh -s - '.implode(' ', $flags);

        if ($sudo) {
            // Env assignments placed before `sudo` only set sudo's OWN
            // environment — sudo resets the environment by default before
            // exec'ing its target, so INSTALL_K3S_VERSION/etc. would never
            // reach `sh`. Routing them through `env` (as sudo's argument,
            // not a shell-prefix) makes them land on the process that
            // actually runs the installer.
            return 'curl -sfL https://get.k3s.io | sudo env '.$assignments.$sh;
        }

        return 'curl -sfL https://get.k3s.io | '.$assignments.$sh;
    }

    /**
     * Check GitHub for the latest k3s release and warn if LaraKube's pinned
     * version is behind. Non-fatal and silently skipped on network failure.
     */
    protected function warnIfNewerK3sAvailable(): void
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'LaraKube-CLI'])
                ->timeout(5)
                ->get('https://api.github.com/repos/k3s-io/k3s/releases/latest');

            if ($response->failed()) {
                return;
            }

            $latest = $response->json('tag_name');
            $pinned = ConfigData::DEFAULT_K3S_VERSION;

            if ($latest && $latest !== $pinned) {
                $this->laraKubeWarn("A newer k3s version is available: <fg=green>{$latest}</>");
                $this->line("  LaraKube currently pins: <fg=yellow>{$pinned}</>");
                $this->line('  Run <fg=cyan>larakube update</> to get the latest LaraKube CLI with the updated k3s version.');
            }
        } catch (Exception) {
            // Best-effort — never block cluster setup over a version check.
        }
    }
}
