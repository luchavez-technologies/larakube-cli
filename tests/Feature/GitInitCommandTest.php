<?php

use Illuminate\Support\Facades\Process;

test('git:init deploys gitea using plex commons seaweedfs by default', function () {
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
                'seaweedfs' => ['enabled' => true],
            ],
        ]),
        '*get secret plex-admin*' => base64_encode('test-cred'),
        '*get secret gitea-admin*' => Process::result(output: '', exitCode: 1),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('git:init local --no-interaction --admin-email=admin@example.com')
        ->assertExitCode(0)
        ->expectsOutputToContain('Creating object-storage bucket')
        ->expectsOutputToContain('Applying Forgejo core manifests...')
        ->expectsOutputToContain('Initializing Forgejo admin user...')
        ->expectsOutputToContain('Forgejo forge and Actions runner are live.');
});

test('git:init deploys standalone gitea when --no-plex is passed', function () {
    Process::fake([
        '*get secret gitea-admin*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*exec *' => Process::result(output: 'success'),
    ]);

    $this->artisan('git:init local --no-plex --no-interaction --admin-email=admin@example.com')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Forgejo core manifests...')
        ->expectsOutputToContain('Initializing Forgejo admin user...')
        ->expectsOutputToContain('Forgejo forge and Actions runner are live.');
});

test('git:init fails when --admin-email is missing in non-interactive mode', function () {
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => ['seaweedfs' => ['enabled' => true]],
        ]),
        '*get secret plex-admin*' => base64_encode('test-cred'),
        '*exec *' => Process::result(output: 'success'),
    ]);

    $this->artisan('git:init local --no-interaction');
})->throws(App\Exceptions\MissingFlagException::class, 'Missing required --admin-email');

test('git:init registers itself in the cluster tool registry, including the admin email', function () {
    // Regression guard: git:init's only registry write was an incidental
    // side effect of resolveToolBranding() saving a custom --app-name/
    // --logo-url — which only fires when one was actually passed. Every
    // plain git:init left Forgejo entirely absent from the registry.
    $captured = null;

    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
                'seaweedfs' => ['enabled' => true],
            ],
        ]),
        '*get secret plex-admin*' => base64_encode('test-cred'),
        '*get secret gitea-admin*' => Process::result(output: '', exitCode: 1),
        '*get secret larakube-tools-registry*' => Process::result(output: ''),
        // Must come BEFORE '*apply -f *': saveToolRegistry()'s own command
        // pipes into `kubectl apply -f -`, which the broader pattern below
        // would otherwise match first (Process::fake matches in array order).
        '*create secret generic larakube-tools-registry*' => function ($process) use (&$captured) {
            if (preg_match('/--from-file=registry\.json=(\S+)/', $process->command, $m)) {
                $captured = json_decode(file_get_contents($m[1]), true);
            }

            return Process::result();
        },
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('git:init local --no-interaction --admin-email=admin@example.com')
        ->assertExitCode(0);

    expect($captured)->not->toBeNull();
    $gitEntry = collect($captured)->firstWhere('tool', 'git');
    expect($gitEntry)->not->toBeNull()
        ->and($gitEntry['host'])->not->toBeNull()
        ->and($gitEntry['adminEmail'])->toBe('admin@example.com');
});

test('git:remove removes gitea stack and deletes resources', function () {
    Process::fake([
        // Gitea leases a Commons tenant, so teardown drops it before deleting
        // the workloads — the exec is the psql that runs the DROP.
        '*exec *' => Process::result(output: 'dropped'),
        '*delete *' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('git:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Forgejo resources...')
        ->expectsOutputToContain('removed from larakube-shared');
});
