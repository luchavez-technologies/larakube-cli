<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function projectListFolder(array $names): TemporaryDirectory
{
    $root = TemporaryDirectory::make()->deleteWhenDestroyed();

    foreach ($names as $name => $framework) {
        $dir = $root->path($name);
        File::ensureDirectoryExists($dir);
        $config = new ConfigData(name: $name);
        $config->framework = $framework;
        file_put_contents($dir.'/.larakube.json', json_encode($config->toArray()));
    }

    // A folder that is not a project, and one with a blueprint that cannot be read.
    File::ensureDirectoryExists($root->path('notes'));
    File::ensureDirectoryExists($root->path('broken'));
    file_put_contents($root->path('broken/.larakube.json'), '{not json');

    return $root;
}

test('project:list reports the projects in a folder, skipping folders that are not projects', function (): void {
    $root = projectListFolder(['shop' => AppFramework::LARAVEL, 'blog' => AppFramework::NEXTJS]);
    Process::fake(['*pods*' => Process::result(output: json_encode(['items' => []]))]);

    Artisan::call('project:list', ['--path' => $root->path(), '--json' => true]);
    $result = json_decode(trim(Artisan::output()), true);

    expect($result['success'])->toBeTrue()
        ->and(array_column($result['projects'], 'name'))->toBe(['blog', 'shop'])
        ->and($result['projects'][1])->toMatchArray(['name' => 'shop', 'framework' => 'laravel', 'local' => 'stopped'])
        ->and($result['projects'][1]['environments'][0]['name'])->toBe('local');
});

test('a project whose local namespace has a running pod is reported as running', function (): void {
    $root = projectListFolder(['shop' => AppFramework::LARAVEL]);
    $namespace = (new ConfigData(name: 'shop'))->getNamespace('local');
    Process::fake(['*pods*' => Process::result(output: json_encode(['items' => [
        ['metadata' => ['namespace' => $namespace], 'status' => ['phase' => 'Running']],
        ['metadata' => ['namespace' => 'other'], 'status' => ['phase' => 'Pending']],
    ]]))]);

    Artisan::call('project:list', ['--path' => $root->path(), '--json' => true]);

    expect(json_decode(trim(Artisan::output()), true)['projects'][0]['local'])->toBe('running');
});

test('an unreachable cluster makes every project read as stopped, not fail', function (): void {
    $root = projectListFolder(['shop' => AppFramework::LARAVEL]);
    Process::fake(['*' => Process::result(output: '', exitCode: 1)]);

    Artisan::call('project:list', ['--path' => $root->path(), '--json' => true]);

    expect(json_decode(trim(Artisan::output()), true)['projects'][0]['local'])->toBe('stopped');
});

test('a folder with no projects is an empty list', function (): void {
    $root = TemporaryDirectory::make()->deleteWhenDestroyed();
    Process::fake();

    Artisan::call('project:list', ['--path' => $root->path(), '--json' => true]);

    expect(json_decode(trim(Artisan::output()), true))->toBe(['success' => true, 'path' => $root->path(), 'projects' => []]);
});
