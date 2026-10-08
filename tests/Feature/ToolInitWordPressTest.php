<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

test('tool:init deploys 1-click WordPress with SQLite by default', function (): void {
    $appliedManifest = '';

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: ''),
        '*get deployment -l larakube.io/tool=windmill*' => Process::result(output: ''),
        '*get deployment -l larakube.io/tool=meet*' => Process::result(output: ''),
        '*has deployment*' => Process::result(output: ''),
        '*get deployment*' => Process::result(output: ''),
        '*create namespace*' => Process::result(output: 'namespace/larakube-shared created'),
        '*putSecret*' => Process::result(output: 'secret/data-wordpress-blog-example-com-secrets created'),
        '*apply*' => function ($process) use (&$appliedManifest) {
            $appliedManifest .= $process->command;

            return Process::result(output: 'applied');
        },
        '*rollout status*' => Process::result(output: 'deployment successfully rolled out'),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('tool:init local --tool=wordpress --domain=blog.example.com --admin-email=admin@example.com --db=sqlite --no-interaction');

    expect($exit)->toBe(0);
});

test('tool:init deploys 1-click WordPress with Commons MySQL when --db=mysql', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: ''),
        '*get deployment -l larakube.io/tool=windmill*' => Process::result(output: ''),
        '*get deployment -l larakube.io/tool=meet*' => Process::result(output: ''),
        '*get deployment*' => Process::result(output: ''),
        '*create namespace*' => Process::result(output: 'namespace/larakube-shared created'),
        '*configmap plex-commons*' => Process::result(output: json_encode(['services' => ['mysql' => ['enabled' => true]]])),
        '*apply*' => Process::result(output: 'applied'),
        '*rollout status*' => Process::result(output: 'deployment successfully rolled out'),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('tool:init local --tool=wordpress --domain=wp.example.com --admin-email=admin@example.com --db=mysql --no-interaction');

    expect($exit)->toBe(0);
});

test('wordpress manifest view renders valid resources for sqlite and mysql', function (): void {
    $sqliteManifest = view('k8s.data.wordpress', [
        'volumeSize' => fn ($name, $default) => $default,
        'deployName' => 'data-wordpress-test',
        'namespace' => 'larakube-shared',
        'labels' => ['app' => 'wordpress'],
        'instance' => 'test',
        'host' => 'blog.test',
        'aliasHosts' => [],
        'secretName' => 'data-wordpress-test-secrets',
        'smtpSecretName' => 'data-wordpress-test-smtp',
        'dbEngine' => 'sqlite',
        'dbName' => 'SQLite (embedded)',
        'dbHost' => '127.0.0.1',
        'pvcName' => 'data-wordpress-test-pvc',
        'plexNamespace' => 'larakube-plex',
        'isLocal' => true,
        'vpnOnly' => false,
        'proxied' => false,
        'redisIndex' => null,
    ])->render();

    expect($sqliteManifest)->toContain('kind: PersistentVolumeClaim')
        ->and($sqliteManifest)->toContain('name: init-sqlite')
        ->and($sqliteManifest)->toContain('sqlite-database-integration')
        ->and($sqliteManifest)->toContain('image: wordpress:6.7-php8.3-apache')
        ->and($sqliteManifest)->toContain('kind: Service')
        ->and($sqliteManifest)->toContain('kind: Ingress');

    $mysqlManifest = view('k8s.data.wordpress', [
        'volumeSize' => fn ($name, $default) => $default,
        'deployName' => 'data-wordpress-test',
        'namespace' => 'larakube-shared',
        'labels' => ['app' => 'wordpress'],
        'instance' => 'test',
        'host' => 'blog.test',
        'aliasHosts' => [],
        'secretName' => 'data-wordpress-test-secrets',
        'smtpSecretName' => 'data-wordpress-test-smtp',
        'dbEngine' => 'mysql',
        'dbName' => 'data_wordpress',
        'dbHost' => 'mysql.larakube-plex.svc.cluster.local:3306',
        'pvcName' => 'data-wordpress-test-pvc',
        'plexNamespace' => 'larakube-plex',
        'isLocal' => true,
        'vpnOnly' => false,
        'proxied' => false,
        'redisIndex' => 2,
    ])->render();

    expect($mysqlManifest)->not->toContain('name: init-sqlite')
        ->and($mysqlManifest)->toContain('mysql.larakube-plex.svc.cluster.local:3306')
        ->and($mysqlManifest)->toContain('WP_REDIS_DATABASE');
});
