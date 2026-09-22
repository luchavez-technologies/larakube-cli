<?php

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;
use Illuminate\Support\Facades\Process;

test('tasks manifest carries canonical resource naming and identity labels', function (): void {
    $manifest = view('k8s.tasks.shared', [
        'host' => 'tasks.example.test',
        'plexNamespace' => 'larakube-plex',
        'vpnOnly' => false,
        'isLocal' => true,
    ])->render();

    expect($manifest)
        ->toContain('name: planka-tasks-example-test')
        ->toContain('larakube.io/tool: tasks')
        ->toContain('larakube.io/component: planka')
        ->toContain('larakube.io/instance: tasks-example-test')
        ->toContain('name: planka-secrets-tasks-example-test')
        ->toContain('postgres://planka_tasks_example_test:$(DB_PASSWORD)@postgres.larakube-plex.svc.cluster.local:5432/planka_tasks_example_test');
});

test('tasks ingress carries canonical naming and proxy annotations', function (): void {
    $cloud = view('k8s.tasks.shared', [
        'host' => 'tasks.luchtech.dev',
        'plexNamespace' => 'larakube-plex',
        'vpnOnly' => false,
        'isLocal' => false,
        'proxied' => true,
    ])->render();

    expect($cloud)
        ->toContain('name: planka-tasks-luchtech-dev')
        ->toContain('external-dns.alpha.kubernetes.io/cloudflare-proxied: "true"');
});

test('tasks ToolInstance resolves canonical database, secrets, and deployment', function (): void {
    $instance = ToolInstance::forHost(ClusterTool::TASKS, 'tasks.example.test', 'planka');

    expect($instance->deployment())->toBe('planka-tasks-example-test')
        ->and($instance->secret())->toBe('planka-secrets-tasks-example-test')
        ->and($instance->secret(SecretKind::SMTP))->toBe('planka-smtp-tasks-example-test')
        ->and($instance->database())->toBe('planka_tasks_example_test');
});

test('tasks:init provisions canonical resources and secret', function (): void {
    Process::fake([
        '*plex-commons*' => Process::result(output: '{"services":{"postgres":{"enabled":true}}}'),
        '*plex-registry*' => Process::result(output: '{"tenants":{}}'),
        '*create namespace*' => Process::result(output: 'created'),
        '*get secret*' => Process::result(output: base64_encode('secret-val')),
        '*exec*' => Process::result(output: 'success'),
        '*apply*' => Process::result(output: 'created'),
        '*rollout status*' => Process::result(output: 'deployment "planka-tasks-dev-test" successfully rolled out'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('tasks:init local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Planka tasks stack is live.');

    Process::assertRan(fn ($process) => str_contains((string) $process->command, 'rollout status deployment/planka-'));
});

test('tasks:remove --purge drops the Commons database and removes canonical resources', function (): void {
    Process::fake([...registeredToolRemoveFakes('tasks:remove', 'tasks-example-com', 'tasks.example.com'),
        '*exec *' => Process::result(output: 'dropped'),
        '*delete *' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('tasks:remove local --force --purge')
        ->assertExitCode(0)
        ->expectsOutputToContain("Dropping database 'planka_tasks_example_com' from Plex Commons");

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete') && str_contains($process->command, 'planka-tasks-example-com'));
});
