<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

/**
 * Run a `create-*` scaffolder inside Node, handing it the terminal when there
 * is one.
 *
 * The default is to let the UPSTREAM CLI ask its own questions, the way
 * `larakube new` does by handing off to the official `laravel new` installer.
 * Vite, Astro and Docusaurus each maintain their own wizard; mirroring their
 * options in a LaraKube-side prompt means shipping a list that silently rots
 * every time they add a template, and answering questions they may since have
 * dropped or reworded. Delegating means their updates arrive for free.
 *
 * That requires a real TTY: `docker run -it` fails outright without one
 * ("the input device is not a TTY"), and a create-* tool that reaches a prompt
 * with no terminal exits 0 having produced nothing — `create-docusaurus` did
 * exactly that, so `docs:new` printed a ✓ spinner and "scaffolding failed" in
 * the same breath. So when there is no TTY (CI, a piped run, `--no-interaction`)
 * the caller's fully-flagged non-interactive command line is used instead.
 */
trait ScaffoldsInNode
{
    use InteractsWithDocker, StreamsProcessOutput;

    /**
     * A persistent, runtime-managed named volume for npm/npx's cache. It
     * survives `--rm` (the container's own layer does not), so the second
     * scaffold onward reuses the create-* package instead of re-downloading it
     * from the registry every run. Shared across nextjs/astro/vite/docs since
     * they all pull the same Node builder image.
     */
    protected const NPM_CACHE_VOLUME = 'larakube-npm-cache';

    /** The decision itself, free of globals so it can be tested directly. */
    public function promptCapable(bool $noInteraction, bool $fast, bool $hasTty): bool
    {
        return $hasTty && ! $noInteraction && ! $fast;
    }

    /**
     * @param  string  $interactive  Command line that lets the scaffolder prompt
     *                               (omit the flags that would answer for it).
     * @param  string  $scripted  Fully-flagged equivalent for when there is
     *                            no terminal to prompt on.
     */
    protected function scaffoldInNode(
        string $appName,
        string $baseDir,
        string $label,
        string $interactive,
        string $scripted,
    ): bool {
        $this->laraKubeInfo('Pulling the Node builder image...');
        Process::forever()->run($this->pullImageCommand(self::NODE_IMAGE));

        $canPrompt = $this->scaffolderCanPrompt();

        $runtime = $this->containerRuntime();

        // The npm cache rides on a named volume pointed at by npm_config_cache,
        // not a host bind-mount, so it needs no chown: npm owns its own cache
        // inside runtime-managed storage, identically under Docker (root there)
        // and rootless Podman (the host user via userns).
        $cache = '-v '.self::NPM_CACHE_VOLUME.':/npm-cache -e npm_config_cache=/npm-cache ';
        $mount = '-v '.escapeshellarg($baseDir).':/app -w /app '.$cache.'--user root ';

        if ($canPrompt) {
            // passthru, not Process::run: the scaffolder owns stdin and stdout
            // for the duration, which is the whole point. Nothing may wrap this
            // in withSpin() either — a spinner repainting over an interactive
            // wizard makes it unreadable.
            $this->laraKubeInfo("Scaffolding {$label} — answer the prompts below.");
            $this->newLine();

            passthru(
                $runtime.' run --rm -it '.$mount.self::NODE_IMAGE.' sh -c '.escapeshellarg($interactive),
            );
        } else {
            $this->withSpin("Scaffolding {$label}...", fn (): bool => Process::forever()->run(
                $runtime.' run --rm '.$mount.self::NODE_IMAGE.' sh -c '.escapeshellarg($scripted),
            )->successful());
        }

        // The directory, not the exit code, is the real check: a create-* tool
        // that bailed at a prompt still exits 0.
        if (! is_dir("{$baseDir}/{$appName}")) {
            return false;
        }

        // The container writes as root; hand the tree back to the host user.
        $this->runStreaming(
            $runtime.' run --rm -v '.escapeshellarg($baseDir).':/app --user root '
            .self::NODE_IMAGE.' chown -R '.$this->containerChownSpec($this->hostUid(), $this->hostGid()).' /app/'.escapeshellarg($appName),
        );

        return true;
    }

    /**
     * Whether the scaffolder can be handed the terminal.
     *
     * `docker run -it` needs a real TTY on stdin, so this is a hard capability
     * question, not a preference — hence stream_isatty() alongside the usual
     * --no-interaction check.
     */
    protected function scaffolderCanPrompt(): bool
    {
        // Never hand a test run the terminal: pest may well be run FROM one, so
        // stream_isatty() alone would let `docker run -it` and passthru() loose
        // in the suite.
        return ! app()->runningUnitTests() && $this->promptCapable(
            (bool) $this->option('no-interaction'),
            (bool) $this->option('fast'),
            stream_isatty(STDIN),
        );
    }
}
