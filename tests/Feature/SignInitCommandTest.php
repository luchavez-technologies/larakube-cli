<?php

/**
 * Regression coverage for the browser-facing S3 endpoint bug: Documenso has
 * no internal/public split (unlike Teable/Sendrec) — NEXT_PRIVATE_UPLOAD_ENDPOINT
 * is its ONE S3 endpoint, and NEXT_PUBLIC_UPLOAD_TRANSPORT=s3 ships it into
 * the browser bundle, which signs presigned upload/download URLs against it.
 * Cluster-internal DNS there makes every document upload/view unresolvable
 * from the browser. See resolveCommonsS3Endpoints() on InteractsWithPlex.
 */

use App\Commands\Sign\SignInitCommand;
use Illuminate\Support\Facades\Process;

function signCommonsSpec(?string $s3Host): array
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

function fakeSignInitProcess(?string $s3Host, ?string &$appliedManifest): void
{
    $spec = signCommonsSpec($s3Host);

    Process::fake(function ($process) use ($spec, &$appliedManifest) {
        $cmd = $process->command;

        if (str_contains($cmd, 'apply -f')) {
            preg_match('/apply -f (\'[^\']*\'|"[^"]*"|\S+)/', $cmd, $m);
            $path = trim($m[1] ?? '', '\'"');
            if ($path !== '' && file_exists($path) && str_contains($path, 'larakube-sign-documenso')) {
                $appliedManifest = file_get_contents($path);
            }

            return Process::result(output: 'applied');
        }

        return match (true) {
            str_contains($cmd, 'get configmap plex-commons') => Process::result(output: json_encode($spec)),
            str_contains($cmd, 'get configmap plex-registry') => Process::result(output: '', exitCode: 1),
            str_contains($cmd, 'S3_ACCESS_KEY') => Process::result(output: base64_encode('larakube')),
            str_contains($cmd, 'S3_SECRET_KEY') => Process::result(output: base64_encode('s3-secret')),
            str_contains($cmd, 'rollout status') => Process::result(output: 'deployment "sign-documenso" successfully rolled out'),
            default => Process::result(output: ''),
        };
    });
}

test('sign:init signs Documenso\'s S3 endpoint against the Commons public host, not cluster-internal DNS', function () {
    $appliedManifest = null;
    fakeSignInitProcess('files.example.com', $appliedManifest);

    $this->artisan(SignInitCommand::class, [
        'environment' => 'local',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    expect($appliedManifest)->not->toBeNull()
        ->and($appliedManifest)->toContain('https://files.example.com')
        ->and($appliedManifest)->not->toContain('seaweedfs.larakube-plex.svc.cluster.local');
});

test('sign:init falls back to the internal S3 endpoint when the Commons has no public host', function () {
    $appliedManifest = null;
    fakeSignInitProcess(null, $appliedManifest);

    $this->artisan(SignInitCommand::class, [
        'environment' => 'local',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    expect($appliedManifest)->not->toBeNull()
        ->and($appliedManifest)->toContain('http://seaweedfs.larakube-plex.svc.cluster.local:8333');
});
