<?php

/**
 * Regression coverage for the browser-facing S3 endpoint bug. Unlike Outline/
 * Documenso, Sendrec supports a genuine internal/public split (S3_ENDPOINT vs
 * S3_PUBLIC_ENDPOINT — confirmed against the binary's own recognised env var
 * names), so record:init must keep S3_ENDPOINT on the fast internal path AND
 * set S3_PUBLIC_ENDPOINT for the presigned URLs it hands to the browser.
 * See resolveCommonsS3Endpoints() on InteractsWithPlex.
 */

use App\Commands\Record\RecordInitCommand;
use Illuminate\Support\Facades\Process;

function recordCommonsSpec(?string $s3Host): array
{
    $seaweedfs = ['enabled' => true, 'port' => 8333];
    if ($s3Host !== null) {
        $seaweedfs['host'] = $s3Host;
    }

    return [
        'services' => [
            'postgres' => ['enabled' => true],
            'seaweedfs' => $seaweedfs,
        ],
    ];
}

function fakeRecordInitProcess(?string $s3Host, ?string &$appliedManifest, int $applyExitCode = 0): void
{
    $spec = recordCommonsSpec($s3Host);

    Process::fake(function ($process) use ($spec, &$appliedManifest, $applyExitCode) {
        $cmd = $process->command;

        if (str_contains($cmd, 'apply -f')) {
            preg_match('/apply -f (\'[^\']*\'|"[^"]*"|\S+)/', $cmd, $m);
            $path = trim($m[1] ?? '', '\'"');
            if ($path !== '' && file_exists($path) && str_contains($path, 'larakube-record-sendrec')) {
                $appliedManifest = file_get_contents($path);
            }

            return Process::result(output: 'applied', exitCode: $applyExitCode);
        }

        return match (true) {
            str_contains($cmd, 'get configmap plex-commons') => Process::result(output: json_encode($spec)),
            str_contains($cmd, 'get configmap plex-registry') => Process::result(output: '', exitCode: 1),
            str_contains($cmd, 'S3_ACCESS_KEY') => Process::result(output: base64_encode('larakube')),
            str_contains($cmd, 'S3_SECRET_KEY') => Process::result(output: base64_encode('s3-secret')),
            str_contains($cmd, 'rollout status') => Process::result(output: 'deployment "record-sendrec" successfully rolled out'),
            default => Process::result(output: ''),
        };
    });
}

test('record:init keeps S3_ENDPOINT internal but signs S3_PUBLIC_ENDPOINT against the Commons public host', function () {
    $appliedManifest = null;
    fakeRecordInitProcess('files.example.com', $appliedManifest);

    $this->artisan(RecordInitCommand::class, [
        'environment' => 'local',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    expect($appliedManifest)->not->toBeNull();

    preg_match('/name: S3_ENDPOINT\s*\n\s*value: "([^"]*)"/', $appliedManifest, $internal);
    preg_match('/name: S3_PUBLIC_ENDPOINT\s*\n\s*value: "([^"]*)"/', $appliedManifest, $public);

    expect($internal[1] ?? null)->toBe('http://seaweedfs.larakube-plex.svc.cluster.local:8333')
        ->and($public[1] ?? null)->toBe('https://files.example.com');
});

test('record:init falls back S3_PUBLIC_ENDPOINT to the internal endpoint when the Commons has no public host', function () {
    $appliedManifest = null;
    fakeRecordInitProcess(null, $appliedManifest);

    $this->artisan(RecordInitCommand::class, [
        'environment' => 'local',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    expect($appliedManifest)->not->toBeNull();

    preg_match('/name: S3_ENDPOINT\s*\n\s*value: "([^"]*)"/', $appliedManifest, $internal);
    preg_match('/name: S3_PUBLIC_ENDPOINT\s*\n\s*value: "([^"]*)"/', $appliedManifest, $public);

    expect($internal[1] ?? null)->toBe('http://seaweedfs.larakube-plex.svc.cluster.local:8333')
        ->and($public[1] ?? null)->toBe('http://seaweedfs.larakube-plex.svc.cluster.local:8333');
});

test('record:init sets SMTP_TLS to "tls", not the stale "implicit" value that deadlocks SendRec against Stalwart', function () {
    $appliedManifest = null;
    fakeRecordInitProcess('files.example.com', $appliedManifest);

    $this->artisan(RecordInitCommand::class, [
        'environment' => 'local',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    preg_match('/name: SMTP_TLS\s*\n\s*value: "([^"]*)"/', $appliedManifest, $m);

    expect($m[1] ?? null)->toBe('tls');
});

test('record:init returns a failing exit code and does not claim success when kubectl apply is rejected', function () {
    $appliedManifest = null;
    fakeRecordInitProcess('files.example.com', $appliedManifest, applyExitCode: 1);

    $this->artisan(RecordInitCommand::class, [
        'environment' => 'local',
        '--no-interaction' => true,
    ])
        ->assertExitCode(1)
        ->doesntExpectOutputToContain('Sendrec async video platform stack is live');
});
