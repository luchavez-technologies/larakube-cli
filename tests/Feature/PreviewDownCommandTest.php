<?php

use App\Enums\AppFramework;
use App\State;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * `preview:down` is the scoped counterpart to `preview:up`. `larakube down`
 * also reclaims the preview, but only by deleting the whole namespace — which
 * takes the dev server, the ConfigMaps and Secrets, and the node_modules PVC
 * with it. That is the gap this closes.
 */
function previewDownProject(AppFramework $framework = AppFramework::VITE): TemporaryDirectory
{
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    file_put_contents($dir->path().'/.larakube.json', json_encode([
        'name' => 'spa',
        'framework' => $framework->value,
    ]));

    return $dir;
}

/** @return array{0: string, 1: array<int, string>} */
function previewDownRun(TemporaryDirectory $dir, array $params = []): array
{
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return Process::result();
    });

    $original = getcwd();
    chdir($dir->path());

    try {
        Artisan::call('preview:down', $params);
        $output = Artisan::output();
    } finally {
        chdir($original);
    }

    return [$output, $commands];
}

test('preview:down removes only the preview workload', function (): void {
    $dir = previewDownProject();
    [$output, $commands] = previewDownRun($dir);

    $delete = collect($commands)->first(fn (string $c) => str_contains($c, 'kubectl delete'));

    expect($delete)->not->toBeNull()
        ->and($delete)->toContain('deployment,service,ingress web-preview')
        ->and($delete)->toContain('-n spa-local')
        // A second run, or a preview that was never brought up, should be a
        // clean no-op rather than three kubectl errors.
        ->and($delete)->toContain('--ignore-not-found');

    // The dev server's own resources are never named here — that is the whole
    // difference between this and `larakube down`.
    expect($delete)->not->toContain(' web ')
        ->and($output)->toContain('is untouched');
});

test('preview:down leaves the image alone unless asked, and says so', function (): void {
    $dir = previewDownProject();
    [$output, $commands] = previewDownRun($dir);

    expect(collect($commands)->contains(fn (string $c) => str_contains($c, 'docker rmi')))->toBeFalse()
        // Named rather than silently left behind: it is the largest thing this
        // command does not clean up.
        ->and($output)->toContain('spa:preview')
        ->and($output)->toContain('--image');
});

test('preview:down --image also drops the built image', function (): void {
    $dir = previewDownProject();
    [, $commands] = previewDownRun($dir, ['--image' => true]);

    expect(collect($commands)->first(fn (string $c) => str_contains($c, 'docker rmi')))
        ->toContain('spa:preview');
});

test('preview:down refuses on a stack that has no preview', function (): void {
    $dir = previewDownProject(AppFramework::LARAVEL);
    [$output, $commands] = previewDownRun($dir);

    // laraKubeError renders through termwind, outside Artisan's buffer, so
    // the headline is asserted where it is actually recorded.
    expect(State::$lastError)->toContain('frontend-only stacks')
        ->and($output)->toContain('Laravel')
        // Crucially it must not delete anything on the way out.
        ->and(collect($commands)->contains(fn (string $c) => str_contains($c, 'kubectl delete')))->toBeFalse();
});
