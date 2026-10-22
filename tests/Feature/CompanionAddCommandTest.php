<?php

use Illuminate\Support\Facades\Process;

function companionAddFakes(): void
{
    Process::fake([
        '*get namespace larakube-system*' => Process::result(output: 'Active'),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*which dnsmasq*' => Process::result(output: ''),
        '*apply -f *' => Process::result(output: 'applied'),
        '*' => Process::result(),
    ]);
}

test('companion:add creates the larakube-companions namespace before applying the companion, so a first-ever companion on a cluster actually deploys', function (): void {
    companionAddFakes();

    $this->artisan('companion:add pgadmin --no-interaction')
        ->expectsConfirmation('Would you like LaraKube to sync your /etc/hosts?', 'yes')
        ->assertExitCode(0)
        ->expectsOutputToContain('pgAdmin installed.');

    // One apply for the larakube-companions Namespace, one for the
    // Deployment/Service/Ingress manifest — both must have run.
    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'apply -f'), 2);
});

test('companion:add reports failure instead of a false success when the manifest apply fails', function (): void {
    Process::fake([
        '*get namespace larakube-system*' => Process::result(output: 'Active'),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*which dnsmasq*' => Process::result(output: ''),
        '*apply -f *' => Process::result(output: '', errorOutput: 'namespaces "larakube-companions" not found', exitCode: 1),
        '*' => Process::result(),
    ]);

    $this->artisan('companion:add pgadmin --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('Failed to install pgAdmin.')
        ->doesntExpectOutputToContain('pgAdmin installed.');
});
