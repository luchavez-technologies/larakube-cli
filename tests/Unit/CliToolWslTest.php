<?php

use App\Enums\CliTool;
use App\Services\WslBridge;
use App\Support\Os;
use Illuminate\Support\Facades\Process;

function cliToolWslDistroListing(string ...$names): string
{
    return "\xFF\xFE".mb_convert_encoding(implode("\n", $names)."\n", 'UTF-16LE', 'UTF-8');
}

afterEach(function (): void {
    Os::setForcedFamily(null);
    WslBridge::setForceWslFeatureEnabled(null);
    putenv('WSLENV');
});

test('on Windows, gh and tea run from the larakube-ubuntu distro through wsl.exe', function (): void {
    Os::setForcedFamily('Windows');
    WslBridge::setForceWslFeatureEnabled(true);
    Process::fake([
        '*--list*--quiet*' => Process::result(output: cliToolWslDistroListing('Ubuntu', 'larakube-ubuntu')),
    ]);

    expect(CliTool::GH->command())->toBe('wsl.exe -d larakube-ubuntu --user larakube --exec gh')
        ->and(CliTool::GH->isAvailable())->toBeTrue()
        ->and(CliTool::GH->isInstalled())->toBeFalse();
});

test('a variable the caller sets reaches the distro\'s tea through WSLENV', function (): void {
    Os::setForcedFamily('Windows');
    WslBridge::setForceWslFeatureEnabled(true);
    putenv('WSLENV=USERPROFILE/p');
    Process::fake([
        '*--list*--quiet*' => Process::result(output: cliToolWslDistroListing('larakube-ubuntu')),
    ]);

    expect(CliTool::TEA->command(['GITEA_SERVER_TOKEN']))->toBe('wsl.exe -d larakube-ubuntu --user larakube --exec tea')
        ->and(getenv('WSLENV'))->toBe('USERPROFILE/p:GITEA_SERVER_TOKEN');
});

test('without the distro, gh on Windows is unavailable rather than pointed at nothing', function (): void {
    Os::setForcedFamily('Windows');
    WslBridge::setForceWslFeatureEnabled(true);
    Process::fake([
        '*--list*--quiet*' => Process::result(output: cliToolWslDistroListing('Ubuntu')),
    ]);

    expect(CliTool::GH->isAvailable())->toBeFalse()
        ->and(CliTool::GH->command())->toBe('gh');
});

test('host-run tools never go through wsl.exe', function (): void {
    Os::setForcedFamily('Windows');
    WslBridge::setForceWslFeatureEnabled(true);
    Process::fake();

    expect(CliTool::KUBECTL->command())->toBe('kubectl');

    Process::assertNotRan(fn ($process) => str_contains((string) $process->command, 'wsl.exe'));
});
