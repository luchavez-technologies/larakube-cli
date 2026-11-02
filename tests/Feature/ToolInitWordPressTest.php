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

test('tool:init --tool=wordpress --custom-env/--custom-env-secret seed the overlay before the Deployment is first applied', function (): void {
    $applied = ['configMaps' => [], 'secrets' => []];

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: ''),
        '*get deployment -l larakube.io/tool=windmill*' => Process::result(output: ''),
        '*get deployment -l larakube.io/tool=meet*' => Process::result(output: ''),
        '*get deployment*' => Process::result(output: ''),
        '*create namespace*' => Process::result(output: 'namespace/larakube-shared created'),
        '*apply*' => function ($process) use (&$applied) {
            $object = json_decode((string) $process->input, true);
            if (($object['kind'] ?? null) === 'ConfigMap') {
                $applied['configMaps'][(string) $object['metadata']['name']] = $object;
            } elseif (($object['kind'] ?? null) === 'Secret') {
                $applied['secrets'][(string) $object['metadata']['name']] = $object;
            }

            return Process::result(output: 'applied');
        },
        '*rollout status*' => Process::result(output: 'deployment successfully rolled out'),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('tool:init local --tool=wordpress --domain=blog.example.com --admin-email=admin@example.com --db=sqlite --custom-env=FEATURE_X=true --custom-env-secret=WEBHOOK_SECRET=super-secret --no-interaction');

    expect($exit)->toBe(0);

    $configMapName = array_key_first(array_filter(
        $applied['configMaps'],
        fn (array $o) => ($o['data']['FEATURE_X'] ?? null) === 'true',
    ));
    $secretName = array_key_first(array_filter(
        $applied['secrets'],
        fn (array $o) => base64_decode((string) ($o['data']['WEBHOOK_SECRET'] ?? '')) === 'super-secret',
    ));

    expect($configMapName)->not->toBeNull()
        ->and((string) $configMapName)->toStartWith('wordpress-custom-')
        ->and($secretName)->toBe($configMapName);
});

test('tool:init --tool=wordpress refuses a malformed --custom-env pair before touching the cluster', function (): void {
    $sawClusterCall = false;

    Process::fake(function ($process) use (&$sawClusterCall) {
        $sawClusterCall = true;

        return Process::result(output: '');
    });

    $exit = Artisan::call('tool:init local --tool=wordpress --domain=blog.example.com --admin-email=admin@example.com --custom-env=NOEQUALSSIGN --no-interaction --force');

    expect($exit)->toBe(1)
        ->and($sawClusterCall)->toBeFalse();
});

test('wordpress:remove also deletes the custom env overlay', function (): void {
    Process::fake([...registeredToolRemoveFakes('wordpress:remove', 'tool-example-com'),
        '*delete*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('wordpress:remove local --force')->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'configmap/wordpress-custom-tool-example-com')
        && str_contains($process->command, 'secret/wordpress-custom-tool-example-com'));
});

test('wordpress deployment wires the custom env overlay via envFrom, ahead of its own managed env', function (): void {
    $rendered = view('k8s.wordpress.wordpress', [
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
        'customEnvConfigMap' => 'data-wordpress-test-custom',
        'customEnvSecret' => 'data-wordpress-test-custom',
    ])->render();

    // WordPress's manifest has several multi-doc sections and inline shell
    // blocks that trip a generic `---`-split YAML parse (duplicate-key
    // false positives) — asserting on the rendered text directly is both
    // simpler and sufficient to prove the envFrom block is wired onto the
    // `wordpress` container specifically, ahead of its own managed env.
    $containerBlock = substr($rendered, (int) strpos($rendered, '- name: wordpress'));

    expect($containerBlock)
        ->toContain("envFrom:\n            - configMapRef:\n                name: data-wordpress-test-custom\n            - secretRef:\n                name: data-wordpress-test-custom")
        ->and(strpos($containerBlock, 'envFrom:'))->toBeLessThan(strpos($containerBlock, "\n          env:"));
});

test('wordpress manifest view renders valid resources for sqlite and mysql', function (): void {
    $sqliteManifest = view('k8s.wordpress.wordpress', [
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
        'customEnvConfigMap' => 'data-wordpress-test-custom',
        'customEnvSecret' => 'data-wordpress-test-custom',
    ])->render();

    expect($sqliteManifest)->toContain('kind: PersistentVolumeClaim')
        ->and($sqliteManifest)->toContain('mountPath: /var/www/html')
        ->and($sqliteManifest)->toContain('name: seed-core')
        ->and($sqliteManifest)->toContain('name: init-sqlite')
        ->and($sqliteManifest)->toContain('name: seed-platform')
        ->and($sqliteManifest)->toContain('name: auto-install')
        ->and($sqliteManifest)->toContain('wp_install')
        ->and($sqliteManifest)->toContain('fsGroup: 33')
        ->and($sqliteManifest)->toContain('sqlite-database-integration')
        ->and($sqliteManifest)->toContain('image: wordpress:7.1-php8.3-apache')
        ->and($sqliteManifest)->toContain('kind: Service')
        ->and($sqliteManifest)->toContain('kind: Ingress');

    $mysqlManifest = view('k8s.wordpress.wordpress', [
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
        'customEnvConfigMap' => 'data-wordpress-test-custom',
        'customEnvSecret' => 'data-wordpress-test-custom',
    ])->render();

    expect($mysqlManifest)->not->toContain('name: init-sqlite')
        ->and($mysqlManifest)->toContain('mountPath: /var/www/html')
        ->and($mysqlManifest)->toContain('name: seed-core')
        ->and($mysqlManifest)->toContain('name: auto-install')
        ->and($mysqlManifest)->toContain('wp_install')
        ->and($mysqlManifest)->toContain('mysql.larakube-plex.svc.cluster.local:3306')
        ->and($mysqlManifest)->toContain('WP_REDIS_DATABASE');
});
