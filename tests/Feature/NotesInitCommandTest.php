<?php

/**
 * Regression coverage for the browser-facing S3 endpoint bug: Outline hands
 * presigned upload/download URLs straight to the browser, signed against
 * whatever AWS_S3_UPLOAD_BUCKET_URL is set to. Signing against the
 * cluster-internal SeaweedFS DNS name (what notes:init used to do
 * unconditionally) makes every attachment upload fail — the browser can
 * never resolve it. See resolveCommonsS3Endpoints() on InteractsWithPlex.
 */

use App\Commands\Notes\NotesInitCommand;
use Illuminate\Support\Facades\Process;

function notesCommonsSpec(?string $s3Host): array
{
    $seaweedfs = ['enabled' => true, 'port' => 8333];
    if ($s3Host !== null) {
        $seaweedfs['host'] = $s3Host;
    }

    return [
        'services' => [
            'postgres' => ['enabled' => true],
            'redis' => ['enabled' => true],
            'seaweedfs' => $seaweedfs,
        ],
    ];
}

function fakeNotesInitProcess(?string $s3Host, ?string &$appliedManifest, int $applyExitCode = 0): void
{
    $spec = notesCommonsSpec($s3Host);

    Process::fake(function ($process) use ($spec, &$appliedManifest, $applyExitCode) {
        $cmd = $process->command;

        if (str_contains($cmd, 'apply -f')) {
            preg_match('/apply -f (\'[^\']*\'|"[^"]*"|\S+)/', $cmd, $m);
            $path = trim($m[1] ?? '', '\'"');
            if ($path !== '' && file_exists($path) && str_contains($path, 'larakube-notes')) {
                $appliedManifest = file_get_contents($path);
            }

            return Process::result(output: 'applied', exitCode: $applyExitCode);
        }

        return match (true) {
            str_contains($cmd, 'get configmap plex-commons') => Process::result(output: json_encode($spec)),
            str_contains($cmd, 'get configmap plex-registry') => Process::result(output: '', exitCode: 1),
            str_contains($cmd, 'S3_ACCESS_KEY') => Process::result(output: base64_encode('larakube')),
            str_contains($cmd, 'S3_SECRET_KEY') => Process::result(output: base64_encode('s3-secret')),
            // Existing, non-pending OIDC creds — takes ensureOidcSecret()'s
            // fast path so the test doesn't have to model Zitadel at all.
            str_contains($cmd, 'notes-outline-oidc') => Process::result(output: base64_encode('existing-client-id')),
            str_contains($cmd, 'rollout status') => Process::result(output: 'deployment "notes-outline" successfully rolled out'),
            default => Process::result(output: ''),
        };
    });
}

test('notes:init signs Outline\'s S3 endpoint against the Commons public host, not cluster-internal DNS', function () {
    $appliedManifest = null;
    fakeNotesInitProcess('files.example.com', $appliedManifest);

    $this->artisan(NotesInitCommand::class, [
        'environment' => 'local',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    expect($appliedManifest)->not->toBeNull()
        ->and($appliedManifest)->toContain('https://files.example.com')
        ->and($appliedManifest)->not->toContain('seaweedfs.larakube-plex.svc.cluster.local');
});

test('notes:init wires REDIS_COLLABORATION_URL to the same Commons Redis as REDIS_URL, and pins WEB_CONCURRENCY so it doesn\'t OOM the pod', function () {
    // Regression guard for a real incident (2026-08-05): Outline only forces
    // 1 worker process (throng) when REDIS_COLLABORATION_URL is ABSENT.
    // Setting it without also pinning WEB_CONCURRENCY=1 let throng fork one
    // Node process per CPU core on the host, which OOMKilled this 512Mi pod
    // on the very next real notes:init run.
    $appliedManifest = null;
    fakeNotesInitProcess('files.example.com', $appliedManifest);

    $this->artisan(NotesInitCommand::class, [
        'environment' => 'local',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    preg_match('/name: REDIS_URL\s*\n\s*value: "([^"]*)"/', $appliedManifest, $redisUrl);
    preg_match('/name: REDIS_COLLABORATION_URL\s*\n\s*value: "([^"]*)"/', $appliedManifest, $collabUrl);
    preg_match('/name: WEB_CONCURRENCY\s*\n\s*value: "([^"]*)"/', $appliedManifest, $concurrency);

    expect($redisUrl[1] ?? null)->not->toBeNull()
        ->and($collabUrl[1] ?? null)->toBe($redisUrl[1] ?? null)
        ->and($concurrency[1] ?? null)->toBe('1');
});

test('notes:init falls back to the internal S3 endpoint when the Commons has no public host', function () {
    $appliedManifest = null;
    fakeNotesInitProcess(null, $appliedManifest);

    $this->artisan(NotesInitCommand::class, [
        'environment' => 'local',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    expect($appliedManifest)->not->toBeNull()
        ->and($appliedManifest)->toContain('http://seaweedfs.larakube-plex.svc.cluster.local:8333');
});

test('notes:init returns a failing exit code and does not claim success when kubectl apply is rejected', function () {
    // Regression guard: withSpin()'s success check is `!== false`, and the
    // old runStreaming() call returned an int exit code — never `=== false`
    // — so a rejected kubectl apply still printed a green check and "Outline
    // wiki stack is live." applyAndVerifyRollout() returns a real bool.
    $appliedManifest = null;
    fakeNotesInitProcess('files.example.com', $appliedManifest, applyExitCode: 1);

    $this->artisan(NotesInitCommand::class, [
        'environment' => 'local',
        '--no-interaction' => true,
    ])
        ->assertExitCode(1)
        ->doesntExpectOutputToContain('Outline wiki stack is live');
});
