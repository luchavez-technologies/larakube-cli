<?php

namespace Tests;

use App\State;
use LaravelZero\Framework\Testing\TestCase as BaseTestCase;
use Mockery;
use Symfony\Component\Console\Output\NullOutput;
use Termwind\Termwind;

abstract class TestCase extends BaseTestCase
{
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

    protected function tearDown(): void
    {
        if (class_exists(Mockery::class)) {
            Mockery::close();
        }

        parent::tearDown();
    }
}
