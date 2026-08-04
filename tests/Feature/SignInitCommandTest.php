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

function fakeSignInitProcess(?string $s3Host, ?string &$appliedManifest, int $applyExitCode = 0): void
{
    $spec = signCommonsSpec($s3Host);

    Process::fake(function ($process) use ($spec, &$appliedManifest, $applyExitCode) {
        $cmd = $process->command;

        if (str_contains($cmd, 'apply -f')) {
            preg_match('/apply -f (\'[^\']*\'|"[^"]*"|\S+)/', $cmd, $m);
            $path = trim($m[1] ?? '', '\'"');
            if ($path !== '' && file_exists($path) && str_contains($path, 'larakube-sign-documenso')) {
                $appliedManifest = file_get_contents($path);
            }

            return Process::result(output: 'applied', exitCode: $applyExitCode);
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

test('sign:init declares the mail:wire/sso:wire static keys as literal values, not valueFrom', function () {
    // Regression guard for a real incident (2026-08-05): mail:wire/sso:wire
    // set these 4 names via `kubectl set env NAME=value` (a plain literal),
    // never through the sign-documenso-smtp/-oidc Secrets. Declaring them
    // here as valueFrom made every re-run of sign:init fail — kubectl
    // apply's merge re-added valueFrom on top of the live literal value,
    // and the two are mutually exclusive ("valueFrom: Invalid value: '':
    // may not be specified when `value` is not empty").
    $appliedManifest = null;
    fakeSignInitProcess('files.example.com', $appliedManifest);

    $this->artisan(SignInitCommand::class, [
        'environment' => 'local',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    foreach ([
        'NEXT_PRIVATE_SMTP_TRANSPORT' => 'smtp-auth',
        'NEXT_PRIVATE_SMTP_SECURE' => 'true',
        'NEXT_PUBLIC_DISABLE_OIDC_SIGNIN' => 'false',
        'NEXT_PUBLIC_DISABLE_OIDC_SIGNUP' => 'false',
    ] as $name => $value) {
        preg_match('/- name: '.$name.'\s*\n\s*(value|valueFrom):\s*"?([^"\n]*)"?/', $appliedManifest, $m);
        expect($m[1] ?? null)->toBe('value')
            ->and(trim($m[2] ?? '', '"'))->toBe($value);
    }
});

test('sign:init returns a failing exit code and does not claim success when kubectl apply is rejected', function () {
    // Regression guard: withSpin()'s success check is `!== false`, and the
    // old runStreaming() call returned an int exit code — never `=== false`
    // — so a rejected kubectl apply (like the valueFrom conflict above)
    // still printed a green check and "Documenso signature stack is live."
    // applyAndVerifyRollout() returns a real bool, which withSpin can act on.
    $appliedManifest = null;
    fakeSignInitProcess('files.example.com', $appliedManifest, applyExitCode: 1);

    $this->artisan(SignInitCommand::class, [
        'environment' => 'local',
        '--no-interaction' => true,
    ])
        ->assertExitCode(1)
        ->doesntExpectOutputToContain('Documenso signature stack is live');
});
