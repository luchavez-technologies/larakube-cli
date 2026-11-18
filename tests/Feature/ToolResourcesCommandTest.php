<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeToolRegistry;

/** One n8n instance: a Deployment reading two Secrets and a ConfigMap, one of them unlabelled. */
function toolResourcesFakes(): array
{
    FakeToolRegistry::install([['tool' => 'n8n', 'instance' => 'flow-example-com', 'host' => 'flow.example.com', 'engine' => 'n8n']]);

    $deployment = [
        'kind' => 'Deployment',
        'metadata' => ['name' => 'n8n-flow-example-com'],
        'spec' => ['template' => ['spec' => [
            'containers' => [[
                'name' => 'n8n',
                'envFrom' => [['secretRef' => ['name' => 'n8n-smtp-flow-example-com']]],
                'env' => [['name' => 'DB_PASSWORD', 'valueFrom' => ['secretKeyRef' => ['name' => 'n8n-secrets-flow-example-com', 'key' => 'db-password']]]],
            ]],
            'volumes' => [['name' => 'cfg', 'configMap' => ['name' => 'n8n-config-flow-example-com']]],
        ]]],
    ];

    $labelled = [
        // Labelled and not referenced: the custom overlay.
        ['kind' => 'Secret', 'metadata' => ['name' => 'n8n-custom-flow-example-com'], 'type' => 'Opaque', 'data' => ['WEBHOOK_TOKEN' => base64_encode('hidden')]],
        ['kind' => 'ConfigMap', 'metadata' => ['name' => 'n8n-config-flow-example-com'], 'data' => ['TZ' => 'UTC']],
    ];

    // Referenced but carrying no labels: written by a wire command.
    $unlabelled = ['kind' => 'List', 'items' => [
        ['kind' => 'Secret', 'metadata' => ['name' => 'n8n-smtp-flow-example-com'], 'type' => 'Opaque', 'data' => ['N8N_SMTP_PASS' => base64_encode('smtp-pass')]],
        ['kind' => 'Secret', 'metadata' => ['name' => 'n8n-secrets-flow-example-com'], 'type' => 'Opaque', 'data' => ['db-password' => base64_encode('rotating'), 'encryption-key' => base64_encode('static')]],
    ]];

    $externalSecrets = ['items' => [[
        'metadata' => ['name' => 'n8n-secrets-flow-example-com-db'],
        'spec' => ['target' => ['name' => 'n8n-secrets-flow-example-com'], 'data' => [['secretKey' => 'db-password']]],
    ]]];

    return [
        '*get deployment,statefulset,daemonset*' => Process::result(output: json_encode(['items' => [$deployment]])),
        '*get configmap,secret*' => Process::result(output: json_encode(['items' => $labelled])),
        '*get secret n8n-smtp-flow-example-com n8n-secrets-flow-example-com*' => Process::result(output: json_encode($unlabelled)),
        '*get secret n8n-secrets-flow-example-com n8n-smtp-flow-example-com*' => Process::result(output: json_encode($unlabelled)),
        '*get externalsecret*' => Process::result(output: json_encode($externalSecrets)),
        '*get secret/n8n-secrets-flow-example-com*' => Process::result(output: json_encode($unlabelled['items'][1])),
        '*' => Process::result(output: ''),
    ];
}

test('tool:resources finds what the workloads reference, labelled or not, with each key\'s owner', function (): void {
    Process::fake(toolResourcesFakes());

    Artisan::call('tool:resources', ['--tool' => 'n8n', '--json' => true]);
    $resources = collect(json_decode(Artisan::output(), true)['resources'])->keyBy('name');

    expect($resources->keys()->all())->toEqualCanonicalizing([
        'n8n-config-flow-example-com', 'n8n-custom-flow-example-com', 'n8n-secrets-flow-example-com', 'n8n-smtp-flow-example-com',
    ]);

    $owners = fn (string $name): array => collect($resources[$name]['keys'])->pluck('owner', 'key')->all();

    expect($owners('n8n-secrets-flow-example-com'))->toBe(['db-password' => 'rotated', 'encryption-key' => 'managed'])
        ->and($owners('n8n-custom-flow-example-com'))->toBe(['WEBHOOK_TOKEN' => 'custom'])
        ->and($owners('n8n-smtp-flow-example-com'))->toBe(['N8N_SMTP_PASS' => 'managed'])
        ->and($resources['n8n-config-flow-example-com']['keys'][0])->toBe(['key' => 'TZ', 'owner' => 'managed', 'value' => 'UTC']);

    expect($resources['n8n-custom-flow-example-com']['custom'])->toBeTrue()
        ->and($resources['n8n-smtp-flow-example-com']['custom'])->toBeFalse();
});

test('tool:resources never prints a Secret value', function (): void {
    Process::fake(toolResourcesFakes());

    Artisan::call('tool:resources', ['--tool' => 'n8n', '--json' => true]);
    $output = Artisan::output();

    expect($output)->not->toContain('hidden')
        ->not->toContain('smtp-pass')
        ->not->toContain('rotating')
        ->not->toContain(base64_encode('rotating'));
});

test('tool:secret reveals one key of a Secret the instance uses', function (): void {
    Process::fake(toolResourcesFakes());

    Artisan::call('tool:secret', ['--tool' => 'n8n', '--name' => 'n8n-secrets-flow-example-com', '--key' => 'db-password', '--json' => true]);

    expect(json_decode(Artisan::output(), true))->toBe([
        'success' => true, 'name' => 'n8n-secrets-flow-example-com', 'key' => 'db-password', 'value' => 'rotating',
    ]);
});

test('tool:secret refuses a Secret the instance does not use', function (): void {
    Process::fake(toolResourcesFakes());

    $exit = Artisan::call('tool:secret', ['--tool' => 'n8n', '--name' => 'netbird-secrets-vpn-example-com', '--key' => 'pat', '--json' => true]);

    expect($exit)->toBe(1)
        ->and(json_decode(Artisan::output(), true)['success'])->toBeFalse();
    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'get secret/netbird'));
});
