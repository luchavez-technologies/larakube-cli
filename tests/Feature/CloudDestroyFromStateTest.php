<?php

use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function destroyFromStateEnv(array $values): Closure
{
    $previous = [];
    foreach ($values as $key => $value) {
        $previous[$key] = getenv($key);
        putenv("{$key}={$value}");
    }

    return function () use ($previous): void {
        foreach ($previous as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
    };
}

function destroyFromStateFixture(): array
{
    $home = TemporaryDirectory::make()->deleteWhenDestroyed();
    $_SERVER['HOME'] = $home->path();
    $tofu = $home->path().'/tofu';
    file_put_contents($tofu, "#!/bin/sh\nexit 0\n");
    chmod($tofu, 0755);
    $credentials = $home->path().'/gcp.json';
    file_put_contents($credentials, '{"type":"service_account","project_id":"example"}');

    $restore = destroyFromStateEnv([
        'LARAKUBE_TOFU_STATE_ID' => '01jexamplestateid000000000',
        'LARAKUBE_TOFU_STATE_BUCKET' => 'example-bucket',
        'LARAKUBE_TOFU_STATE_ENDPOINT' => 'https://example.com',
        'LARAKUBE_TOFU_PASSPHRASE' => 'not-a-real-passphrase-000',
    ]);

    return [$home, $tofu, $credentials, $restore];
}

function destroyFromStateArgs(string $credentials): array
{
    return ['stack' => 'cloud-server', '--provider' => 'gcp', '--force' => true, '--gcp-project' => 'example-project', '--gcp-credentials' => $credentials];
}

test('destroys from remote state with no registry entry, keyed by the state ID', function (): void {
    [$home, $tofu, $credentials, $restore] = destroyFromStateFixture();
    $lists = 0;

    Process::fake([
        'command -v tofu' => Process::result($tofu),
        '*tofu* version*' => Process::result('OpenTofu v1.13.9'),
        '*state list*' => function () use (&$lists) {
            return Process::result(++$lists === 1 ? "google_compute_address.larakube\ngoogle_compute_instance.larakube" : '');
        },
        '*init*' => Process::result(),
        '*destroy*' => Process::result(),
        '*' => Process::result(),
    ]);

    try {
        $this->artisan('cloud:destroy', destroyFromStateArgs($credentials))->assertExitCode(0);

        expect((string) file_get_contents($home->path().'/.larakube/tofu/cloud-server/backend.tf'))
            ->toContain('tofu-state/01jexamplestateid000000000/terraform.tfstate');
        Process::assertRan(fn ($process): bool => str_contains($process->command, ' destroy ') && str_contains($process->command, '-refresh=false'));
    } finally {
        $restore();
    }
});

test('reports failure when resources remain in state after destroy', function (): void {
    [$home, $tofu, $credentials, $restore] = destroyFromStateFixture();

    Process::fake([
        'command -v tofu' => Process::result($tofu),
        '*state list*' => Process::result('google_compute_instance.larakube'),
        '*' => Process::result(),
    ]);

    try {
        $this->artisan('cloud:destroy', destroyFromStateArgs($credentials))->assertExitCode(1);
    } finally {
        $restore();
    }
});

test('treats a stack that never wrote state as already destroyed', function (): void {
    [$home, $tofu, $credentials, $restore] = destroyFromStateFixture();

    Process::fake([
        'command -v tofu' => Process::result($tofu),
        '*tofu* version*' => Process::result('OpenTofu v1.13.9'),
        '*state list*' => Process::result(errorOutput: 'No state file was found!', exitCode: 1),
        '*' => Process::result(),
    ]);

    try {
        $this->artisan('cloud:destroy', destroyFromStateArgs($credentials))->assertExitCode(0);

        Process::assertDidntRun(fn ($process): bool => str_contains($process->command, ' destroy '));
    } finally {
        $restore();
    }
});

test('still refuses when the remote state cannot be read', function (): void {
    [$home, $tofu, $credentials, $restore] = destroyFromStateFixture();

    Process::fake([
        'command -v tofu' => Process::result($tofu),
        '*tofu* version*' => Process::result('OpenTofu v1.13.9'),
        '*state list*' => Process::result(errorOutput: 'Error loading state: AccessDenied', exitCode: 1),
        '*' => Process::result(),
    ]);

    try {
        $this->artisan('cloud:destroy', destroyFromStateArgs($credentials))->assertExitCode(1);

        Process::assertDidntRun(fn ($process): bool => str_contains($process->command, ' destroy '));
    } finally {
        $restore();
    }
});

test('refuses without the state passphrase and never runs tofu', function (): void {
    [$home, $tofu, $credentials, $restore] = destroyFromStateFixture();
    $restorePassphrase = destroyFromStateEnv(['LARAKUBE_TOFU_PASSPHRASE' => '']);
    Process::fake(['*' => Process::result()]);

    try {
        $this->artisan('cloud:destroy', destroyFromStateArgs($credentials))->assertExitCode(1);
        Process::assertNotRan(fn ($process): bool => str_contains($process->command, ' destroy'));
    } finally {
        $restorePassphrase();
        $restore();
    }
});

test('rejects a malformed state ID', function (): void {
    [$home, $tofu, $credentials, $restore] = destroyFromStateFixture();
    $restoreId = destroyFromStateEnv(['LARAKUBE_TOFU_STATE_ID' => '../other']);
    Process::fake(['command -v tofu' => Process::result($tofu), '*' => Process::result()]);

    try {
        expect(fn () => $this->artisan('cloud:destroy', destroyFromStateArgs($credentials))->run())->toThrow(RuntimeException::class);
    } finally {
        $restoreId();
        $restore();
    }
});
