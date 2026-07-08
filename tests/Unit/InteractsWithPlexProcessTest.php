<?php

/**
 * Tests for InteractsWithPlex's Process-backed cluster reads. The manifest-
 * apply / database-allocation / bucket-creation paths mix Blade rendering
 * and real kubectl exec against the Commons and are left to a real-cluster
 * smoke test — this covers the simple read-only checks.
 */

use App\Traits\InteractsWithPlex;
use Illuminate\Support\Facades\Process;

function plexProcessHelper(): object
{
    return new class
    {
        use InteractsWithPlex;

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
    };
}

test('plexContextReachable reflects cluster-info exit code', function () {
    Process::fake(['kubectl cluster-info --request-timeout=8s' => Process::result(exitCode: 0)]);
    expect(plexProcessHelper()->reachable())->toBeTrue();

    Process::fake(['kubectl cluster-info --request-timeout=8s' => Process::result(exitCode: 1)]);
    expect(plexProcessHelper()->reachable())->toBeFalse();
});

test('getCommonsSpec is null when the plex-commons ConfigMap does not exist yet', function () {
    Process::fake(["kubectl get configmap plex-commons -n larakube-plex -o jsonpath='{.data.commons\\.json}'" => Process::result(output: '', exitCode: 1)]);

    expect(plexProcessHelper()->spec())->toBeNull();
});

test('getCommonsSpec decodes the live spec JSON', function () {
    $spec = ['version' => 1, 'services' => ['postgres' => ['enabled' => true]]];
    Process::fake(["kubectl get configmap plex-commons -n larakube-plex -o jsonpath='{.data.commons\\.json}'" => json_encode($spec)]);

    expect(plexProcessHelper()->spec())->toBe($spec);
});

test('getRegistry is an empty array when the plex-registry ConfigMap is absent', function () {
    Process::fake(["kubectl get configmap plex-registry -n larakube-plex -o jsonpath='{.data.registry\\.json}'" => Process::result(output: '', exitCode: 1)]);

    expect(plexProcessHelper()->registry())->toBe([]);
});

test('readCommonsS3Credentials decodes base64 access/secret keys from the Secret', function () {
    Process::fake([
        "kubectl get secret plex-admin -n larakube-plex -o jsonpath='{.data.S3_ACCESS_KEY}'" => base64_encode('AKIA_TEST'),
        "kubectl get secret plex-admin -n larakube-plex -o jsonpath='{.data.S3_SECRET_KEY}'" => base64_encode('shh'),
    ]);

    expect(plexProcessHelper()->s3Credentials())->toBe(['access' => 'AKIA_TEST', 'secret' => 'shh']);
});

test('readCommonsS3Credentials is null when either key is missing', function () {
    Process::fake([
        "kubectl get secret plex-admin -n larakube-plex -o jsonpath='{.data.S3_ACCESS_KEY}'" => Process::result(output: '', exitCode: 1),
        "kubectl get secret plex-admin -n larakube-plex -o jsonpath='{.data.S3_SECRET_KEY}'" => base64_encode('shh'),
    ]);

    expect(plexProcessHelper()->s3Credentials())->toBeNull();
});
