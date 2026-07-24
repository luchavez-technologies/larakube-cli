<?php

use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

/**
 * `{tool}:remove {environment}` replaces `{tool}:init --remove`. These cover
 * the behaviour the shared base owns for all 24 tools, plus the per-tool
 * teardown details that were previously only exercised through the init
 * command's --remove branch.
 */
test('tool:remove takes the environment as its only positional', function () {
    foreach (ClusterTool::cases() as $tool) {
        $definition = $this->app
            ->make(Illuminate\Contracts\Console\Kernel::class)
            ->all()[$tool->removeCommand()]
            ->getDefinition();

        expect(array_keys($definition->getArguments()))->toBe(['environment']);
    }
});

test('every tool has a remove command and none of them still accept --remove on init', function () {
    $commands = $this->app->make(Illuminate\Contracts\Console\Kernel::class)->all();

    foreach (ClusterTool::cases() as $tool) {
        expect($commands)->toHaveKey($tool->removeCommand());

        expect($commands[$tool->initCommand()]->getDefinition()->hasOption('remove'))
            ->toBeFalse("{$tool->initCommand()} still carries the decoupled --remove flag");
    }
});

test('flow:remove drops both engine databases and deletes the resources', function () {
    Process::fake([
        // A non-empty flow-secrets means this install leased a Commons tenant.
        '*get secret flow-secrets*' => Process::result(output: 'flow-secrets'),
        '*exec *' => Process::result(output: 'dropped'),
        '*delete *' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('flow:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain("Dropping database 'n8n' from Plex Commons")
        ->expectsOutputToContain("Dropping database 'windmill' from Plex Commons")
        ->expectsOutputToContain('Removing Flow resources...');
});

test('flow:remove skips the Commons drop for a bundled --no-plex install', function () {
    Process::fake([
        // No flow-secrets: the install bundled its own SQLite storage, so there
        // is no Commons tenant — dropping one would hit a database this install
        // never owned.
        '*get secret flow-secrets*' => Process::result(output: '', exitCode: 1),
        '*delete *' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('flow:remove local --force')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('Dropping database');
});

test('--keep-data removes workloads but leaves the Commons database alone', function () {
    Process::fake([
        '*get secret flow-secrets*' => Process::result(output: 'flow-secrets'),
        '*delete *' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('flow:remove local --force --keep-data')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('Dropping database')
        ->expectsOutputToContain('Removing Flow resources...');
});

test('a failed delete exits non-zero instead of reporting success', function () {
    // The bug this guards: every tool's remove path used to discard the step
    // result and print "removed" regardless of what kubectl actually did.
    Process::fake([
        '*get secret flow-secrets*' => Process::result(output: '', exitCode: 1),
        '*delete *' => Process::result(output: 'forbidden', exitCode: 1),
    ]);

    $this->artisan('flow:remove local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('failed to remove');
});

test('namespace-wholesale tools delete their own namespace and nothing shared', function () {
    foreach ([ClusterTool::PASSWORDS, ClusterTool::VPN] as $tool) {
        Process::fake([
            '*delete namespace*' => Process::result(output: 'deleted'),
            '*' => Process::result(output: ''),
        ]);

        $this->artisan("{$tool->removeCommand()} local --force")->assertExitCode(0);

        Process::assertRan(fn ($process) => str_contains($process->command, "delete namespace {$tool->namespace()}")
            && ! str_contains($process->command, 'larakube-shared'));
    }
});

test('mail:remove closes the firewall ports it opened', function () {
    Process::fake([
        '*delete *' => Process::result(output: 'deleted'),
        '*wait *' => Process::result(output: ''),
        '*get secrets*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    // A mail server that's gone but whose SMTP ports stay open is a real
    // exposure, so teardown must reach the firewall too.
    $this->artisan('mail:remove local --force')->assertExitCode(0);
});
