<?php

use App\Enums\CliTool;
use App\Support\Os;
use Illuminate\Support\Facades\Process;

afterEach(function (): void {
    Os::setForcedFamily(null);
});

test('update() leaves a System-owned binary alone and says who owns it', function (): void {
    Process::fake([
        'command -v kubectl' => Process::result('/usr/bin/kubectl'),
        '*' => Process::result(''),
    ]);

    expect(CliTool::KUBECTL->update())->toBeFalse()
        ->and(CliTool::KUBECTL->lastError())->toContain('/usr/bin/kubectl')
        ->and(CliTool::KUBECTL->lastError())->toContain('system package manager');

    Process::assertNotRan(fn ($process) => str_contains((string) $process->command, 'dl.k8s.io'));
    Process::assertNotRan(fn ($process) => str_contains((string) $process->command, 'brew '));
});

test('update() and reinstall() never drop a second copy next to one another installer owns', function (): void {
    Process::fake([
        'command -v kubectl' => Process::result('/usr/local/bin/kubectl'),
        '*' => Process::result(''),
    ]);

    expect(CliTool::KUBECTL->update())->toBeFalse()
        ->and(CliTool::KUBECTL->reinstall())->toBeFalse()
        ->and(CliTool::KUBECTL->lastError())->toContain('another installer');

    Process::assertNotRan(fn ($process) => str_contains((string) $process->command, 'dl.k8s.io'));
});

test('reinstall() asks Homebrew to reinstall a Homebrew-owned tool, not upgrade it', function (): void {
    Process::fake([
        'command -v k9s' => Process::result('/opt/homebrew/bin/k9s'),
        '*' => Process::result(''),
    ]);

    expect(CliTool::K9S->reinstall())->toBeTrue();

    Process::assertRan(fn ($process) => $process->command === 'brew reinstall k9s');
    Process::assertNotRan(fn ($process) => str_contains((string) $process->command, 'brew upgrade'));
});

test('reinstall() re-downloads the LaraKube CLI\'s own copy over itself', function (): void {
    Process::fake([
        'command -v kubectl' => Process::result(CliTool::binDir().'/kubectl'),
        '*' => Process::result(''),
    ]);

    CliTool::KUBECTL->reinstall();

    Process::assertRan(fn ($process) => str_contains((string) $process->command, 'dl.k8s.io/release/stable.txt'));
});

test('reinstall() rebuilds a LaraKube-installed Google Cloud SDK with components reinstall', function (): void {
    Process::fake([
        'command -v gcloud' => Process::result('/Users/dev/google-cloud-sdk/bin/gcloud'),
        'command -v python3.12' => Process::result('/usr/local/bin/python3.12'),
        '*python3.12* -c *' => Process::result('3.12'),
        '*' => Process::result(''),
    ]);

    expect(CliTool::GCLOUD->reinstall())->toBeTrue();

    Process::assertRan(fn ($process) => str_contains((string) $process->command, 'components reinstall --quiet')
        && ($process->environment['CLOUDSDK_PYTHON'] ?? null) === '/usr/local/bin/python3.12');
});

test('a failed brew install falls back to the CLI\'s own download only when Homebrew left nothing installed', function (): void {
    Os::setForcedFamily('Darwin');
    Process::fake([
        'command -v brew' => Process::result('/opt/homebrew/bin/brew'),
        'command -v k9s' => Process::result('', exitCode: 1),
        'brew install k9s' => Process::result('Error: download failed', exitCode: 1),
        '*' => Process::result(''),
    ]);

    CliTool::K9S->install();

    Process::assertRan(fn ($process) => str_contains((string) $process->command, 'derailed/k9s/releases/latest'));
});

test('a brew install that failed but still left the tool installed does not download a second copy', function (): void {
    Os::setForcedFamily('Darwin');
    Process::fake([
        'command -v brew' => Process::result('/opt/homebrew/bin/brew'),
        'command -v k9s' => Process::sequence()
            ->push(Process::result('', exitCode: 1))
            ->push(Process::result('/opt/homebrew/bin/k9s'))
            ->push(Process::result('/opt/homebrew/bin/k9s'))
            ->push(Process::result('/opt/homebrew/bin/k9s')),
        'brew install k9s' => Process::result('Warning: post-install step failed', exitCode: 1),
        '*' => Process::result(''),
    ]);

    expect(CliTool::K9S->install())->toBeFalse();

    Process::assertNotRan(fn ($process) => str_contains((string) $process->command, 'derailed/k9s/releases/latest'));
});

test('a tool with no native Windows installer reports why instead of failing silently', function (): void {
    Os::setForcedFamily('Windows');

    expect(CliTool::GH->isInstallableHere())->toBeFalse()
        ->and(CliTool::GH->installsOnWindows())->toBeFalse()
        ->and(CliTool::KUBECTL->installsOnWindows())->toBeTrue();

    if (! CliTool::GH->isInstalled()) {
        expect(CliTool::GH->install())->toBeFalse()
            ->and(CliTool::GH->lastError())->toContain('no native Windows installer');
    }
});
