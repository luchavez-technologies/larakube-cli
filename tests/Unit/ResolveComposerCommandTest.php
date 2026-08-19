<?php

/**
 * resolveComposerCommand() is the cheaply-testable Process-backed leaf here.
 * runGitClone()/runComposerInstall() stream live output via runStreaming()
 * and are left to a real-machine smoke test (nothing meaningful to assert
 * beyond "a real git clone/composer install ran").
 */

use App\Traits\ClonesRepositories;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function composerCommandResolver(): object
{
    return new class
    {
        use ClonesRepositories;

        public function resolve(string $workDir): string
        {
            return $this->resolveComposerCommand($workDir);
        }
    };
}

test('resolveComposerCommand prefers a real command -v hit over the docker fallback', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $fakeComposer = $temporaryDirectory->path().'/fake-composer';
    file_put_contents($fakeComposer, "#!/bin/sh\necho fake-composer\n");
    chmod($fakeComposer, 0755);

    try {
        Process::fake(['command -v composer' => $fakeComposer."\n"]);

        expect(composerCommandResolver()->resolve('/proj'))->toBe($fakeComposer);
    } finally {
        $temporaryDirectory->delete();
    }
});

test('resolveComposerCommand falls back to a dockerized composer when nothing resolves to a real executable', function (): void {
    Process::fake(['command -v composer' => Process::result(output: '', exitCode: 1)]);

    if (collect(['/usr/local/bin/composer', '/opt/homebrew/bin/composer'])->contains(fn ($p) => @is_executable($p))) {
        $this->markTestSkipped('composer is actually installed at a fallback path on this machine.');
    }

    expect(composerCommandResolver()->resolve('/proj'))
        ->toContain('docker run')
        ->toContain("-v '/proj':/app");
});
