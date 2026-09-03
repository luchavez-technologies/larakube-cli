<?php

namespace Tests;

use App\State;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Laravel\Prompts\Prompt;
use LaravelZero\Framework\Testing\TestCase as BaseTestCase;
use Mockery;
use ReflectionProperty;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Console\Output\NullOutput;
use Termwind\Termwind;

abstract class TestCase extends BaseTestCase
{
    private ?TemporaryDirectory $testHomeDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Point HOME at a fresh, UNIQUE-PER-TEST temp dir so
        // GlobalConfigData::load() returns defaults (TLD=kube) and never
        // reads the developer's real config. Unique, not a shared fixed
        // path: several tests exercise real cert-generation code that
        // writes actual files under this path, and a shared path — even
        // wiped at the top of every setUp() — still lets one test's
        // in-flight writes race a later test's directory-existence checks
        // (confirmed live: sporadic "No such file or directory" on the
        // cert sidecar write, order-dependent and not reproducible in
        // isolation — a real symptom of two tests sharing one mutable
        // path, made worse under `pest --parallel`, where two DIFFERENT
        // processes could hit it simultaneously). A brand-new directory per
        // test removes the shared state entirely. deleteWhenDestroyed() is
        // a safety net alongside the explicit delete() in tearDown() below
        // — covers a test that errors before tearDown runs.
        $this->testHomeDir = TemporaryDirectory::make()->deleteWhenDestroyed();
        $_SERVER['HOME'] = $this->testHomeDir->path();

        // Unset the ambient WSL marker so the suite behaves identically on a
        // real WSL2 host. isWsl() is `getenv('WSL_DISTRO_NAME') ||
        // wslKernelSignaturePresent()`; the WSL-sensitive tests already stub the
        // /proc/version half, but a WSL shell exports WSL_DISTRO_NAME into this
        // process, so without this a contributor on WSL sees vpn:join hit its
        // "can't run in WSL2" guard and DetectsWsl's "not WSL" cases invert.
        // Tests that need "is WSL" set it explicitly (forceWsl()).
        putenv('WSL_DISTRO_NAME');

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

        // Force non-interactive prompts in EVERY test. Laravel Prompts only
        // renders to the terminal when STDIN is a TTY (`static::$interactive
        // ??= stream_isatty(STDIN)` in Prompt::prompt()) — false in CI/a
        // non-TTY runner (so an unfaked prompt just returns its default),
        // but TRUE in a real developer terminal, where an unfaked text()/
        // select()/confirm() prompt actually renders and blocks waiting for
        // real keystrokes (confirmed live: a real terminal run needed
        // several real Enter presses partway through the suite, and hung
        // indefinitely on another). Pinning it false makes both
        // environments behave identically. Prompt::fake() calls
        // interactive(true) itself, so tests that script key presses still
        // work. Declared here rather than as a standalone beforeEach() in
        // tests/Pest.php: that placement had the exact same
        // does-not-reliably-take-effect problem as Sleep::fake() below —
        // this is the proven hook point.
        Prompt::interactive(false);

        // Illuminate\Console\Concerns\ConfiguresPrompts::configurePrompts() —
        // called on EVERY real Command::run() (i.e. every $this->artisan()
        // call) — does `Prompt::fallbackWhen($this->laravel->runningUnitTests())`,
        // and Fallback::fallbackWhen() only ever ORs the flag on
        // ($shouldFallback = $condition || $shouldFallback); there is no
        // public way to force it back to false. Once ANY test in a worker
        // process runs a real artisan command, Prompt::$shouldFallback is
        // permanently true for every test that runs afterward IN THE SAME
        // WORKER — including ones that never call configurePrompts()
        // themselves. That silently breaks Laravel Prompts' own
        // Prompt::fake([...scripted keys...]) mechanism (used by a handful
        // of tests that call $command->handle() directly instead of going
        // through $this->artisan()->expectsQuestion(...)): shouldFallback()
        // short-circuits Prompt::prompt() into the fallback path before it
        // ever reaches the real-interactive-with-faked-Terminal rendering
        // Prompt::fake() sets up, regardless of what Prompt::fake() itself
        // configures. Confirmed live (2026-08-19): MailRelayCommandTest's
        // and ExtRemoveCommandTest's Prompt::fake()-based tests failed or
        // passed depending on which other tests ran earlier in the same
        // --parallel worker — order-dependent flakiness. Resetting this
        // static every test removes the leak; it's harmless for
        // artisan()-driven tests since their own configurePrompts() call
        // sets it straight back to true before their command body runs.
        (new ReflectionProperty(Prompt::class, 'shouldFallback'))->setValue(null, false);

        // Fake every Sleep::sleep()/usleep() call in every test — several
        // polling loops (pollSecretToken, releaseSelfHostedPvc,
        // waitForExternalSecretSynced, ...) retry against real seconds-scale
        // delays in production. Declared here rather than as a standalone
        // beforeEach() in tests/Pest.php: that placement didn't reliably
        // take effect (confirmed live — a plain top-level beforeEach() left
        // Sleep::$fake false by the time the test body ran; calling
        // Sleep::fake() explicitly inside the SAME test worked, so this is
        // a real hook-ordering quirk, not a Sleep::fake() bug). setUp() is
        // the same proven hook point already used for the State::* resets
        // above.
        //
        // syncWithCarbon: true advances Carbon's test-"now" by each faked
        // sleep's duration — required for waitForExternalSecretSynced()'s
        // now()-based deadline loop to actually expire in tests instead of
        // spinning for the real wall-clock timeout.
        Sleep::fake(syncWithCarbon: true);
    }

    protected function tearDown(): void
    {
        if (class_exists(Mockery::class)) {
            Mockery::close();
        }

        $this->testHomeDir?->delete();

        // Sleep::fake(syncWithCarbon: true) advances Carbon's test-"now" on
        // every faked sleep but never resets it — without this, a test that
        // sleeps leaves Carbon's clock frozen in the future for every test
        // that runs after it, including ones that never touch Sleep at all.
        Carbon::setTestNow();

        parent::tearDown();
    }
}
