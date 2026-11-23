<?php

use App\Commands\Cloud\CloudAccessConnectCommand as Connect;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

test('the observer role only reads nodes and metrics', function (): void {
    $rules = collect(explode("---\n", Connect::observerManifest()))->map(fn (string $doc) => Yaml::parse($doc))->first()['rules'];

    expect($rules)->toHaveCount(2)
        ->and($rules[0]['resources'])->toBe(['nodes'])
        ->and(collect($rules)->pluck('verbs')->flatten()->unique()->sort()->values()->all())->toBe(['get', 'list', 'watch']);
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

test('connect requires a context', function (): void {
    Process::fake();

    $this->artisan('cloud:access:connect', ['--no-interaction' => true])->assertExitCode(1);
    Process::assertNothingRan();
});
