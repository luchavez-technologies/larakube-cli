<?php

/**
 * Tests for InteractsWithGlobalConfig's Process-backed checks. getGhCommand()
 * also requires the candidate to be a real, executable file on disk
 * (@is_executable()) — same caveat as ConfiguresCloudEnvironment's
 * resolveGlabCommand(), so the "found via command -v" case needs an actual
 * temporary executable to resolve to.
 */

use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithOs;
use Illuminate\Support\Facades\Process;

function globalConfigHelper(): object
{
    return new class
    {
        use InteractsWithGlobalConfig, InteractsWithOs;

        public function gh(): string
        {
            return $this->getGhCommand();
        }
    };
}

function globalConfigHelperOnDarwin(): object
{
    return new class
    {
        use InteractsWithGlobalConfig, InteractsWithOs;

        public function caTrusted(): bool
        {
            return $this->checkCaTrust();
        }

        protected function isDarwin(): bool
        {
            return true;
        }
    };
}

test('getGhCommand prefers a real command -v hit over the docker fallback', function () {
    $fakeGh = sys_get_temp_dir().'/fake-gh-'.uniqid();
    file_put_contents($fakeGh, "#!/bin/sh\necho fake-gh\n");
    chmod($fakeGh, 0755);

    try {
        Process::fake(['command -v gh' => $fakeGh."\n"]);

        expect(globalConfigHelper()->gh())->toBe($fakeGh);
    } finally {
        unlink($fakeGh);
    }
});

test('getGhCommand falls back to the dockerized gh when nothing resolves to a real executable', function () {
    Process::fake(['command -v gh' => Process::result(output: '', exitCode: 1)]);

    if (collect(['/usr/local/bin/gh', '/opt/homebrew/bin/gh', '/home/linuxbrew/.linuxbrew/bin/gh'])->contains(fn ($p) => @is_executable($p))) {
        $this->markTestSkipped('gh is actually installed at a fallback path on this machine.');
    }

    expect(globalConfigHelper()->gh())->toContain('docker run');
});

test('checkCaTrust on macOS reflects whether the CA is in the keychain', function () {
    Process::fake(['security find-certificate -c "Server Side Up CA"' => "keychain: ...\n"]);
    expect(globalConfigHelperOnDarwin()->caTrusted())->toBeTrue();

    Process::fake(['security find-certificate -c "Server Side Up CA"' => Process::result(output: '', exitCode: 1)]);
    expect(globalConfigHelperOnDarwin()->caTrusted())->toBeFalse();
});
