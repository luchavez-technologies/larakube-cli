<?php

use App\Exceptions\MissingFlagException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/** A Commons with Postgres, Redis and SeaweedFS all enabled, no tenants yet. */
function plexProvisionFakes(array $overrides = []): array
{
    return array_merge([
        '*cluster-info*' => Process::result(output: 'Kubernetes control plane is running'),
        '*get configmap plex-commons*' => Process::result(output: (string) json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
                'seaweedfs' => ['enabled' => true],
            ],
        ])),
        '*get configmap plex-registry*' => Process::result(output: (string) json_encode(['tenants' => []])),
        '*S3_ACCESS_KEY*' => Process::result(output: base64_encode('AK')),
        '*S3_SECRET_KEY*' => Process::result(output: base64_encode('SK')),
        '*exec*' => Process::result(output: 'OK'),
        '*create configmap plex-registry*' => Process::result(output: 'configured'),
        '*' => Process::result(output: ''),
    ], $overrides);
}

/** plex:provision always prints human lines too, even under --json — only the LAST line is the JSON result. */
function plexProvisionJsonReport(array $arguments): array
{
    expect(Artisan::call('plex:provision', array_merge(
        ['environment' => 'local', '--context' => 'test-ctx'],
        $arguments,
        ['--json' => true, '--force' => true],
    )))->toBe(0);

    $lines = preg_split('/\R/', trim(Artisan::output())) ?: [];

    return json_decode((string) end($lines), true);
}

test('plex:provision refuses when the target context is unreachable', function (): void {
    Process::fake([
        '*cluster-info*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('plex:provision local --tenant=my-app --context=nope --force')
        ->assertExitCode(1)
        ->expectsOutputToContain("context 'nope' is unreachable");
});

test('plex:provision requires the Commons to be initialized first', function (): void {
    Process::fake(plexProvisionFakes([
        '*get configmap plex-commons*' => Process::result(output: ''),
    ]));

    $this->artisan('plex:provision local --tenant=my-app --context=test-ctx --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('Run `larakube plex:init` first');
});

test('plex:provision rejects a requested service that is not enabled on this Commons', function (): void {
    Process::fake(plexProvisionFakes([
        '*get configmap plex-commons*' => Process::result(output: (string) json_encode([
            'version' => 1,
            'services' => ['postgres' => ['enabled' => true]],
        ])),
    ]));

    $this->artisan('plex:provision local --tenant=my-app --context=test-ctx --force --service=s3')
        ->assertExitCode(1)
        ->expectsOutputToContain('None of the requested services (s3) are enabled');
});

test('plex:provision allocates every enabled service for a new tenant and reports it in --json', function (): void {
    Process::fake(plexProvisionFakes());

    $report = plexProvisionJsonReport(['--tenant' => 'my-side-project']);

    expect($report['success'])->toBeTrue()
        ->and($report['tenant'])->toBe('my_side_project') // plexTenantIdentifier() sanitizes hyphens to underscores
        ->and($report['credentials']['database']['database'])->toBe('my_side_project')
        ->and($report['credentials']['database']['password'])->not->toBeEmpty()
        ->and($report['credentials']['redis']['index'])->toBeInt()
        ->and($report['credentials']['s3']['bucket'])->toBe('my-side-project')
        ->and($report['credentials']['s3']['accessKey'])->toBe('AK')
        ->and($report['skipped'])->toBe([]);
});

test('plex:provision never resets an already-provisioned database\'s password on a second run', function (): void {
    Process::fake(plexProvisionFakes([
        '*get configmap plex-registry*' => Process::result(output: (string) json_encode(['tenants' => [
            'my_side_project' => ['db' => 'my_side_project', 'db_service' => 'postgres'],
        ]])),
    ]));

    $report = plexProvisionJsonReport(['--tenant' => 'my-side-project', '--service' => ['db']]);

    expect($report['success'])->toBeTrue()
        ->and($report['credentials'])->toBe([])
        ->and($report['skipped'])->toBe(['database (already provisioned — see note below)']);

    // No SQL exec should have been issued at all for an already-provisioned database.
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'exec -i'));
});

test('plex:provision aborts without --force unless the user confirms', function (): void {
    Process::fake(plexProvisionFakes());

    $this->artisan('plex:provision local --tenant=my-app --context=test-ctx')
        ->expectsConfirmation("Provision Commons credentials for 'my_app'?", 'no')
        ->assertExitCode(0)
        ->expectsOutputToContain('Aborted.');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'exec -i'));
});

test('plex:provision without --tenant fails loudly rather than guessing', function (): void {
    Process::fake(plexProvisionFakes());

    $this->artisan('plex:provision local --context=test-ctx --force');
})->throws(MissingFlagException::class, 'Missing required --tenant');
