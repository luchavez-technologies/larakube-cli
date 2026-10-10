<?php

use App\Traits\InteractsWithClusterCapacity;
use Illuminate\Support\Facades\Process;

function clusterCapacitySubject(): object
{
    return new class
    {
        use InteractsWithClusterCapacity;

        public function snapshot(string $kubectl, float $margin = 0.10): ?App\Data\ClusterCapacitySnapshot
        {
            return $this->clusterCapacitySnapshot($kubectl, $margin);
        }

        public function demand(string $manifest, int $liveNodeCount, int $floorCpu = 50, int $floorMemory = 67108864): array
        {
            return $this->manifestResourceDemand($manifest, $liveNodeCount, $floorCpu, $floorMemory);
        }
    };
}

function twoNodeAllocatableJson(): string
{
    return json_encode(['items' => [
        ['status' => ['allocatable' => ['cpu' => '2', 'memory' => '4Gi']]],
        ['status' => ['allocatable' => ['cpu' => '2', 'memory' => '4Gi']]],
    ]]);
}

function podsRequestingJson(): string
{
    return json_encode(['items' => [
        [
            'status' => ['phase' => 'Running'],
            'spec' => ['containers' => [
                ['resources' => ['requests' => ['cpu' => '500m', 'memory' => '512Mi']]],
            ]],
        ],
        [
            // Completed Job pod — must NOT count toward requested resources.
            'status' => ['phase' => 'Succeeded'],
            'spec' => ['containers' => [
                ['resources' => ['requests' => ['cpu' => '1000m', 'memory' => '1Gi']]],
            ]],
        ],
        [
            'status' => ['phase' => 'Pending'],
            'spec' => ['containers' => [
                ['resources' => ['requests' => ['cpu' => '250m', 'memory' => '256Mi']]],
            ]],
        ],
    ]]);
}

test('snapshot sums allocatable across nodes and requested across running+pending pods only', function (): void {
    Process::fake([
        '*get nodes -o json*' => Process::result(output: twoNodeAllocatableJson()),
        '*get pods -A -o json*' => Process::result(output: podsRequestingJson()),
    ]);

    $snapshot = clusterCapacitySubject()->snapshot('kubectl');

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->nodeCount)->toBe(2)
        ->and($snapshot->allocatableCpuMillicores)->toBe(4000)
        ->and($snapshot->allocatableMemoryBytes)->toBe(2 * 4 * 1024 ** 3)
        // Succeeded pod's 1000m/1Gi must be excluded.
        ->and($snapshot->requestedCpuMillicores)->toBe(750)
        ->and($snapshot->requestedMemoryBytes)->toBe((512 + 256) * 1024 ** 2);
});

test('free headroom reserves the safety margin before subtracting what is requested', function (): void {
    Process::fake([
        '*get nodes -o json*' => Process::result(output: twoNodeAllocatableJson()),
        '*get pods -A -o json*' => Process::result(output: podsRequestingJson()),
    ]);

    $snapshot = clusterCapacitySubject()->snapshot('kubectl', margin: 0.10);

    // Allocatable 4000m * 0.90 = 3600m usable, minus 750m requested = 2850m free.
    expect($snapshot->freeCpuMillicores())->toBe(2850);
});

test('a failed node or pod read returns null instead of a wrong snapshot', function (): void {
    Process::fake([
        '*get nodes -o json*' => Process::result(output: '', exitCode: 1),
        '*get pods -A -o json*' => Process::result(output: podsRequestingJson()),
    ]);

    expect(clusterCapacitySubject()->snapshot('kubectl'))->toBeNull();
});

test('manifest demand sums Deployment replicas directly and multiplies a DaemonSet by the live node count', function (): void {
    $manifest = <<<'YAML'
    apiVersion: apps/v1
    kind: Deployment
    metadata:
      name: app
      namespace: larakube-shared
    spec:
      replicas: 2
      template:
        spec:
          containers:
            - name: app
              resources:
                requests:
                  cpu: 100m
                  memory: 128Mi
    ---
    apiVersion: apps/v1
    kind: DaemonSet
    metadata:
      name: agent
      namespace: larakube-shared
    spec:
      template:
        spec:
          containers:
            - name: agent
              resources:
                requests:
                  cpu: 50m
                  memory: 64Mi
    YAML;

    $demand = clusterCapacitySubject()->demand($manifest, liveNodeCount: 3);

    // Deployment: 100m * 2 replicas = 200m. DaemonSet: 50m * 3 nodes = 150m. Total 350m.
    expect($demand['cpu'])->toBe(350)
        ->and($demand['memory'])->toBe((128 * 2 + 64 * 3) * 1024 ** 2);
});

test('an undeclared container counts at the floor instead of zero', function (): void {
    $manifest = <<<'YAML'
    apiVersion: apps/v1
    kind: Deployment
    metadata:
      name: app
      namespace: larakube-shared
    spec:
      replicas: 1
      template:
        spec:
          containers:
            - name: app
    YAML;

    $demand = clusterCapacitySubject()->demand($manifest, liveNodeCount: 1, floorCpu: 75, floorMemory: 100 * 1024 ** 2);

    expect($demand['cpu'])->toBe(75)
        ->and($demand['memory'])->toBe(100 * 1024 ** 2);
});
