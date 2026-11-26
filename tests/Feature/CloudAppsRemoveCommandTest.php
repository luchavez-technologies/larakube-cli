<?php

use App\Commands\Cloud\CloudAppsInstallCommand;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/** @return array<string, mixed> */
function cloudAppsRemoveResult(string $output): array
{
    $lines = preg_split('/\R/', trim($output)) ?: [];

    return (array) json_decode((string) end($lines), true);
}

test('only apps LaraKube Cloud installs can be removed, and Plex Commons is not one of them', function (string $app): void {
    expect(Artisan::call('cloud:apps:remove', ['app' => $app, '--json' => true]))->toBe(1)
        ->and(cloudAppsRemoveResult(Artisan::output())['error'])->toContain("cannot remove {$app}");
})->with(['plex', 'external-dns', 'nonexistent']);

test('a kubeconfig is required, and nothing runs without one', function (): void {
    Process::fake();

    expect(Artisan::call('cloud:apps:remove', ['app' => 'n8n', '--json' => true]))->toBe(1)
        ->and(cloudAppsRemoveResult(Artisan::output())['error'])->toContain('kubeconfig');
    Process::assertNothingRan();
});

test('a bad host is refused before anything is removed', function (): void {
    $directory = TemporaryDirectory::make();
    expect(home_path())->not->toBe((string) (posix_getpwuid(posix_getuid())['dir'] ?? ''));
    $kubeconfig = $directory->path('installer.kubeconfig');
    file_put_contents($kubeconfig, "apiVersion: v1\nkind: Config\ncurrent-context: larakube-cloud-installer\n");
    Process::preventStrayProcesses();
    Process::fake([
        '*jsonpath={.current-context}*' => Process::result(output: 'larakube-cloud-installer'),
        '*--flatten*' => Process::result(output: "apiVersion: v1\nkind: Config\n"),
    ]);

    expect(Artisan::call('cloud:apps:remove', ['app' => 'n8n', '--domain' => 'not a host', '--kubeconfig' => $kubeconfig, '--json' => true]))->toBe(1)
        ->and(cloudAppsRemoveResult(Artisan::output())['error'])->toContain('not a valid host');
    Process::assertDidntRun(fn ($process): bool => str_contains(is_array($process->command) ? implode(' ', $process->command) : (string) $process->command, 'delete'));
});

test('every app LaraKube Cloud installs has a remove command to run', function (): void {
    $commands = array_keys(Artisan::all());

    foreach (array_diff(CloudAppsInstallCommand::APPS, ['plex']) as $app) {
        expect($commands)->toContain(ClusterTool::from($app)->removeCommand());
    }
});
