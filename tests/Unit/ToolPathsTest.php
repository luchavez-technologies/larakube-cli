<?php

use App\Enums\CliTool;
use App\Services\CliTools\ToolPaths;
use App\Support\Os;

/**
 * A real script in the sandboxed home (no Process::fake(): the record is
 * bypassed under fakes), which logs every run so a test can count them.
 */
function toolPathsFakeBinary(string $name, string $version = 'Client Version: v1.34.1'): string
{
    $dir = home_path('bin');
    @mkdir($dir, 0755, true);
    $path = "{$dir}/{$name}";
    file_put_contents($path, "#!/bin/sh\necho run >> ".escapeshellarg("{$path}.runs")."\necho ".escapeshellarg($version)."\n");
    chmod($path, 0755);

    return $path;
}

function toolPathsRuns(string $path): int
{
    return is_file("{$path}.runs") ? count(file("{$path}.runs") ?: []) : 0;
}

afterEach(function (): void {
    Os::setForcedFamily(null);
});

test('a recorded location is used instead of searching PATH', function (): void {
    $kubectl = toolPathsFakeBinary('kubectl');
    app(ToolPaths::class)->remember('kubectl', $kubectl);

    // Searching would find the machine's own kubectl (or none); the record wins.
    expect(CliTool::KUBECTL->resolveBinary())->toBe($kubectl);
});

test('a recorded tool that is gone is evicted and searched for again', function (): void {
    Os::setForcedFamily('Windows');
    app(ToolPaths::class)->remember('k9s', '/nowhere/k9s.exe');

    expect(CliTool::K9S->resolveBinary())->toBeNull()
        ->and(app(ToolPaths::class)->entry('k9s'))->toBeNull();
});

test('a version is reused while the binary is unchanged, and read again once it changes', function (): void {
    $kubectl = toolPathsFakeBinary('kubectl');
    app(ToolPaths::class)->remember('kubectl', $kubectl);

    expect(CliTool::KUBECTL->service()->version($kubectl))->toBe('Client Version: v1.34.1')
        ->and(CliTool::KUBECTL->service()->version($kubectl))->toBe('Client Version: v1.34.1')
        ->and(toolPathsRuns($kubectl))->toBe(1);

    // An upgrade rewrites the binary (a different size), so its version is read again.
    toolPathsFakeBinary('kubectl', 'Client Version: v1.35.0-with-a-longer-name');
    clearstatcache();

    expect(CliTool::KUBECTL->service()->version($kubectl))->toBe('Client Version: v1.35.0-with-a-longer-name')
        ->and(toolPathsRuns($kubectl))->toBe(2);
});

test('tools.json is its own file and never touches config.json', function (): void {
    @mkdir(home_path('.larakube'), 0700, true);
    file_put_contents(home_path('.larakube/config.json'), '{"tofuPassphrases":{"prod":"secret"}}');

    app(ToolPaths::class)->remember('kubectl', toolPathsFakeBinary('kubectl'), 'v1');

    expect(file_get_contents(home_path('.larakube/config.json')))->toBe('{"tofuPassphrases":{"prod":"secret"}}')
        ->and(app(ToolPaths::class)->entry('kubectl')['version'])->toBe('v1');
});

test('remember() writes nothing when the entry is unchanged', function (): void {
    $kubectl = toolPathsFakeBinary('kubectl');
    $paths = app(ToolPaths::class);
    $paths->remember('kubectl', $kubectl, 'v1');
    touch($paths->path(), 1_000_000);
    clearstatcache();

    $paths->remember('kubectl', $kubectl, 'v1');
    clearstatcache();

    expect(filemtime($paths->path()))->toBe(1_000_000);
});
