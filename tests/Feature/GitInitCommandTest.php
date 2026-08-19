<?php

use Illuminate\Support\Facades\Process;

test('git:init deploys gitea using plex commons seaweedfs by default', function (): void {
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

test('git:init never registers an OpenBao static role itself — only secrets:wire may hand rotation over', function (): void {
    // {tool}:init must not know or care whether OpenBao is installed; it
    // just writes a locally-generated password directly into git-secrets
    // (see the Deployment template's db-password key, rendered straight
    // from the PHP variable). Only secrets:wire may register a tool's DB
    // password as an OpenBao static role. Design principle stated explicitly
    // 2026-08-18, after a live incident: :init doing this eagerly meant a
    // tool's password silently became OpenBao-managed the moment OpenBao
    // existed on the cluster, with no explicit secrets:wire ever run — the
    // opposite of what secrets:wire's own description promises ("hand a
    // tool's DB password over to OpenBao static-role rotation").
    // resolveManagedDbPassword() is the one exception: a READ-only check so
    // a re-run doesn't clobber a password OpenBao already owns from a PAST
    // secrets:wire run — it never itself registers anything.
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
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*port-forward*' => Process::result(output: ''),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*' => Process::result(),
    ]);

    // Only resolveManagedDbPassword()'s read-only lookup should ever hit
    // OpenBao's HTTP API from :init — nothing here is a static-role write.
    Http::fake(function (Illuminate\Http\Client\Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $path === '/v1/sys/mounts' => Http::response(['data' => ['database/' => ['type' => 'database']]]),
            $path === '/v1/database/static-creds/forgejo' => Http::response(['data' => []]),
            default => Http::response(['data' => []]),
        };
    });

    $this->artisan('git:init local --no-interaction --admin-email=admin@example.com')
        ->assertExitCode(0)
        ->expectsOutputToContain('Forgejo forge and Actions runner are live.');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'externalsecret'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/database/static-roles/'));
});

test('git:init deploys standalone gitea when --no-plex is passed', function (): void {
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

test('git:init fails when --admin-email is missing in non-interactive mode', function (): void {
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

test('git:init registers itself in the cluster tool registry, including the admin email', function (): void {
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

test('git:remove removes gitea stack and deletes resources', function (): void {
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
