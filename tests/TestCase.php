<?php

namespace Tests;

use App\State;
use LaravelZero\Framework\Testing\TestCase as BaseTestCase;
use Symfony\Component\Console\Output\NullOutput;
use Termwind\Termwind;

abstract class TestCase extends BaseTestCase
{
    /**
     * Default every artisan call to --no-interaction.
     *
     * The Pest beforeEach pins Prompt::interactive(false), but Illuminate's
     * Console Command::run() re-runs configurePrompts() on every artisan call,
     * which forces interactive back ON whenever STDIN is a TTY — and with
     * laravel-zero's app.env hardcoded to 'development' (never 'testing'),
     * runningUnitTests() is false, so there's no unit-test branch to save us.
     * Under a real terminal an unfaked prompt then blocks reading stdin (or
     * churns the render loop into an OOM); --no-interaction makes Symfony mark
     * the input non-interactive so prompts fall straight to their defaults.
     * Explicit parameters win, so a test passing '--no-interaction' => false
     * still gets an interactive input.
     */
    public function artisan($command, $parameters = [])
    {
        // When called with a plain command string and no parameters, Application::call()
        // parses it via StringInput and resolves the command by name — injecting anything
        // into $parameters would flip it onto the ArrayInput path, which unshifts the whole
        // string as a positional arg and breaks command resolution. Append the flag to the
        // string instead; for parameterized calls, merge it into the array (later keys win).
        if (is_string($command) && $parameters === []) {
            return parent::artisan(trim($command).' --no-interaction');
        }

        return parent::artisan($command, array_merge(['--no-interaction' => true], $parameters));
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Point HOME at an empty temp dir so GlobalConfigData::load() returns
        // defaults (TLD=kube) and never reads the developer's real config.
        $_SERVER['HOME'] = sys_get_temp_dir().'/larakube-test-home';

        // Keep the test runner's output clean. Every laraKube* output helper (and
        // the header tagline) renders via termwind's render(), which writes to its
        // own console output — NOT the BufferedOutput that Artisan::call captures —
        // so command banners spill straight into the test report. Redirect termwind
        // to a NullOutput, and pre-set the "header already shown" flag so the raw
        // echo'd ASCII logo is skipped too. This is purely cosmetic: it never
        // touches Artisan::output(), so output-asserting tests are unaffected, and
        // unlike forcing AI_AGENT=true it triggers no agent-mode logic branches.
        Termwind::renderUsing(new NullOutput);
        State::$headerRendered = true;
        State::$isTesting = true;

        // Process-wide statics from JSON mode / transient-token handling must
        // not leak between tests.
        State::$jsonMode = false;
        State::$transientDoToken = null;
        State::$lastError = null;
        State::$stdout = null;
        State::$registeredSecrets = [];
    }
}
