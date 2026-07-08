<?php

/**
 * resolveK9sBin() is the cheaply-testable leaf here. installK9s() mixes
 * platform detection, a real download/extract, and real filesystem
 * verification of the extracted binary — left to a real-machine smoke test.
 */

use App\Traits\InstallsK9s;
use Illuminate\Support\Facades\Process;

function k9sResolver(): object
{
    return new class
    {
        use InstallsK9s;

        public function resolve(): ?string
        {
            return $this->resolveK9sBin();
        }
    };
}

test('resolveK9sBin returns the PATH binary when it exists and is executable', function () {
    $fakeK9s = sys_get_temp_dir().'/fake-k9s-'.uniqid();
    file_put_contents($fakeK9s, "#!/bin/sh\necho fake-k9s\n");
    chmod($fakeK9s, 0755);

    try {
        Process::fake(['command -v k9s' => $fakeK9s."\n"]);

        expect(k9sResolver()->resolve())->toBe($fakeK9s);
    } finally {
        unlink($fakeK9s);
    }
});

test('resolveK9sBin is null when neither PATH nor the managed location has a real executable', function () {
    Process::fake(['command -v k9s' => Process::result(output: '', exitCode: 1)]);

    $managed = home_path('.larakube/bin/k9s');
    if (@is_executable($managed)) {
        $this->markTestSkipped('k9s is actually installed at the managed path on this machine.');
    }

    expect(k9sResolver()->resolve())->toBeNull();
});
