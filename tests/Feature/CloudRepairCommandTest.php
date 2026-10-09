<?php

use App\Data\GlobalConfigData;
use App\Data\StackData;
use Illuminate\Support\Facades\Process;

function repairStack(array $overrides = []): string
{
    $key = home_path('.larakube/test-repair-key');
    @mkdir(dirname($key), 0755, true);
    file_put_contents($key, 'key');

    $config = GlobalConfigData::load();
    $config->putStack(new StackData(...($overrides + ['name' => 'repair-demo', 'provider' => 'do', 'kind' => 'vps', 'region' => 'sgp1', 'ip' => '203.0.113.60', 'context' => 'larakube-203.0.113.60', 'sshKey' => $key])));
    $config->save();

    return $key;
}

test('an already-registered server is re-provisioned over SSH without ever being destroyed', function (): void {
    repairStack();
    Process::fake([
        '*echo success*' => Process::result(output: 'success'),
        '*' => Process::result(output: 'ok'),
    ]);

    $this->artisan('cloud:repair --stack=repair-demo --force --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('repaired');

    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'cloud:destroy'));
});

test('nothing is attempted when the server cannot be reached over SSH', function (): void {
    repairStack();
    Process::fake(['*' => Process::result(output: '', exitCode: 255)]);

    $this->artisan('cloud:repair --stack=repair-demo --force --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('Cannot reach repair-demo over SSH');
});

test('only a server made with cloud:create/cloud:init, with its key still here, can be repaired', function (): void {
    repairStack(['name' => 'no-key', 'sshKey' => '/nonexistent/key']);
    Process::fake(['*' => Process::result(output: 'success')]);

    $this->artisan('cloud:repair --stack=no-key --force --no-interaction')->assertExitCode(1)->expectsOutputToContain('SSH key');
    $this->artisan('cloud:repair --stack=unknown --force --no-interaction')->assertExitCode(1)->expectsOutputToContain("No repairable server named 'unknown'");
});
