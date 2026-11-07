<?php

use App\Enums\ClusterTool;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

test('every tool that serves a host has a show command', function (): void {
    $commands = $this->app->make(Kernel::class)->all();

    foreach (ClusterTool::cases() as $tool) {
        if ($tool->service() === null) {
            // ExternalDNS has no ingress of its own — nothing to show a URL for.
            expect($commands)->not->toHaveKey($tool->showCommand());

            continue;
        }

        expect($commands)->toHaveKey($tool->showCommand());
    }
});

test('show commands take the environment as their only positional and default to local', function (): void {
    $commands = $this->app->make(Kernel::class)->all();

    foreach (ClusterTool::cases() as $tool) {
        if ($tool->service() === null) {
            continue;
        }

        $definition = $commands[$tool->showCommand()]->getDefinition();

        expect(array_keys($definition->getArguments()))->toBe(['environment'])
            ->and($definition->getArgument('environment')->getDefault())->toBe('local');
    }
});

test('show exits non-zero and points at init when the tool is not installed', function (): void {
    Process::fake([
        // Empty registry: nothing is installed on this cluster.
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('outline:show local')
        ->assertExitCode(1)
        ->expectsOutputToContain('is not installed')
        ->expectsOutputToContain('tool:init local --tool=outline');
});

test('the tool:show --tool= proxy forwards --domain= to the underlying {tool}:show', function (): void {
    // Regression: the dispatcher's own signature never declared --domain, so
    // `tool:show --tool=pocketbase --domain=X` failed outright ("The
    // --domain option does not exist") before this was fixed — silently
    // breaking any caller (e.g. the Desktop app) that targets one instance
    // through the generic entry point instead of pocketbase:show directly.
    Process::fake([
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*get deployment -n larakube-shared *' => Process::result(output: 'pocketbase-peanut-example-com'),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('tool:show local --tool=pocketbase --domain=peanut.example.com --json --no-interaction');
    $payload = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($payload['tool'])->toBe('pocketbase')
        ->and($payload['instance'])->toBe('peanut-example-com');
});

test('show without --domain returns an array of full instance detail when more than one instance is registered', function (): void {
    // Regression: instanceForHost() used to silently return whichever
    // instance happened to be first in the registry when no --domain was
    // given — so a caller could get the WRONG instance's host/credentials
    // with no indication anything was ambiguous. Must now report every
    // registered instance, in the same shape a single result returns, as an
    // array — never silently collapse to just one.
    Process::fake([
        '*larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'pocketbase', 'instance' => 'data-one-example-com', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'data-one.example.com'],
                ['tool' => 'pocketbase', 'instance' => 'data-two-example-com', 'installedAt' => '2026-08-02T00:00:00+00:00', 'host' => 'data-two.example.com'],
            ])),
        ),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('pocketbase:show local --json --no-interaction');
    $payload = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($payload)->toBeArray()
        ->and(array_column($payload, 'instance'))->toBe([
            'data-one-example-com', 'data-two-example-com',
        ])
        ->and(array_column($payload, 'host'))->toBe([
            'data-one.example.com', 'data-two.example.com',
        ])
        ->and($payload[0])->toHaveKeys(['tool', 'instance', 'environment', 'installed', 'namespace', 'host', 'url', 'wirings', 'components', 'credentials']);
});

test('show without --domain still resolves normally when only one instance is registered', function (): void {
    Process::fake([
        '*larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'pocketbase', 'instance' => 'data-one-example-com', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'data-one.example.com'],
            ])),
        ),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('pocketbase:show local --json --no-interaction');
    $payload = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($payload['instance'])->toBe('data-one-example-com')
        ->and($payload['host'])->toBe('data-one.example.com');
});

test('--json emits a machine-readable object instead of a table', function (): void {
    Process::fake([
        // The registry Secret holds base64'd JSON — a flat list across every tool/instance.
        '*larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'outline', 'instance' => 'main', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'notes.example.com'],
            ])),
        ),
        '*' => Process::result(output: ''),
    ]);

    // Asserted on the raw buffer rather than expectsOutputToContain(): the whole
    // document is emitted as ONE line() call, which that matcher doesn't split.
    $exit = Artisan::call('outline:show local --json');
    $payload = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($payload)->toMatchArray([
            'tool' => 'outline',
            'environment' => 'local',
            'installed' => true,
            'namespace' => 'larakube-shared',
            'host' => 'notes.example.com',
            'url' => 'https://notes.example.com',
        ])
        ->and($payload['wirings'])->toHaveKeys(['sso', 'mail', 'secrets', 'vpn', 'meet'])
        ->and($payload['components'][0])->toHaveKeys(['key', 'role', 'deployment', 'labels'])
        ->and($payload['components'][0]['deployment'])->toEndWith('-main');
});

test('--json reports component readiness and replicas from live cluster deployments', function (): void {
    $deploymentListJson = json_encode([
        'items' => [
            [
                'metadata' => ['name' => 'ocis-drive-main'],
                'spec' => ['replicas' => 1],
                'status' => ['readyReplicas' => 1, 'replicas' => 1],
            ],
            [
                'metadata' => ['name' => 'code-drive-main'],
                'spec' => ['replicas' => 1],
                'status' => ['readyReplicas' => 1, 'replicas' => 1],
            ],
        ],
    ]);

    Process::fake([
        '*larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'ocis', 'instance' => 'drive-main', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'drive.example.com'],
            ])),
        ),
        '*get deployment -n larakube-shared -o json*' => Process::result(output: (string) $deploymentListJson),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('ocis:show local --json');
    $payload = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($payload['components'])->toHaveCount(2)
        ->and($payload['components'][0])->toMatchArray([
            'key' => 'app',
            'deployed' => true,
            'ready' => true,
            'replicas' => '1/1',
            'paused' => false,
        ])
        ->and($payload['components'][1])->toMatchArray([
            'key' => 'code',
            'deployed' => true,
            'ready' => true,
            'replicas' => '1/1',
            'paused' => false,
        ]);
});

test('--json reports paused when a deployment carries the pause annotation', function (): void {
    $deploymentListJson = json_encode([
        'items' => [
            [
                'metadata' => ['name' => 'ocis-drive-main', 'annotations' => ['larakube.app/paused-replicas' => '1']],
                'spec' => ['replicas' => 0],
                'status' => ['readyReplicas' => 0, 'replicas' => 0],
            ],
            [
                'metadata' => ['name' => 'code-drive-main'],
                'spec' => ['replicas' => 0],
                'status' => ['readyReplicas' => 0, 'replicas' => 0],
            ],
        ],
    ]);

    Process::fake([
        '*larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'ocis', 'instance' => 'drive-main', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'drive.example.com'],
            ])),
        ),
        '*get deployment -n larakube-shared -o json*' => Process::result(output: (string) $deploymentListJson),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('ocis:show local --json');
    $payload = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($payload['components'][0])->toMatchArray(['key' => 'app', 'paused' => true])
        ->and($payload['components'][1])->toMatchArray(['key' => 'code', 'paused' => false]);
});

test('OcisTool toolAccessRows includes Collabora Online when code deployment is present', function (): void {
    Process::fake([
        '*get deployment code-drive-main *' => Process::result(output: 'deployment.apps/code-drive-main'),
        '*' => Process::result(output: ''),
    ]);

    $vendor = ClusterTool::OCIS->vendor();
    expect($vendor)->toBeInstanceOf(App\Contracts\HasToolAccessDetails::class);

    $rows = $vendor->toolAccessRows('drive.example.com', 'production', 'kubectl', 'drive-main');
    expect($rows)->toBe([
        ['Collabora Online', 'https://office.drive.example.com'],
    ]);
});

test('OcisTool toolAccessRows returns empty when code deployment is not present', function (): void {
    Process::fake([
        '*' => Process::result(output: ''),
    ]);

    $vendor = ClusterTool::OCIS->vendor();
    $rows = $vendor->toolAccessRows('drive.example.com', 'production', 'kubectl', 'drive-main');
    expect($rows)->toBe([]);
});
