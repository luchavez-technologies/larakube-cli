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
    Process::fake(["kubectl get deployment 'phpmyadmin' -n larakube-companions --no-headers" => 'phpmyadmin   1/1   1   1   5d']);
    expect(companionsProcessHelper()->installed(CompanionDriver::PHPMYADMIN))->toBeTrue();

    Process::fake(["kubectl get deployment 'phpmyadmin' -n larakube-companions --no-headers" => Process::result(output: '', exitCode: 1)]);
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
