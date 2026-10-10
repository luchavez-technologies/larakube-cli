<?php

use App\Http\Integrations\OpenBao\Requests\DynamicNoBodyRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

/** A single, nearly-full node — small enough that Forgejo's real resources: block won't fit. */
function tinyOneNodeClusterFakes(): array
{
    return [
        '*get nodes -o json*' => Process::result(output: json_encode(['items' => [
            ['status' => ['allocatable' => ['cpu' => '1', 'memory' => '1Gi']]],
        ]])),
        '*get pods -A -o json*' => Process::result(output: json_encode(['items' => [
            [
                'status' => ['phase' => 'Running'],
                'spec' => ['containers' => [['resources' => ['requests' => ['cpu' => '900m', 'memory' => '900Mi']]]]],
            ],
        ]])),
    ];
}

test('tool:init --tool=forgejo deploys forgejo using plex commons seaweedfs by default', function (): void {
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
        '*get secret forgejo-admin*' => Process::result(output: '', exitCode: 1),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('tool:init --tool=forgejo local --no-interaction --admin-email=admin@example.com')
        ->assertExitCode(0)
        ->expectsOutputToContain('Creating object-storage bucket')
        ->expectsOutputToContain('Applying Forgejo core manifests...')
        ->expectsOutputToContain('Initializing Forgejo admin user...')
        ->expectsOutputToContain('Forgejo forge and Actions runner are live.');
});

test('tool:init --tool=forgejo never registers an OpenBao static role itself — only secrets:wire may hand rotation over', function (): void {
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
        '*get secret forgejo-admin*' => Process::result(output: '', exitCode: 1),
        '*get secret openbao-secrets-secrets-example-com*' => Process::result(output: base64_encode('hvs.token')),
        '*port-forward*' => Process::result(output: ''),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*' => Process::result(),
    ]);

    // Only resolveManagedDbPassword()'s read-only lookup should ever hit
    // OpenBao's HTTP API from :init — nothing here is a static-role write.
    Saloon::fake([
        DynamicNoBodyRequest::class => openBaoFake([
            '*/v1/sys/mounts' => ['data' => ['database/' => ['type' => 'database']]],
            '*/v1/database/static-creds/forgejo' => ['data' => []],
        ], default: ['data' => []]),
    ]);

    $this->artisan('tool:init --tool=forgejo local --no-interaction --admin-email=admin@example.com')
        ->assertExitCode(0)
        ->expectsOutputToContain('Forgejo forge and Actions runner are live.');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'externalsecret'));
    Saloon::assertNotSent(fn ($request) => str_contains($request->resolveEndpoint(), '/v1/database/static-roles/'));
});

test('tool:init --tool=forgejo deploys standalone forgejo when --no-plex is passed', function (): void {
    Process::fake([
        '*get secret forgejo-admin*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*exec *' => Process::result(output: 'success'),
    ]);

    $this->artisan('tool:init --tool=forgejo local --no-plex --no-interaction --admin-email=admin@example.com')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Forgejo core manifests...')
        ->expectsOutputToContain('Initializing Forgejo admin user...')
        ->expectsOutputToContain('Forgejo forge and Actions runner are live.');
});

test('tool:init --tool=forgejo fails when --admin-email is missing in non-interactive mode', function (): void {
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => ['seaweedfs' => ['enabled' => true]],
        ]),
        '*get secret plex-admin*' => base64_encode('test-cred'),
        '*exec *' => Process::result(output: 'success'),
    ]);

    $this->artisan('tool:init --tool=forgejo local --no-interaction');
})->throws(App\Exceptions\MissingFlagException::class, 'Missing required --admin-email');

test('tool:init --tool=forgejo registers itself in the cluster tool registry, including the admin email', function (): void {
    // Regression guard: tool:init --tool=forgejo's only registry write was an incidental
    // side effect of resolveToolBranding() saving a custom --app-name/
    // --logo-url — which only fires when one was actually passed. Every
    // plain tool:init --tool=forgejo left Forgejo entirely absent from the registry.
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
        '*get secret forgejo-admin*' => Process::result(output: '', exitCode: 1),
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

    $this->artisan('tool:init --tool=forgejo local --no-interaction --admin-email=admin@example.com')
        ->assertExitCode(0);

    expect($captured)->not->toBeNull();
    $gitEntry = collect($captured)->firstWhere('tool', 'git');
    expect($gitEntry)->not->toBeNull()
        ->and($gitEntry['host'])->not->toBeNull()
        ->and($gitEntry['adminEmail'])->toBe('admin@example.com');
});

test('tool:init --tool=forgejo reads and keeps the brand name on its own instance row when the registry holds other git rows', function (): void {
    // With more than one git row, an instance-less lookup matched nothing:
    // every run prompted for the brand again and appended another row.
    $registry = [
        ['tool' => 'git', 'instance' => 'git-example-com', 'host' => 'git.example.com', 'brandName' => 'Acme Git'],
        ['tool' => 'git', 'instance' => '', 'host' => 'git.example.com'],
        ['tool' => 'git', 'instance' => null, 'brandName' => 'Acme Git'],
    ];
    $captured = null;
    $manifests = [];

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
        '*get secret larakube-tools-registry*' => base64_encode(json_encode($registry)),
        '*create secret generic larakube-tools-registry*' => function ($process) use (&$captured) {
            if (preg_match('/--from-file=registry\.json=(\S+)/', $process->command, $m)) {
                $captured = json_decode(file_get_contents($m[1]), true);
            }

            return Process::result();
        },
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *larakube-forgejo*' => function ($process) use (&$manifests) {
            if (preg_match('/apply -f (\S+larakube-forgejo\S*\.yaml)/', $process->command, $m)) {
                $manifests[] = file_get_contents(trim($m[1], "'"));
            }

            return Process::result(output: 'applied');
        },
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('tool:init --tool=forgejo local --domain=git.example.com --no-interaction --admin-email=admin@example.com')
        ->assertExitCode(0);

    expect($manifests)->toHaveCount(2)->each->toContain('value: "Acme Git"');

    $gitRows = collect($captured)->where('tool', 'git');
    expect($gitRows)->toHaveCount(3)
        ->and($gitRows->firstWhere('instance', 'git-example-com')['brandName'])->toBe('Acme Git');
});

// forgejo:remove's own coverage lives in GitRemoveCommandTest.php (the
// resource-set regression test) rather than duplicated here.

test('tool:init --tool=forgejo refuses on a cluster without enough free capacity, non-interactively and without --force', function (): void {
    Process::fake([
        ...tinyOneNodeClusterFakes(),
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
                'seaweedfs' => ['enabled' => true],
            ],
        ]),
        '*get secret plex-admin*' => base64_encode('test-cred'),
        '*get secret forgejo-admin*' => Process::result(output: '', exitCode: 1),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('tool:init --tool=forgejo local --no-interaction --admin-email=admin@example.com')
        ->assertExitCode(1)
        ->expectsOutputToContain('This cluster may not have enough free capacity for this install');

    // The guard must stop BEFORE the Forgejo manifest itself is ever applied
    // (namespace creation also shells out through "apply -f" and legitimately
    // still runs ahead of the guard, so this checks the actual manifest file).
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'larakube-forgejo.yaml'));
});

test('tool:init --tool=forgejo --force proceeds past a cluster without enough free capacity', function (): void {
    Process::fake([
        ...tinyOneNodeClusterFakes(),
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
                'seaweedfs' => ['enabled' => true],
            ],
        ]),
        '*get secret plex-admin*' => base64_encode('test-cred'),
        '*get secret forgejo-admin*' => Process::result(output: '', exitCode: 1),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('tool:init --tool=forgejo local --no-interaction --admin-email=admin@example.com --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Proceeding despite low free cluster capacity (--force).');
});
