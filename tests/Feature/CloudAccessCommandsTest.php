<?php

use App\Commands\Cloud\CloudAccessConnectCommand as Connect;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

test('the observer role reads nodes and metrics and can delete only itself', function (): void {
    $rules = Yaml::parse(Connect::observerRoleManifest())['rules'];

    expect($rules)->toHaveCount(3)
        ->and($rules[0]['resources'])->toBe(['nodes'])
        ->and($rules[0]['verbs'])->toBe(['get', 'list', 'watch'])
        ->and($rules[1]['verbs'])->toBe(['get', 'list'])
        ->and($rules[2])->toMatchArray(['resources' => ['clusterroles'], 'resourceNames' => [Connect::OBSERVER], 'verbs' => ['delete']]);
});

test('the identity can only delete its own ServiceAccount, and its rules are owned by it', function (): void {
    [$role, $binding] = array_map(fn (string $doc) => Yaml::parse($doc), explode("---\n", Connect::selfRemovalManifest('uid-1')));

    expect($role['rules'])->toBe([['apiGroups' => [''], 'resources' => ['serviceaccounts'], 'resourceNames' => [Connect::IDENTITY], 'verbs' => ['delete']]])
        ->and($role['metadata']['ownerReferences'][0]['uid'])->toBe('uid-1')
        ->and($binding['metadata']['ownerReferences'][0]['kind'])->toBe('ServiceAccount');
});

test('the read identity removes its role before its account and proves the token is dead', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'read');
    $commands = [];
    $probes = 0;
    Process::fake(function ($process) use (&$commands, &$probes) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'get nodes')) {
            return ++$probes === 1 ? Process::result('node/one') : Process::result(errorOutput: 'error: You must be logged in to the server (Unauthorized)', exitCode: 1);
        }

        return Process::result();
    });

    $this->artisan('cloud:access:disconnect', ['--kubeconfig' => $file, '--no-interaction' => true])->assertExitCode(0);
    @unlink($file);

    $role = array_key_first(array_filter($commands, fn (string $c): bool => str_contains($c, 'delete clusterrole')));
    $account = array_key_first(array_filter($commands, fn (string $c): bool => str_contains($c, 'delete serviceaccount')));
    expect($role)->toBeLessThan($account);
});

test('self-removal reports manual steps when the cluster is unreachable', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'read');
    Process::fake(['*' => Process::result(errorOutput: 'dial tcp: i/o timeout', exitCode: 1)]);

    $this->artisan('cloud:access:disconnect', ['--kubeconfig' => $file, '--no-interaction' => true])
        ->expectsOutputToContain('ssh larakube@<your-server>')
        ->assertExitCode(1);
    @unlink($file);
    Process::assertNotRan(fn ($p): bool => str_contains($p->command, 'delete serviceaccount'));
});

test('the promise never allows Secrets, exec or writes', function (): void {
    $allowed = collect(Connect::PERMISSIONS)->filter(fn (array $p): bool => $p[2]);

    expect($allowed->pluck(0)->unique()->sort()->values()->all())->toBe(['get', 'list'])
        ->and($allowed->pluck(1)->contains('secrets'))->toBeFalse()
        ->and($allowed->pluck(1)->contains('pods/exec'))->toBeFalse();
});

test('disconnect removes the role, binding and identity', function (): void {
    Process::fake(['*' => Process::result()]);

    $this->artisan('cloud:access:disconnect', ['--context' => 'example', '--no-interaction' => true])->assertExitCode(0);

    Process::assertRan(fn ($p): bool => str_contains($p->command, 'delete clusterrolebinding larakube-cloud-observer'));
    Process::assertRan(fn ($p): bool => str_contains($p->command, 'delete clusterrole larakube-cloud-observer'));
    Process::assertRan(fn ($p): bool => str_contains($p->command, 'delete serviceaccount') && str_contains($p->command, 'larakube-cloud'));
});

test('disconnect fails when access is still present', function (): void {
    Process::fake([
        '*get serviceaccount*' => Process::result(output: 'serviceaccount/larakube-cloud'),
        '*' => Process::result(),
    ]);

    $this->artisan('cloud:access:disconnect', ['--context' => 'example', '--no-interaction' => true])->assertExitCode(1);
});

test('disconnect fails when the cluster cannot be reached', function (): void {
    Process::fake(['*' => Process::result(errorOutput: 'context was not found', exitCode: 1)]);

    $this->artisan('cloud:access:disconnect', ['--context' => 'missing', '--no-interaction' => true])->assertExitCode(1);
});

test('connect requires a context', function (): void {
    Process::fake();

    $this->artisan('cloud:access:connect', ['--no-interaction' => true])->assertExitCode(1);
    Process::assertNothingRan();
});
