<?php

/**
 * Tests for ConfiguresCloudEnvironment's standalone, Process-backed helpers.
 * The larger orchestration methods (configureGha/configureGitlab/
 * uploadGhaSecrets/setupGhcrSecret/uploadGitlabVariables) need a full
 * ConfigData + Blade view rendering + filesystem state to exercise
 * meaningfully and are covered by configureBase()/detectCiPlatform() in
 * tests/Feature/CloudConfigureConsolidationTest.php; this file covers the
 * two simple leaf methods that shell out directly.
 */

use App\Traits\ConfiguresCloudEnvironment;
use App\Traits\EnsuresRealHosts;
use App\Traits\GathersEnvironmentData;
use App\Traits\ResolvesEnvironmentContext;
use Illuminate\Support\Facades\Process;

function cloudEnvironmentHelper(): object
{
    return new class
    {
        use ConfiguresCloudEnvironment, EnsuresRealHosts, GathersEnvironmentData, ResolvesEnvironmentContext;

        public function remoteUrl(): string
        {
            return $this->gitRemoteUrl();
        }

        public function glab(): ?string
        {
            return $this->resolveGlabCommand();
        }
    };
}

test('gitRemoteUrl trims the origin remote, empty string outside a git repo', function () {
    Process::fake(['git remote get-url origin' => "git@github.com:acme/demo.git\n"]);
    expect(cloudEnvironmentHelper()->remoteUrl())->toBe('git@github.com:acme/demo.git');

    Process::fake(['git remote get-url origin' => Process::result(output: '', exitCode: 1)]);
    expect(cloudEnvironmentHelper()->remoteUrl())->toBe('');
});

test('resolveGlabCommand prefers a real command -v hit over the hardcoded fallback paths', function () {
    // resolveGlabCommand() also requires the candidate to be a real,
    // executable file on disk (@is_executable()) — Process::fake() only
    // covers the `command -v` lookup, not that check, so this needs an
    // actual (temporary) executable to resolve to.
    $fakeGlab = sys_get_temp_dir().'/fake-glab-'.uniqid();
    file_put_contents($fakeGlab, "#!/bin/sh\necho fake-glab\n");
    chmod($fakeGlab, 0755);

    try {
        Process::fake(['command -v glab' => $fakeGlab."\n"]);

        expect(cloudEnvironmentHelper()->glab())->toBe($fakeGlab);
    } finally {
        unlink($fakeGlab);
    }
});

test('resolveGlabCommand is null when neither command -v nor any fallback path resolves to a real executable', function () {
    Process::fake(['command -v glab' => Process::result(output: '', exitCode: 1)]);

    // Only meaningful on a machine where glab genuinely isn't installed at
    // any of the hardcoded fallback paths — which is the norm in CI/sandboxes.
    if (collect(['/usr/local/bin/glab', '/opt/homebrew/bin/glab', '/home/linuxbrew/.linuxbrew/bin/glab'])->contains(fn ($p) => @is_executable($p))) {
        $this->markTestSkipped('glab is actually installed at a fallback path on this machine.');
    }

    expect(cloudEnvironmentHelper()->glab())->toBeNull();
});
