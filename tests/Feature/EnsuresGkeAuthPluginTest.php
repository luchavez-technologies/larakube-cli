<?php

use App\Support\Os;
use App\Traits\EnsuresGkeAuthPlugin;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function gkeAuthPluginRunner(bool $interactive): Command
{
    $command = new class extends Command
    {
        use EnsuresGkeAuthPlugin;

        protected $signature = 'test:ensure-gke-auth-plugin';

        public function bind(bool $interactive): void
        {
            $this->input = new ArrayInput([]);
            $this->input->setInteractive($interactive);
            $this->output = new OutputStyle($this->input, new BufferedOutput);
        }

        public function check(): bool
        {
            return $this->ensureGkeAuthPlugin();
        }
    };

    $command->bind($interactive);

    return $command;
}

test('a plugin already on PATH passes without touching gcloud at all', function (): void {
    Process::fake([
        'command -v gke-gcloud-auth-plugin' => Process::result('/usr/local/bin/gke-gcloud-auth-plugin'),
        '*' => Process::result('', exitCode: 1),
    ]);

    expect(gkeAuthPluginRunner(interactive: true)->check())->toBeTrue();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'components install'));
});

test('a plugin installed but off PATH (the Homebrew-cask case) is found via gcloud\'s own sdk_root and added to PATH', function (): void {
    // Confirmed live: `gcloud components install` reports success on a
    // Homebrew-cask gcloud, but the binary is never symlinked onto PATH —
    // it only ever lands inside the SDK's own bin/ dir.
    $sdk = TemporaryDirectory::make()->deleteWhenDestroyed();
    $binDir = $sdk->path('bin'); // TemporaryDirectory::path() already creates the subdirectory
    $plugin = "{$binDir}/gke-gcloud-auth-plugin";
    file_put_contents($plugin, "#!/bin/sh\n");
    chmod($plugin, 0755);

    $originalPath = (string) getenv('PATH');

    Process::fake([
        'command -v gke-gcloud-auth-plugin' => Process::result('', exitCode: 1),
        'command -v gcloud' => Process::result('/opt/homebrew/bin/gcloud'),
        '*sdk_root*' => Process::result($sdk->path()),
        '*' => Process::result('', exitCode: 1),
    ]);

    expect(gkeAuthPluginRunner(interactive: true)->check())->toBeTrue()
        ->and(getenv('PATH'))->toContain($binDir);

    putenv("PATH={$originalPath}"); // don't leak into other tests
});

test('non-interactive with no plugin anywhere refuses instead of guessing', function (): void {
    Process::fake([
        'command -v gke-gcloud-auth-plugin' => Process::result('', exitCode: 1),
        'command -v gcloud' => Process::result('/opt/homebrew/bin/gcloud'),
        '*sdk_root*' => Process::result('/nonexistent/sdk/root'),
        '*' => Process::result('', exitCode: 1),
    ]);

    expect(gkeAuthPluginRunner(interactive: false)->check())->toBeFalse();

    Process::assertNotRan(fn ($process) => str_contains(implode(' ', (array) $process->command), 'components install'));
});

test('on Windows, the plugin is located via gcloud\'s sdk_root with its .exe suffix, never command -v', function (): void {
    Os::setForcedFamily('Windows');

    // CliTool::GCLOUD->resolveBinary() itself skips `command -v` on Windows
    // and resolves via LARAKUBE_BIN_DIR candidate paths instead — a fake
    // `gcloud.cmd` there stands in for a native Windows gcloud install.
    $binDir = TemporaryDirectory::make()->deleteWhenDestroyed();
    putenv('LARAKUBE_BIN_DIR='.$binDir->path());
    file_put_contents($binDir->path().'/gcloud.cmd', '');
    chmod($binDir->path().'/gcloud.cmd', 0755);

    $sdk = TemporaryDirectory::make()->deleteWhenDestroyed();
    $pluginDir = $sdk->path('bin');
    file_put_contents("{$pluginDir}/gke-gcloud-auth-plugin.exe", '');
    chmod("{$pluginDir}/gke-gcloud-auth-plugin.exe", 0755);

    $originalPath = (string) getenv('PATH');

    Process::fake([
        '*sdk_root*' => Process::result($sdk->path()),
        '*' => function (): never {
            throw new RuntimeException('locateGkeAuthPlugin must not shell out to `command -v` on Windows.');
        },
    ]);

    try {
        expect(gkeAuthPluginRunner(interactive: true)->check())->toBeTrue()
            // The Windows PATH merge must use `;`, not a POSIX `:` — joining
            // a Windows directory with `:` would be ambiguous with its own
            // drive-letter colon (`C:\...`).
            ->and(getenv('PATH'))->toContain($pluginDir.';');
    } finally {
        putenv('LARAKUBE_BIN_DIR');
        putenv("PATH={$originalPath}");
        Os::setForcedFamily(null);
    }
});

test('no gcloud installed at all refuses with a clear install-gcloud-first message', function (): void {
    Process::fake([
        'command -v gke-gcloud-auth-plugin' => Process::result('', exitCode: 1),
        'command -v gcloud' => Process::result('', exitCode: 1),
        '*' => Process::result('', exitCode: 1),
    ]);

    expect(gkeAuthPluginRunner(interactive: true)->check())->toBeFalse();
});
