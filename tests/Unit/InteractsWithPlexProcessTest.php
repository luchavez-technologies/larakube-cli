<?php

/**
 * Tests for InteractsWithPlex's Process-backed cluster reads. The manifest-
 * apply / database-allocation / bucket-creation paths mix Blade rendering
 * and real kubectl exec against the Commons and are left to a real-cluster
 * smoke test — this covers the simple read-only checks.
 */

use App\Enums\StorageDriver;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Output\BufferedOutput;

function plexProcessHelper(): object
{
    return new class
    {
        use InteractsWithPlex, LaraKubeOutput;

        public function reachable(): bool
        {
            return $this->plexContextReachable();
        }

        public function spec(): ?array
        {
            return $this->getCommonsSpec();
        }

        public function registry(): array
        {
            return $this->getRegistry();
        }

        public function s3Credentials(): ?array
        {
            return $this->readCommonsS3Credentials();
        }

        public function meiliKey(): ?string
        {
            return $this->readCommonsMeiliKey();
        }

        /** @return array{internal: string, public: string} */
        public function endpoints(StorageDriver $driver, string $toolLabel): array
        {
            return $this->resolveCommonsS3Endpoints($driver, $toolLabel);
        }
    };
}

function plexProcessKubectl(): string
{
    return 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';
}

test('plexContextReachable reflects cluster-info exit code', function (): void {
    $kubectl = plexProcessKubectl();

    Process::fake(["{$kubectl} cluster-info --request-timeout=8s" => Process::result(exitCode: 0)]);
    expect(plexProcessHelper()->reachable())->toBeTrue();

    Process::fake(["{$kubectl} cluster-info --request-timeout=8s" => Process::result(exitCode: 1)]);
    expect(plexProcessHelper()->reachable())->toBeFalse();
});

test('getCommonsSpec is null when the plex-commons ConfigMap does not exist yet', function (): void {
    Process::fake([plexProcessKubectl()." get configmap plex-commons -n larakube-plex -o jsonpath='{.data.commons\\.json}'" => Process::result(output: '', exitCode: 1)]);

    expect(plexProcessHelper()->spec())->toBeNull();
});

test('getCommonsSpec decodes the live spec JSON', function (): void {
    $spec = ['version' => 1, 'services' => ['postgres' => ['enabled' => true]]];
    Process::fake([plexProcessKubectl()." get configmap plex-commons -n larakube-plex -o jsonpath='{.data.commons\\.json}'" => json_encode($spec)]);

    expect(plexProcessHelper()->spec())->toBe($spec);
});

test('getRegistry is an empty array when the plex-registry ConfigMap is absent', function (): void {
    Process::fake([plexProcessKubectl()." get configmap plex-registry -n larakube-plex -o jsonpath='{.data.registry\\.json}'" => Process::result(output: '', exitCode: 1)]);

    expect(plexProcessHelper()->registry())->toBe([]);
});

test('readCommonsS3Credentials decodes base64 access/secret keys from the Secret', function (): void {
    $kubectl = plexProcessKubectl();

    Process::fake([
        "{$kubectl} get secret plex-admin -n larakube-plex -o jsonpath='{.data.S3_ACCESS_KEY}'" => base64_encode('AKIA_TEST'),
        "{$kubectl} get secret plex-admin -n larakube-plex -o jsonpath='{.data.S3_SECRET_KEY}'" => base64_encode('shh'),
    ]);

    expect(plexProcessHelper()->s3Credentials())->toBe(['access' => 'AKIA_TEST', 'secret' => 'shh']);
});

test('readCommonsS3Credentials is null when either key is missing', function (): void {
    $kubectl = plexProcessKubectl();

    Process::fake([
        "{$kubectl} get secret plex-admin -n larakube-plex -o jsonpath='{.data.S3_ACCESS_KEY}'" => Process::result(output: '', exitCode: 1),
        "{$kubectl} get secret plex-admin -n larakube-plex -o jsonpath='{.data.S3_SECRET_KEY}'" => base64_encode('shh'),
    ]);

    expect(plexProcessHelper()->s3Credentials())->toBeNull();
});

test('readCommonsMeiliKey decodes the shared master key, or is null when absent', function (): void {
    $kubectl = plexProcessKubectl();

    Process::fake(["{$kubectl} get secret plex-admin -n larakube-plex -o jsonpath='{.data.MEILI_MASTER_KEY}'" => base64_encode('master-key')]);
    expect(plexProcessHelper()->meiliKey())->toBe('master-key');

    Process::fake(["{$kubectl} get secret plex-admin -n larakube-plex -o jsonpath='{.data.MEILI_MASTER_KEY}'" => Process::result(output: '', exitCode: 1)]);
    expect(plexProcessHelper()->meiliKey())->toBeNull();
});

test('resolveCommonsS3Endpoints signs against the Commons public host when one is configured', function (): void {
    $spec = ['services' => ['seaweedfs' => ['enabled' => true, 'host' => 'files.example.com']]];
    Process::fake([plexProcessKubectl()." get configmap plex-commons -n larakube-plex -o jsonpath='{.data.commons\\.json}'" => json_encode($spec)]);

    $endpoints = plexProcessHelper()->endpoints(StorageDriver::SEAWEEDFS, 'TestTool');

    expect($endpoints['public'])->toBe('https://files.example.com')
        ->and($endpoints['internal'])->toBe('http://seaweedfs.larakube-plex.svc.cluster.local:8333');
});

test('resolveCommonsS3Endpoints falls back to the internal endpoint and warns when no public host is set', function (): void {
    $spec = ['services' => ['seaweedfs' => ['enabled' => true]]];
    Process::fake([plexProcessKubectl()." get configmap plex-commons -n larakube-plex -o jsonpath='{.data.commons\\.json}'" => json_encode($spec)]);

    $output = new BufferedOutput;
    \Termwind\renderUsing($output);

    $endpoints = plexProcessHelper()->endpoints(StorageDriver::SEAWEEDFS, 'TestTool');

    expect($endpoints['public'])->toBe($endpoints['internal'])
        ->and($endpoints['internal'])->toBe('http://seaweedfs.larakube-plex.svc.cluster.local:8333')
        ->and($output->fetch())
        ->toContain("The Commons 'seaweedfs' has no public host")
        ->toContain('TestTool')
        ->toContain('plex:init --s3-host=');
});

test('resolveCommonsS3Endpoints uses the right port per storage driver', function (): void {
    $spec = ['services' => ['minio' => ['enabled' => true, 'host' => 'files.example.com']]];
    Process::fake([plexProcessKubectl()." get configmap plex-commons -n larakube-plex -o jsonpath='{.data.commons\\.json}'" => json_encode($spec)]);

    $endpoints = plexProcessHelper()->endpoints(StorageDriver::MINIO, 'TestTool');

    expect($endpoints['internal'])->toBe('http://minio.larakube-plex.svc.cluster.local:'.StorageDriver::MINIO->port())
        ->and($endpoints['public'])->toBe('https://files.example.com');
});
