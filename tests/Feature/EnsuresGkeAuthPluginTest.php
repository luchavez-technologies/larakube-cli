<?php

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
        "*info --format='value(installation.sdk_root)'*" => Process::result($sdk->path()),
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
        "*info --format='value(installation.sdk_root)'*" => Process::result('/nonexistent/sdk/root'),
        '*' => Process::result('', exitCode: 1),
    ]);

    expect(gkeAuthPluginRunner(interactive: false)->check())->toBeFalse();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'components install'));
});

test('no gcloud installed at all refuses with a clear install-gcloud-first message', function (): void {
    Process::fake([
        'command -v gke-gcloud-auth-plugin' => Process::result('', exitCode: 1),
        'command -v gcloud' => Process::result('', exitCode: 1),
        '*' => Process::result('', exitCode: 1),
    ]);

    expect(gkeAuthPluginRunner(interactive: true)->check())->toBeFalse();
});
