<?php

use App\Data\ConfigData;
use App\Enums\DeploymentStrategy;
use App\Enums\ServerVariation;

test('Strategy: single-node cloud env gets RWO storage + data PVCs', function (): void {
    $config = new ConfigData(name: 'strat-single');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setStrategy(DeploymentStrategy::SINGLE_NODE);

    $manifests = generateManifestsAsArray($config);

    // App PVCs live per-environment, not in base/.
    expect($manifests)->not->toHaveKey('base/volumes.yaml')
        ->and($manifests)->toHaveKey('overlays/production/app-volumes.yaml');

    // Single-node: both the shared storage PVC and the data PVC, ReadWriteOnce.
    $prod = $manifests['overlays/production/app-volumes.yaml'];
    expect($prod)->toHaveCount(2)
        ->and($prod[0]['spec']['accessModes'][0])->toBe('ReadWriteOnce')
        ->and($prod[1]['spec']['accessModes'][0])->toBe('ReadWriteOnce');

    // No emptyDir swap on single-node.
    expect($manifests)->not->toHaveKey('overlays/production/storage-emptydir.yaml');
});

test('Strategy: multi-node has no shared PVC — app pods use a per-pod emptyDir', function (): void {
    $config = new ConfigData(name: 'strat-multi');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setStrategy(DeploymentStrategy::MULTI_NODE_HA);

    $manifests = generateManifestsAsArray($config);

    // No shared storage PVC on multi-node (block storage can't do RWX across nodes)…
    expect($manifests)->not->toHaveKey('overlays/production/app-volumes.yaml')
        // …an emptyDir patch replaces the app pods' storage volume instead.
        ->and($manifests)->toHaveKey('overlays/production/storage-emptydir.yaml');

    // Local is always single-node → its shared storage PVC stays ReadWriteOnce.
    $local = $manifests['overlays/local/app-volumes.yaml'];
    expect($local[0]['spec']['accessModes'][0])->toBe('ReadWriteOnce');
});

test('multi-node + sharedStorage keeps a shared RWX PVC on the NFS class (no emptyDir)', function (): void {
    $config = ConfigData::from([
        'name' => 'shared-test',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.5',
        'database' => 'postgres',
        'environments' => [
            'local' => [],
            'production' => [
                'strategy' => 'multi-node-ha',
                'sharedStorage' => true,
                'hosts' => ['web' => 'shared.example'],
            ],
        ],
    ]);

    $manifests = generateManifestsAsArray($config);

    // The shared PVC exists, ReadWriteMany, on the in-cluster NFS class…
    $vols = $manifests['overlays/production/app-volumes.yaml'];
    $storage = collect($vols)->first(fn ($d) => str_ends_with($d['metadata']['name'] ?? '', 'laravel-storage-pvc'));
    expect($storage['spec']['accessModes'][0])->toBe('ReadWriteMany')
        ->and($storage['spec']['storageClassName'])->toBe('larakube-nfs');

    // …and NO emptyDir swap patch — the pods share the PVC.
    expect($manifests)->not->toHaveKey('overlays/production/storage-emptydir.yaml');
});

test('the NFS server manifest renders with the backing StorageClass and a readiness probe', function (): void {
    $yaml = view('k8s.nfs.server', ['size' => '10Gi', 'storageClass' => 'do-block-storage'])->render();

    expect($yaml)
        ->toContain('kind: Namespace')
        ->toContain('app: nfs-server')
        ->toContain('storageClassName: do-block-storage')
        ->toContain('readinessProbe');
});

test('the NFS provisioner manifest renders with the larakube-nfs StorageClass and data-safety options', function (): void {
    $yaml = view('k8s.nfs.provisioner', ['archiveOnDelete' => 'true', 'reclaimPolicy' => 'Retain'])->render();

    expect($yaml)
        ->toContain('name: larakube-nfs')
        ->toContain('provisioner: larakube.io/nfs')
        ->toContain('kind: StorageClass')
        ->toContain('kind: PersistentVolume')
        ->toContain('nfs-server.nfs.svc.cluster.local')
        ->toContain('archiveOnDelete: "true"')
        ->toContain('reclaimPolicy: Retain')
        ->toContain('nfsvers=4.1');
});

test('scheduler CronJob gets a cloud wait override that excludes managed services', function (): void {
    $config = ConfigData::from([
        'name' => 'sched-test',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.5',
        'database' => 'postgres',
        'features' => ['scheduler'],
        'environments' => [
            'local' => [],
            'production' => [
                'hosts' => ['web' => 'sched.example'],
                'managed' => ['postgres'],   // externalized → the wait must not nc it
            ],
        ],
    ]);

    $patch = generateManifestsAsArray($config)['overlays/production/deployment-patch.yaml'];
    $cron = collect($patch)->firstWhere('kind', 'CronJob');

    expect($cron)->not->toBeNull();
    $cmd = $cron['spec']['jobTemplate']['spec']['template']['spec']['initContainers'][0]['command'][2];
    expect($cmd)
        ->toContain('curl -sf http://web/up')   // waits for the web pod (migrations)
        ->not->toContain('postgres')            // managed in this env → never waited on
        ->not->toContain('\/');                 // unescaped slashes (kustomize-parseable)
});
