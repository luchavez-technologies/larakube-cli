<?php

namespace App\Traits;

use App\Enums\CliTool;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\warning;

/**
 * GKE authenticates kubectl through a separate gcloud component
 * (gke-gcloud-auth-plugin), required since client-go v1.26. Without it,
 * EVERY kubectl call against a GKE cluster fails identically with "getting
 * credentials: exec: executable gke-gcloud-auth-plugin not found" — which
 * reads like (and was once misdiagnosed live as) a Traefik/manifest problem,
 * when the manifest itself was never the issue. Checked once, right before
 * the first kubectl call a GKE flow makes.
 *
 * A Homebrew-cask gcloud install (common on macOS) does NOT put this
 * component's binary on PATH at all — confirmed live: `gcloud components
 * install` reported success, but `command -v` still failed, because the
 * binary lands in the SDK's own bin/ dir, never symlinked anywhere on PATH.
 * So this locates it via `gcloud info`'s own sdk_root when a bare PATH
 * lookup fails, and prepends that directory to PATH for the rest of this
 * process — kubectl is a SEPARATE process this CLI spawns, and it resolves
 * the plugin via its own PATH at exec time, not whatever this check found.
 */
trait EnsuresGkeAuthPlugin
{
    protected function ensureGkeAuthPlugin(): bool
    {
        if ($this->locateGkeAuthPlugin() !== null) {
            return true;
        }

        if (! CliTool::GCLOUD->isInstalled()) {
            error('gcloud is not installed, and GKE needs its gke-gcloud-auth-plugin component to authenticate kubectl.');
            error('Install gcloud first (`larakube setup --tools=gcloud`), then `gcloud components install gke-gcloud-auth-plugin`.');

            return false;
        }

        if (! $this->input->isInteractive()) {
            error('gke-gcloud-auth-plugin is not installed — every kubectl call against this GKE cluster fails without it.');
            error('Install it with `gcloud components install gke-gcloud-auth-plugin --quiet`, then re-run this command.');

            return false;
        }

        warning('gke-gcloud-auth-plugin is not installed — every kubectl call against this GKE cluster fails without it.');

        if (! confirm('Install it now via gcloud (gcloud components install gke-gcloud-auth-plugin)?', default: true)) {
            error('Cannot reach this cluster with kubectl until the plugin is installed.');

            return false;
        }

        $bin = CliTool::GCLOUD->resolveBinary() ?? 'gcloud';
        Process::forever()->run("{$bin} components install gke-gcloud-auth-plugin --quiet");

        if ($this->locateGkeAuthPlugin() !== null) {
            return true;
        }

        error('Could not install gke-gcloud-auth-plugin. Try `gcloud components install gke-gcloud-auth-plugin` by hand.');

        return false;
    }

    /** Finds the plugin on PATH, or via gcloud's own SDK root when PATH doesn't have it — and fixes PATH for this process either way. */
    private function locateGkeAuthPlugin(): ?string
    {
        $onPath = trim(Process::run('command -v gke-gcloud-auth-plugin')->output());
        if ($onPath !== '') {
            return $onPath;
        }

        $gcloud = CliTool::GCLOUD->resolveBinary();
        if ($gcloud === null) {
            return null;
        }

        $sdkRoot = trim(Process::run(escapeshellarg($gcloud)." info --format='value(installation.sdk_root)'")->output());
        if ($sdkRoot === '') {
            return null;
        }

        $candidate = "{$sdkRoot}/bin/gke-gcloud-auth-plugin";
        if (! @is_executable($candidate)) {
            return null;
        }

        $dir = dirname($candidate);
        $path = (string) getenv('PATH');
        if (! in_array($dir, explode(':', $path), true)) {
            putenv("PATH={$dir}:{$path}");
        }

        return $candidate;
    }
}
