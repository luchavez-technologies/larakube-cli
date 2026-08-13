<?php

use App\Commands\Data\DataRemoveCommand;
use Illuminate\Support\Facades\Process;

test('data:remove tears down single default instance cleanly', function () {
    Process::fake([
        '*get secret larakube-tools-registry*' => json_encode([
            ['tool' => 'data', 'instance' => 'main', 'host' => 'data.dev.test'],
        ]),
        '*get deployment data-pocketbase*' => Process::result(output: ''),
        '*get deployment data-directus*' => Process::result(output: 'data-directus   1/1   1   1   10d'),
        '*delete deployment/data-directus*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan(DataRemoveCommand::class, [
        'environment' => 'local',
        '--force' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);
});

test('data:remove targets explicit domain instance', function () {
    Process::fake([
        '*get secret larakube-tools-registry*' => json_encode([
            ['tool' => 'data', 'instance' => 'blog', 'host' => 'blog.dev.test'],
        ]),
        '*get deployment data-pocketbase-blog*' => Process::result(output: ''),
        '*get deployment data-directus-blog*' => Process::result(output: 'data-directus-blog   1/1   1   1   10d'),
        '*delete deployment/data-directus-blog*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan(DataRemoveCommand::class, [
        'environment' => 'local',
        '--domain' => 'blog.dev.test',
        '--force' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);
});

test('data:remove --all removes all registered instances', function () {
    Process::fake([
        '*get secret larakube-tools-registry*' => json_encode([
            ['tool' => 'data', 'instance' => 'main', 'host' => 'data.dev.test'],
            ['tool' => 'data', 'instance' => 'blog', 'host' => 'blog.dev.test'],
        ]),
        '*get deployment data-pocketbase*' => Process::result(output: ''),
        '*get deployment data-directus*' => Process::result(output: 'data-directus   1/1   1   1   10d'),
        '*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan(DataRemoveCommand::class, [
        'environment' => 'local',
        '--all' => true,
        '--force' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);
});
