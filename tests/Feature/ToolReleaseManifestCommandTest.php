<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

test('tool:release-manifest describes openbao release manifest as json', function (): void {
    Process::fake();

    $exit = Artisan::call('tool:release-manifest', ['tool' => 'openbao', '--json' => true]);
    expect($exit)->toBe(0);

    $payload = json_decode(Artisan::output(), true);
    expect($payload)->toBeArray()
        ->and($payload['tool'])->toBe('openbao')
        ->and($payload['engine'])->toBe('default')
        ->and($payload['contracts'])->toBe([1])
        ->and($payload['services'])->toHaveCount(1)
        ->and($payload['services'][0]['role'])->toBe('primary')
        ->and($payload['services'][0]['backup_paths'])->toContain('/openbao')
        ->and($payload['images'])->toHaveKeys(['openbao', 'external-secrets', 'reloader']);

    $filesByPath = collect($payload['files'])->keyBy('path');
    expect($filesByPath->has('openbao.blade.php'))->toBeTrue()
        ->and($filesByPath->has('eso.blade.php'))->toBeTrue()
        ->and($filesByPath['openbao.blade.php']['sha256'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($filesByPath['eso.blade.php']['sha256'])->toMatch('/^[a-f0-9]{64}$/');

    $requirementKinds = array_column($payload['requirements'], 'kind');
    expect($requirementKinds)->toEqualCanonicalizing(['oidc', 'vpn']);
});

test('tool:release-manifest describes supabase with 11 services and 11 images', function (): void {
    Process::fake();

    $exit = Artisan::call('tool:release-manifest', ['tool' => 'supabase', '--json' => true]);
    expect($exit)->toBe(0);

    $payload = json_decode(Artisan::output(), true);
    expect($payload)->toBeArray()
        ->and($payload['tool'])->toBe('supabase')
        ->and($payload['services'])->toHaveCount(11)
        ->and($payload['images'])->toHaveCount(11);

    $filePaths = array_column($payload['files'], 'path');
    expect($filePaths)->toContain('partials/wait-for-db.blade.php');
});

test('tool:release-manifest fails non-zero for an unknown tool or unknown engine', function (): void {
    Process::fake();

    $unknownToolExit = Artisan::call('tool:release-manifest', ['tool' => 'nonexistent-tool', '--json' => true]);
    expect($unknownToolExit)->not->toBe(0);

    $unknownEngineExit = Artisan::call('tool:release-manifest', ['tool' => 'openbao', '--engine' => 'nonexistent-engine', '--json' => true]);
    expect($unknownEngineExit)->not->toBe(0);
});
