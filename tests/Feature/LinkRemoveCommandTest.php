<?php

use App\Commands\Link\LinkRemoveCommand;
use Illuminate\Support\Facades\Process;

test('link:remove tears down single default instance cleanly', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'link', 'instance' => 'link-dev-test', 'host' => 'link.dev.test'],
        ]))),
        '*get deployment kutt-link-dev-test*' => Process::result(output: 'kutt-link-dev-test   1/1   1   1   10d'),
        '*delete deployment/kutt-link-dev-test*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan(LinkRemoveCommand::class, [
        'environment' => 'local',
        '--force' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);
});

test('link:remove targets explicit domain instance', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'link', 'instance' => 'go-dev-test', 'host' => 'go.dev.test'],
        ]))),
        '*get deployment kutt-go-dev-test*' => Process::result(output: 'kutt-go-dev-test   1/1   1   1   10d'),
        '*delete deployment/kutt-go-dev-test*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan(LinkRemoveCommand::class, [
        'environment' => 'local',
        '--domain' => 'go.dev.test',
        '--force' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);
});

test('link:remove hard-errors non-interactively when 2+ instances are registered and neither --domain nor --all was given', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'link', 'instance' => 'link-dev-test', 'host' => 'link.dev.test'],
            ['tool' => 'link', 'instance' => 'go-dev-test', 'host' => 'go.dev.test'],
        ]))),
    ]);

    $this->artisan(LinkRemoveCommand::class, [
        'environment' => 'local',
        '--force' => true,
        '--no-interaction' => true,
    ])->run();
})->throws(RuntimeException::class, 'Pass --domain=<host>');

test('link:remove --all removes all registered instances', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'link', 'instance' => 'link-dev-test', 'host' => 'link.dev.test'],
            ['tool' => 'link', 'instance' => 'go-dev-test', 'host' => 'go.dev.test'],
        ]))),
        '*get deployment kutt-link-dev-test*' => Process::result(output: 'kutt-link-dev-test   1/1   1   1   10d'),
        '*get deployment kutt-go-dev-test*' => Process::result(output: 'kutt-go-dev-test   1/1   1   1   10d'),
        '*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan(LinkRemoveCommand::class, [
        'environment' => 'local',
        '--all' => true,
        '--force' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);
});

test('link:remove --purge drops the Commons postgres database and releases the redis index', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'link', 'instance' => 'link-dev-test', 'host' => 'link.dev.test'],
        ]))),
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
            ],
        ]),
        '*get deployment kutt-link-dev-test*' => Process::result(output: 'kutt-link-dev-test   1/1   1   1   10d'),
        '*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan(LinkRemoveCommand::class, [
        'environment' => 'local',
        '--purge' => true,
        '--force' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'kutt_link_dev_test'));
});
