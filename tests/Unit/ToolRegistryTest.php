<?php

use App\Enums\ClusterTool;
use App\Services\ToolRegistry;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeToolRegistry;

function toolRegistryRows(): array
{
    return [
        ['tool' => 'flow', 'instance' => 'flow-example-com', 'host' => 'flow.example.com', 'aliases' => []],
        ['tool' => 'data', 'instance' => 'data-example-com', 'host' => 'data.example.com'],
        ['tool' => 'data', 'instance' => 'cms-example-com', 'host' => 'cms.example.com'],
    ];
}

test('reads the registry Secret and parses its list', function (): void {
    Process::fake(['*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode(toolRegistryRows())))]);

    $registry = ToolRegistry::on('kubectl');

    expect($registry->rows())->toHaveCount(3)
        ->and($registry->hosts(ClusterTool::DATA))->toBe(['data.example.com', 'cms.example.com']);
    Process::assertRan(fn (PendingProcess $p) => $p->command === "kubectl get secret larakube-tools-registry -n larakube-shared -o jsonpath='{.data.registry\\.json}'");
});

test('a missing Secret reads as an empty registry', function (): void {
    Process::fake(['*' => Process::result(output: '', exitCode: 1)]);

    expect(ToolRegistry::on('kubectl')->rows())->toBeEmpty();
});

test('no instance given means the tool\'s sole row, never a guess between several', function (): void {
    $registry = FakeToolRegistry::install(toolRegistryRows());

    expect($registry->host(ClusterTool::FLOW))->toBe('flow.example.com')
        ->and($registry->entry(ClusterTool::DATA))->toBeNull()
        ->and($registry->host(ClusterTool::DATA, 'cms-example-com'))->toBe('cms.example.com');
});

test('a host resolves to its registered instance, or derives one when unregistered', function (): void {
    $registry = FakeToolRegistry::install(toolRegistryRows());

    expect($registry->instanceForHost(ClusterTool::DATA, 'https://CMS.example.com/'))->toBe('cms-example-com')
        ->and($registry->instanceForHost(ClusterTool::DATA, 'new.example.com'))->toBe('new-example-com')
        ->and($registry->targetsForHost(ClusterTool::DATA, ''))->toBe(['data-example-com', 'cms-example-com'])
        ->and($registry->targetsForHost(ClusterTool::SIGN, ''))->toBe(['']);
});

test('register heals a legacy row but never overwrites a different instance', function (): void {
    $registry = FakeToolRegistry::install([['tool' => 'mail', 'instance' => '', 'host' => 'send.example.com']]);
    $registry->register(ClusterTool::MAIL, ['host' => 'send.example.com'], 'send-example-com');

    expect($registry->stored)->toHaveCount(1)
        ->and($registry->stored[0]['instance'])->toBe('send-example-com');

    $registry->register(ClusterTool::MAIL, ['host' => 'other.example.com'], 'other-example-com');

    expect($registry->stored)->toHaveCount(2);
});

test('aliases and unregistering change only the matched row', function (): void {
    $registry = FakeToolRegistry::install(toolRegistryRows());

    $registry->addAlias(ClusterTool::FLOW, 'n8n.example.com');
    $registry->unregister(ClusterTool::DATA, 'cms-example-com');

    expect($registry->aliases(ClusterTool::FLOW))->toBe(['n8n.example.com'])
        ->and($registry->instanceSlugs(ClusterTool::DATA))->toBe(['data-example-com'])
        ->and($registry->writes)->toHaveCount(2);
});
