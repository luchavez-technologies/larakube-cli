<?php

/**
 * Tests for ManagesCompanions' real (non-stubbed) Process-backed methods.
 * ShowCompanionAccessTest.php/EnsureProjectCompanionsTest.php both override
 * isCompanionInstalled()/readClusterEnvVars() as plain method stubs, so
 * neither ever exercises the actual kubectl calls migrated here.
 */

use App\Enums\CompanionDriver;
use App\Traits\ManagesCompanions;
use Illuminate\Support\Facades\Process;

function companionsProcessHelper(): object
{
    return new class
    {
        use ManagesCompanions;

        public function installed(CompanionDriver $companion): bool
        {
            return $this->isCompanionInstalled($companion);
        }

        public function clusterEnvVars(string $kind, string $name, string $namespace, bool $base64): array
        {
            return $this->readClusterEnvVars($kind, $name, $namespace, $base64);
        }
    };
}

test('isCompanionInstalled reflects whether the Deployment exists', function (): void {
    Process::fake(['kubectl get deployment/phpmyadmin -n larakube-companions -o name --ignore-not-found' => 'deployment.apps/phpmyadmin']);
    expect(companionsProcessHelper()->installed(CompanionDriver::PHPMYADMIN))->toBeTrue();

    Process::fake(['kubectl get deployment/phpmyadmin -n larakube-companions -o name --ignore-not-found' => Process::result(output: '', exitCode: 1)]);
    expect(companionsProcessHelper()->installed(CompanionDriver::PHPMYADMIN))->toBeFalse();
});

test('readClusterEnvVars decodes a Secret and passes through a ConfigMap', function (): void {
    Process::fake([
        "kubectl get 'secret' 'laravel-secrets' -n 'demo' -o json" => json_encode([
            'data' => ['DB_PASSWORD' => base64_encode('s3cr3t')],
        ]),
    ]);
    expect(companionsProcessHelper()->clusterEnvVars('secret', 'laravel-secrets', 'demo', true))
        ->toBe(['DB_PASSWORD' => 's3cr3t']);

    Process::fake([
        "kubectl get 'configmap' 'laravel-config' -n 'demo' -o json" => json_encode([
            'data' => ['DB_HOST' => 'mariadb.demo.svc.cluster.local'],
        ]),
    ]);
    expect(companionsProcessHelper()->clusterEnvVars('configmap', 'laravel-config', 'demo', false))
        ->toBe(['DB_HOST' => 'mariadb.demo.svc.cluster.local']);
});

test('readClusterEnvVars is empty when the object is missing or the cluster is unreachable', function (): void {
    Process::fake(["kubectl get 'secret' 'laravel-secrets' -n 'demo' -o json" => Process::result(output: '', exitCode: 1)]);

    expect(companionsProcessHelper()->clusterEnvVars('secret', 'laravel-secrets', 'demo', true))->toBe([]);
});

test('removing a companion deletes its Deployment, Service and Ingress in one call', function (): void {
    $cluster = Tests\Support\FakeKubectl::install();

    (new ReflectionMethod($helper = companionsProcessHelper(), 'removeCompanion'))->invoke($helper, CompanionDriver::PHPMYADMIN);

    expect(array_map(fn ($ref) => $ref->key(), $cluster->deleted()))->toBe([
        'larakube-companions/deployment/phpmyadmin',
        'larakube-companions/service/phpmyadmin',
        'larakube-companions/ingress/phpmyadmin',
    ]);
});

test('phpMyAdmin keeps the servers it knows and adds this project\'s', function (): void {
    $cluster = Tests\Support\FakeKubectl::install()
        ->with(['kind' => 'Deployment', 'metadata' => ['name' => 'phpmyadmin', 'namespace' => 'larakube-companions']])
        ->with(['kind' => 'ConfigMap', 'metadata' => ['name' => 'phpmyadmin-hosts', 'namespace' => 'larakube-companions'], 'data' => ['hosts' => 'mysql.other.svc.cluster.local']]);
    $config = (new App\Data\ConfigData(name: 'shop'))->setDatabase(App\Enums\DatabaseDriver::MYSQL);

    (new ReflectionMethod($helper = companionsProcessHelper(), 'refreshPhpMyAdminServers'))->invoke($helper, $config, 'shop');

    $hosts = 'mysql.other.svc.cluster.local,mysql.shop.svc.cluster.local';
    expect($cluster->object(new App\Data\ResourceRef('ConfigMap', 'phpmyadmin-hosts', 'larakube-companions'))['data']['hosts'])->toBe($hosts)
        ->and($cluster->calls())->toContain(['set', 'env', 'deployment/phpmyadmin', "PMA_HOSTS={$hosts}", '-n', 'larakube-companions'])
        ->and($cluster->calls())->toContain(['rollout', 'restart', 'deployment/phpmyadmin', '-n', 'larakube-companions']);
});

test('the companions namespace is applied as a manifest, on the pinned cluster', function (): void {
    $cluster = Tests\Support\FakeKubectl::install();

    (new ReflectionMethod($helper = companionsProcessHelper(), 'ensureCompanionNamespace'))->invoke($helper);

    expect($cluster->has(new App\Data\ResourceRef('Namespace', 'larakube-companions', 'default')))->toBeTrue();
});
