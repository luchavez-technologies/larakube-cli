<?php

/**
 * resolveK9sBin() is the cheaply-testable leaf here. installK9s() mixes
 * platform detection, a real download/extract, and real filesystem
 * verification of the extracted binary — left to a real-machine smoke test.
 */

use App\Traits\InstallsK9s;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

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

test('resolveK9sBin returns the PATH binary when it exists and is executable', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $fakeK9s = $temporaryDirectory->path().'/fake-k9s';
    file_put_contents($fakeK9s, "#!/bin/sh\necho fake-k9s\n");
    chmod($fakeK9s, 0755);

    try {
        Process::fake(['command -v k9s' => $fakeK9s."\n"]);

        expect(k9sResolver()->resolve())->toBe($fakeK9s);
    } finally {
        $temporaryDirectory->delete();
    }
});

test('resolveK9sBin is null when neither PATH nor the managed location has a real executable', function (): void {
    Process::fake(['command -v k9s' => Process::result(output: '', exitCode: 1)]);

    $managed = home_path('.larakube/bin/k9s');
    if (@is_executable($managed)) {
        $this->markTestSkipped('k9s is actually installed at the managed path on this machine.');
    }

    expect(k9sResolver()->resolve())->toBeNull();
});

test('installK9s downloads Darwin tarball directly when brew is not installed', function (): void {
    $installer = new class
    {
        use InstallsK9s;

        public function install(): bool
        {
            return $this->installK9s();
        }

        protected function isDarwin(): bool
        {
            return true;
        }

        protected function isLinux(): bool
        {
            return false;
        }

        protected function isWsl(): bool
        {
            return false;
        }

        protected function laraKubeInfo(string $message): void {}

        protected function laraKubeWarn(string $message): void {}

        protected function laraKubeLine(string $message): void {}
    };

    $binDir = home_path('.larakube/bin');
    @mkdir($binDir, 0755, true);
    $bin = $binDir.'/k9s';
    file_put_contents($bin, "#!/bin/sh\necho v0.51.0\n");
    chmod($bin, 0755);

    try {
        Process::fake([
            'command -v brew' => Process::result(output: '', exitCode: 1),
            '*tar -xz*' => Process::result(),
            '*version --short*' => Process::result(output: "v0.51.0\n"),
            '*' => Process::result(),
        ]);

        expect($installer->install())->toBeTrue();
        Process::assertRan(fn ($process): bool => str_contains((string) (is_array($process->command) ? implode(' ', $process->command) : $process->command), 'k9s_Darwin_') && str_contains((string) (is_array($process->command) ? implode(' ', $process->command) : $process->command), 'curl'));
    } finally {
        @unlink($bin);
    }
});
