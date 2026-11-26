<?php

use App\Enums\ClusterTool;
use App\Services\K8s\ManifestResourceParser;
use App\Services\K8s\ToolFootprint;

test('every shipped ClusterTool container declares cpu and memory requests', function (ClusterTool $tool): void {
    $parser = new ManifestResourceParser;
    $workloads = [];

    foreach ((new ToolFootprint)->renderManifests($tool) as $rendered) {
        foreach ($parser->parse($rendered) as $workload) {
            $workloads[] = $workload;
        }
    }

    expect($workloads)->not->toBeEmpty("Expected at least one Deployment or DaemonSet for {$tool->value}");

    foreach ($workloads as $workload) {
        foreach ($workload->containers as $container) {
            expect($container->declared)
                ->toBeTrue("{$tool->value} {$workload->kind}/{$workload->name} container {$container->name} is missing resources:")
                ->and($container->requestsCpuMillicores)
                ->not->toBeNull("{$tool->value} {$workload->kind}/{$workload->name} container {$container->name} is missing resources.requests.cpu")
                ->and($container->requestsMemoryBytes)
                ->not->toBeNull("{$tool->value} {$workload->kind}/{$workload->name} container {$container->name} is missing resources.requests.memory");
        }
    }
})->with(fn () => array_map(fn (ClusterTool $tool) => [$tool], ClusterTool::cases()));

test('every Traefik manifest container declares cpu and memory requests', function (string $view): void {
    $rendered = view($view, match ($view) {
        'k8s.traefik-cloud' => ['email' => 'ops@example.com', 'ip' => '203.0.113.10'],
        'k8s.traefik-managed' => ['email' => 'ops@example.com', 'loadBalancerName' => 'lb-example'],
        default => [],
    })->render();

    $workloads = (new ManifestResourceParser)->parse($rendered);
    expect($workloads)->not->toBeEmpty();

    foreach ($workloads as $workload) {
        foreach ($workload->containers as $container) {
            expect($container->declared)->toBeTrue()
                ->and($container->requestsCpuMillicores)->not->toBeNull()
                ->and($container->requestsMemoryBytes)->not->toBeNull();
        }
    }
})->with(['k8s.traefik-cloud', 'k8s.traefik-managed', 'k8s.traefik-install']);

test('ToolFootprint measures positive CPU and memory for every shipped tool and exposes footprint in catalog rows', function (): void {
    $row = ClusterTool::GRAFANA->toCatalogRow();

    expect($row['footprint']['cpu_millicores'])->toBeGreaterThan(0)
        ->and($row['footprint']['memory_bytes'])->toBeGreaterThan(0)
        ->and($row['footprint']['components'])->not->toBeEmpty()
        ->and($row['alternatives'])->toBeArray();

    $slim = (new ToolFootprint)->measure(ClusterTool::GRAFANA, ['no-logs' => true, 'no-traces' => true]);
    expect($slim['memory_bytes'])->toBeLessThan($row['footprint']['memory_bytes']);
});
