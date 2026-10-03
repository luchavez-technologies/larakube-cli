<?php

use App\Data\GlobalConfigData;
use App\Data\StackData;
use Illuminate\Support\Facades\Process;

function restartStack(array $overrides = []): string
{
    $key = home_path('.larakube/test-key');
    @mkdir(dirname($key), 0755, true);
    file_put_contents($key, 'key');

    $config = GlobalConfigData::load();
    $config->putStack(new StackData(...($overrides + ['name' => 'workshop-demo', 'provider' => 'do', 'kind' => 'vps', 'region' => 'sgp1', 'ip' => '203.0.113.21', 'context' => 'larakube-203.0.113.21', 'sshKey' => $key])));
    $config->save();

    return $key;
}

test('a server is rebooted over SSH and waited on until SSH and Kubernetes answer', function (): void {
    restartStack();
    Process::fake([
        '*echo success*' => Process::result(output: 'success'),
        '*sudo reboot*' => Process::result(exitCode: 255),
        '*get --raw=/readyz*' => Process::result(output: 'ok'),
    ]);

    $this->artisan('cloud:restart --stack=workshop-demo --force --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('restarted and serving again');

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'larakube@203.0.113.21') && str_contains((string) $process->command, 'sudo reboot'));
});

test('nothing is rebooted when the server cannot be reached first', function (): void {
    restartStack();
    Process::fake(['*' => Process::result(output: '', exitCode: 255)]);

    $this->artisan('cloud:restart --stack=workshop-demo --force --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('Cannot reach workshop-demo over SSH');

    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'sudo reboot'));
});

test('only servers made with cloud:create, with their key still here, can be restarted', function (): void {
    restartStack(['name' => 'no-key', 'sshKey' => '/nonexistent/key']);
    Process::fake(['*' => Process::result(output: 'success')]);

    $this->artisan('cloud:restart --stack=no-key --force --no-interaction')->assertExitCode(1)->expectsOutputToContain('SSH key');
    $this->artisan('cloud:restart --stack=unknown --force --no-interaction')->assertExitCode(1)->expectsOutputToContain("No restartable server named 'unknown'");

    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'sudo reboot'));
});
