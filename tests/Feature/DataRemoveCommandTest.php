<?php

use App\Commands\Directus\DirectusRemoveCommand;
use App\Commands\PocketBase\PocketBaseRemoveCommand;
use Illuminate\Support\Facades\Process;

test('directus:remove tears down single default instance cleanly', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'data', 'instance' => 'data-dev-test', 'host' => 'data.dev.test'],
        ]))),
        '*get deployment directus-data-dev-test*' => Process::result(output: 'directus-data-dev-test   1/1   1   1   10d'),
        '*get deployment pocketbase*' => Process::result(output: ''),
        '*delete deployment/directus-data-dev-test*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan(DirectusRemoveCommand::class, [
        'environment' => 'local',
        '--force' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);
});

test('directus:remove targets explicit domain instance', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'data', 'instance' => 'blog-dev-test', 'host' => 'blog.dev.test'],
        ]))),
        '*get deployment directus-blog-dev-test*' => Process::result(output: 'directus-blog-dev-test   1/1   1   1   10d'),
        '*get deployment pocketbase*' => Process::result(output: ''),
        '*delete deployment/directus-blog-dev-test*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan(DirectusRemoveCommand::class, [
        'environment' => 'local',
        '--domain' => 'blog.dev.test',
        '--force' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);
});

test('directus:remove hard-errors non-interactively when 2+ instances are registered and neither --domain nor --all was given', function (): void {
    // Previously this silently picked $registered[0] and tore that instance
    // down without telling the operator there was a choice to make — the
    // exact same failure class as the DATA duplicate-registration incident
    // this file's other tests guard against, just at removal time instead of
    // init time. Failing loudly beats guessing.
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'data', 'instance' => 'data-dev-test', 'host' => 'data.dev.test'],
            ['tool' => 'data', 'instance' => 'blog-dev-test', 'host' => 'blog.dev.test'],
        ]))),
    ]);

    $this->artisan(DirectusRemoveCommand::class, [
        'environment' => 'local',
        '--force' => true,
        '--no-interaction' => true,
    ])->run();
})->throws(RuntimeException::class, 'Pass --domain=<host>');

test('directus:remove --all removes all registered instances', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'data', 'instance' => 'data-dev-test', 'host' => 'data.dev.test'],
            ['tool' => 'data', 'instance' => 'blog-dev-test', 'host' => 'blog.dev.test'],
        ]))),
        '*get deployment directus-data-dev-test*' => Process::result(output: 'directus-data-dev-test   1/1   1   1   10d'),
        '*get deployment directus-blog-dev-test*' => Process::result(output: 'directus-blog-dev-test   1/1   1   1   10d'),
        '*get deployment pocketbase*' => Process::result(output: ''),
        '*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan(DirectusRemoveCommand::class, [
        'environment' => 'local',
        '--all' => true,
        '--force' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);
});

test('pocketbase:remove --all --purge deletes every PocketBase instance and its data volume', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'pocketbase', 'instance' => 'data-test', 'host' => 'data.test'],
            ['tool' => 'pocketbase', 'instance' => 'data-second-test', 'host' => 'data-second.test'],
        ]))),
        '*get deployment directus*' => Process::result(output: ''),
        '*get deployment pocketbase-data-test*' => Process::result(output: 'pocketbase-data-test   1/1   1   1   10d'),
        '*get deployment pocketbase-data-second-test*' => Process::result(output: 'pocketbase-data-second-test   1/1   1   1   10d'),
        '*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan(PocketBaseRemoveCommand::class, [
        'environment' => 'local',
        '--all' => true,
        '--purge' => true,
        '--force' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);

    foreach (['data-test', 'data-second-test'] as $instance) {
        Process::assertRan(fn ($process) => str_contains($process->command, "delete deployment/pocketbase-{$instance} "));
        Process::assertRan(fn ($process) => str_contains($process->command, "delete pvc/pocketbase-storage-{$instance} "));
    }
});

test('pocketbase:remove without --purge keeps the PocketBase data volume', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'pocketbase', 'instance' => 'data-test', 'host' => 'data.test'],
        ]))),
        '*get deployment directus*' => Process::result(output: ''),
        '*get deployment pocketbase-data-test*' => Process::result(output: 'pocketbase-data-test   1/1   1   1   10d'),
        '*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan(PocketBaseRemoveCommand::class, [
        'environment' => 'local',
        '--force' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'delete pvc/'));
});
