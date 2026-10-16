<?php

use App\Commands\UninstallCommand;

test('uninstall command cancels when user declines confirmation', function (): void {
    $this->artisan('uninstall')
        ->expectsConfirmation('Are you sure you want to uninstall the LaraKube CLI?', 'no')
        ->expectsOutputToContain('Uninstall cancelled.')
        ->assertExitCode(0);
});

test('uninstall command executes with --force flag non-interactively', function (): void {
    $this->artisan('uninstall --force')
        ->expectsOutputToContain('LaraKube CLI has been removed from your system.')
        ->assertExitCode(0);
});

test('self-uninstall alias executes identically', function (): void {
    $this->artisan('self-uninstall --force')
        ->expectsOutputToContain('LaraKube CLI has been removed from your system.')
        ->assertExitCode(0);
});

test('cleanShellProfiles removes export and comment lines from shell profiles', function (): void {
    $tempDir = sys_get_temp_dir().'/larakube_test_'.uniqid();
    mkdir($tempDir, 0755, true);

    $zshrc = $tempDir.'/.zshrc';
    $bashrc = $tempDir.'/.bashrc';

    file_put_contents($zshrc, "export FOO=BAR\n# Added by LaraKube Desktop\nexport PATH=\"\$HOME/.larakube/bin:\$PATH\"\nalias ll='ls -la'\n");
    file_put_contents($bashrc, "export PATH=\"\$HOME/.larakube/bin:\$PATH\"\nexport BAZ=QUX\n");

    $command = new UninstallCommand;
    $cleaned = $command->cleanShellProfiles($tempDir);

    expect($cleaned)->toContain($zshrc)
        ->toContain($bashrc);

    $zshContent = file_get_contents($zshrc);
    expect($zshContent)->not->toContain('.larakube/bin')->not->toContain('# Added by LaraKube')
        ->toContain('export FOO=BAR')
        ->toContain("alias ll='ls -la'");

    $bashContent = file_get_contents($bashrc);
    expect($bashContent)->not->toContain('.larakube/bin')
        ->toContain('export BAZ=QUX');

    // Clean up temp dir
    @unlink($zshrc);
    @unlink($bashrc);
    @rmdir($tempDir);
});

test('deleteDirectory recursively removes directories and dotfiles', function (): void {
    $tempDir = sys_get_temp_dir().'/larakube_dir_test_'.uniqid();
    mkdir($tempDir.'/subdir', 0755, true);
    file_put_contents($tempDir.'/file.txt', 'hello');
    file_put_contents($tempDir.'/.dotfile', 'secret');
    file_put_contents($tempDir.'/subdir/nested.txt', 'nested');

    $command = new UninstallCommand;
    $command->deleteDirectory($tempDir);

    expect($tempDir)->not->toBeDirectory();
});
