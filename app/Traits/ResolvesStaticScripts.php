<?php

namespace App\Traits;

use App\Data\ConfigData;
use RuntimeException;

/**
 * Resolve a static site's dev-server and build commands against the project's
 * OWN package.json, using the framework enum only as an ordered hint.
 *
 * The enum knows the K8s-imposed shape — which port the Service targets, the
 * --host/--poll flags a Pod needs — and owns that, because nothing in the
 * project declares it. It does NOT own the script NAME: package.json states
 * that, one file read away in the very directory we generate into. Asserting
 * `docusaurus => start` from the enum is the same mistake as maintaining a
 * template list the scaffolder already owns — a rename upstream (or by the
 * user), or Docusaurus ever shipping `dev`, and the manifest is wrong.
 *
 * The blast radius is why the two are split. A wrong script name fails at
 * runtime as a CrashLoopBackOff in the cluster — restart-count noise, the
 * worst place to learn of it (this is exactly how `doctor` failed). A wrong
 * output dir fails loudly at `docker build` ("/app/dist: not found"), so that
 * one stays a cheap enum answer rather than a JS-parse of vite.config.
 *
 * Resolving here turns a missing script into a clear error at GENERATION time
 * — where `up` already regenerates every run — instead of a crashing pod.
 */
trait ResolvesStaticScripts
{
    /**
     * The full dev-server command line the local pod runs: the resolved script
     * through the package manager, plus the framework's Pod-reachability flags.
     */
    protected function resolveDevServerCommand(ConfigData $config): string
    {
        $framework = $config->framework;
        $script = $this->pickPackageScript(
            $config,
            $framework?->devServerScriptCandidates() ?? [],
            'dev-server',
        );

        $flags = $framework?->devServerFlags() ?? '';

        return trim($config->getPackageManager()->runScript($script).' '.$flags);
    }

    /**
     * The build command the Dockerfile.static runs to produce the bundle.
     */
    protected function resolveBuildCommand(ConfigData $config): string
    {
        $script = $this->pickPackageScript(
            $config,
            $config->framework?->buildScriptCandidates() ?? [],
            'build',
        );

        return $config->getPackageManager()->runScript($script);
    }

    /**
     * First candidate that actually exists in the project's package.json.
     *
     * @param  list<string>  $candidates
     *
     * @throws RuntimeException when none of them is declared — a genuine
     *                          misconfiguration that must halt generation, not
     *                          produce a manifest that crashloops.
     */
    protected function pickPackageScript(ConfigData $config, array $candidates, string $kind): string
    {
        $scripts = $this->readPackageScripts($config->getPath());

        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $scripts)) {
                return $candidate;
            }
        }

        throw new RuntimeException(sprintf(
            "No %s script found in %s/package.json.\n  Looked for: %s\n  Declared:   %s",
            $kind,
            $config->getName() ?? 'this project',
            implode(', ', $candidates) ?: '(none — is this a static site?)',
            $scripts === [] ? '(no scripts block)' : implode(', ', array_keys($scripts)),
        ));
    }

    /**
     * The `scripts` block of a project's package.json, or [] when there is no
     * file or no block. Not a hard failure on its own — pickPackageScript()
     * turns an empty result into the specific error, so the caller sees what
     * was looked for, not just "file missing".
     *
     * @return array<string, string>
     */
    protected function readPackageScripts(string $projectPath): array
    {
        $file = "{$projectPath}/package.json";

        if (! is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);
        $scripts = is_array($data) ? ($data['scripts'] ?? null) : null;

        if (! is_array($scripts)) {
            return [];
        }

        // Keep only string=>string entries; a malformed value is not a script
        // we can run, so it must not satisfy a candidate check.
        return array_filter(
            $scripts,
            fn ($v, $k): bool => is_string($k) && is_string($v),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
