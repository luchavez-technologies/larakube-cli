<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function cloudAppsInstallKubeconfig(TemporaryDirectory $directory): string
{
    $path = $directory->path('installer.kubeconfig');
    file_put_contents($path, "apiVersion: v1\nkind: Config\ncurrent-context: larakube-cloud-installer\n");

    return $path;
}

/** @return array<string, mixed> */
function cloudAppsInstallResult(string $output): array
{
    $lines = preg_split('/\R/', trim($output)) ?: [];

    return (array) json_decode((string) end($lines), true);
}

test('only listed apps can be installed', function (string $unlisted): void {
    expect(Artisan::call('cloud:apps:install', ['app' => $unlisted, '--json' => true]))->toBe(1)
        ->and(cloudAppsInstallResult(Artisan::output())['error'])->toContain("cannot install {$unlisted}");
})->with(['external-dns', 'nonexistent']);

test('a kubeconfig is required, and nothing runs without one', function (): void {
    Process::fake();

    expect(Artisan::call('cloud:apps:install', ['app' => 'openbao', '--json' => true]))->toBe(1)
        ->and(cloudAppsInstallResult(Artisan::output())['error'])->toContain('kubeconfig');
    Process::assertNothingRan();
});

test('hosts, emails, storage choices and Commons services are checked before anything is installed', function (array $options, string $message): void {
    $directory = TemporaryDirectory::make();
    expect(home_path())->not->toBe((string) (posix_getpwuid(posix_getuid())['dir'] ?? ''));
    Process::preventStrayProcesses();
    Process::fake([
        '*jsonpath={.current-context}*' => Process::result(output: 'larakube-cloud-installer'),
        '*--flatten*' => Process::result(output: "apiVersion: v1\nkind: Config\n"),
    ]);

    expect(Artisan::call('cloud:apps:install', [...$options, '--kubeconfig' => cloudAppsInstallKubeconfig($directory), '--json' => true]))->toBe(1)
        ->and(cloudAppsInstallResult(Artisan::output())['error'])->toContain($message);
    Process::assertDidntRun(fn ($process): bool => str_contains(is_array($process->command) ? implode(' ', $process->command) : (string) $process->command, 'apply'));
})->with([
    'bad host' => [['app' => 'openbao', '--domain' => 'not a host'], 'not a valid host'],
    'bad email' => [['app' => 'wordpress', '--domain' => 'blog.example.com', '--admin-email' => 'nope'], 'admin email'],
    'unknown storage choice' => [['app' => 'wordpress', '--domain' => 'blog.example.com', '--db' => 'oracle'], 'storage choice'],
    'no Commons services' => [['app' => 'plex'], 'Commons services'],
    'unknown Commons service' => [['app' => 'plex', '--plex-services' => 'postgres,mongodb'], 'Commons services'],
]);

test('the given kubeconfig is merged into the default one, never replacing other clusters', function (): void {
    $directory = TemporaryDirectory::make();
    $default = home_path('.kube/config');
    // The test home must never be the real one: this test writes ~/.kube/config.
    expect(home_path())->not->toBe((string) (posix_getpwuid(posix_getuid())['dir'] ?? ''));
    @mkdir(dirname($default), 0700, true);
    file_put_contents($default, "existing\n");
    Process::preventStrayProcesses();
    Process::fake([
        '*jsonpath={.current-context}*' => Process::result(output: 'larakube-cloud-installer'),
        '*--flatten*' => Process::result(output: "merged\n"),
    ]);

    Artisan::call('cloud:apps:install', ['app' => 'plex', '--plex-services' => 'nope', '--kubeconfig' => cloudAppsInstallKubeconfig($directory), '--json' => true]);

    Process::assertRan(fn ($process): bool => ($process->environment['KUBECONFIG'] ?? '') === cloudAppsInstallKubeconfig($directory).':'.$default);
    expect(file_get_contents($default))->toBe("merged\n");
});

test('an app install confirms Commons restarts, since the Install click in LaraKube Cloud is the confirmation', function (): void {
    $command = app(App\Commands\Cloud\CloudAppsInstallCommand::class);
    $command->setLaravel(app());
    $command->setInput(new Symfony\Component\Console\Input\ArrayInput(['app' => 'umami'], $command->getDefinition()));

    $arguments = (fn (): array => $this->initArguments('umami', 'larakube-cloud-installer'))->call($command);

    expect($arguments)->toMatchArray(['--tool' => 'umami', '--confirm-commons-restart' => true, '--no-interaction' => true]);
});

test('Plex Commons accepts every engine the LaraKube CLI can provision, and refuses planned ones', function (): void {
    $directory = TemporaryDirectory::make();
    expect(home_path())->not->toBe((string) (posix_getpwuid(posix_getuid())['dir'] ?? ''));
    Process::preventStrayProcesses();
    Process::fake([
        '*jsonpath={.current-context}*' => Process::result(output: 'larakube-cloud-installer'),
        '*--flatten*' => Process::result(output: "apiVersion: v1\nkind: Config\n"),
    ]);

    Artisan::call('cloud:apps:install', ['app' => 'plex', '--plex-services' => 'typesense', '--kubeconfig' => cloudAppsInstallKubeconfig($directory), '--json' => true]);
    $error = (string) cloudAppsInstallResult(Artisan::output())['error'];

    expect($error)->toContain('Commons services')->toContain('garage')->toContain('meilisearch')->toContain('headless-shell')->not->toContain('typesense');
});
