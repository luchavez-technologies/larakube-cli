<?php

/**
 * cloud:init:doks headless behavior: --email must be validated, and a
 * headless run with no email anywhere must fail with a clear pointer to
 * --email= rather than throwing from a required prompt. kubectl is faked so
 * the Traefik-already-installed check reports "not installed" and the email
 * path is actually reached.
 */

use App\State;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 1)]);
});

test('an invalid --email is rejected before anything is installed', function () {
    $this->artisan('cloud:init:doks', ['--context' => 'do-nyc1-test', '--email' => 'not-an-email'])
        ->assertExitCode(1);

    expect(State::$lastError)->toContain('Invalid --email');
});

test('headless with no stored email fails clearly, pointing at --email=', function () {
    $this->artisan('cloud:init:doks', ['--context' => 'do-nyc1-test', '--no-interaction' => true])
        ->assertExitCode(1);

    expect(State::$lastError)->toContain('--email=');
});
