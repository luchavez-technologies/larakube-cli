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
            'headless-shell' => ['enabled' => true],
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
            str_contains($cmd, 'get service headless-shell') => Process::result(output: '10.43.0.99'),
            str_contains($cmd, 'S3_ACCESS_KEY') => Process::result(output: base64_encode('larakube')),
            str_contains($cmd, 'S3_SECRET_KEY') => Process::result(output: base64_encode('s3-secret')),
            str_contains($cmd, 'rollout status') => Process::result(output: 'deployment "sign-documenso" successfully rolled out'),
            default => Process::result(output: ''),
        };
    });
}

test('sign:init signs Documenso\'s S3 endpoint against the Commons public host, not cluster-internal DNS', function (): void {
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

test('sign:init falls back to the internal S3 endpoint when the Commons has no public host', function (): void {
    $appliedManifest = null;
    fakeSignInitProcess(null, $appliedManifest);

    $this->artisan(SignInitCommand::class, [
        'environment' => 'local',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    expect($appliedManifest)->not->toBeNull()
        ->and($appliedManifest)->toContain('http://seaweedfs.larakube-plex.svc.cluster.local:8333');
});

test('sign:init declares the mail:wire/sso:wire static keys as literal values, not valueFrom', function (): void {
    // Regression guard for a real incident (2026-08-05): mail:wire/sso:wire
    // set these 4 names via `kubectl set env NAME=value` (a plain literal),
    // never through the sign-smtp/-oidc Secrets. Declaring them
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

test('sign:init returns a failing exit code and does not claim success when kubectl apply is rejected', function (): void {
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

test('sign:init --vpn-only names the Traefik Middleware for its instance, never the main sentinel', function (): void {
    // Regression guard (2026-08-15): ensureVpnMiddleware()'s $instance
    // default used to be the literal string 'main', which SignTool's
    // vpnMiddlewareTarget() recognized as "no instance" and correctly
    // produced 'sign-vpn-only'. When SignTool (and ~9 other Vendors) were
    // simplified to only recognize null/'' as "no instance"
    // (matching CRM's pure host-derived convention), ensureVpnMiddleware()'s
    // own default was left unchanged at 'main' — so this exact call, with no
    // explicit --domain/instance, would have silently applied a SECOND
    // Middleware named 'sign-vpn-only-main' instead of updating the real
    // 'sign-vpn-only' one, leaving --vpn-only's access restriction pointing
    // at whichever Middleware the Ingress annotation actually references —
    // a live access-control gap, not a cosmetic naming one.
    $appliedVpnMiddlewareManifest = null;
    Process::fake(function ($process) use (&$appliedVpnMiddlewareManifest) {
        $cmd = $process->command;

        if (str_contains($cmd, 'apply -f')) {
            // The Middleware arrives on stdin, not as a file.
            if (str_contains((string) $process->input, 'kind: Middleware')) {
                $appliedVpnMiddlewareManifest = ['content' => (string) $process->input];
            }

            return Process::result(output: 'applied');
        }

        return match (true) {
            str_contains($cmd, 'get configmap plex-commons') => Process::result(output: json_encode(signCommonsSpec('files.example.com'))),
            str_contains($cmd, 'get configmap plex-registry') => Process::result(output: '', exitCode: 1),
            str_contains($cmd, 'get service headless-shell') => Process::result(output: '10.43.0.99'),
            str_contains($cmd, 'S3_ACCESS_KEY') => Process::result(output: base64_encode('larakube')),
            str_contains($cmd, 'S3_SECRET_KEY') => Process::result(output: base64_encode('s3-secret')),
            str_contains($cmd, 'rollout status') => Process::result(output: 'deployment "sign-documenso" successfully rolled out'),
            default => Process::result(output: ''),
        };
    });

    $this->artisan(SignInitCommand::class, [
        'environment' => 'local',
        '--vpn-only' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);

    // Named for this instance (ADR 0021), never the removed 'main' sentinel,
    // and the same name the Ingress references.
    $name = App\Data\ToolInstance::forHost(App\Enums\ClusterTool::SIGN, 'sign.kube')->vpnMiddleware()->name;

    expect($name)->toBe('documenso-vpn-only-sign-kube')
        ->and($appliedVpnMiddlewareManifest)->not->toBeNull()
        ->and($appliedVpnMiddlewareManifest['content'])->toContain("name: {$name}")
        ->and($appliedVpnMiddlewareManifest['content'])->not->toContain('-main');
});

test('sign:init wires Documenso to the Commons headless Chrome by ClusterIP and mounts its signing certificate', function (): void {
    $appliedManifest = null;
    fakeSignInitProcess('files.example.com', $appliedManifest);

    $this->artisan(SignInitCommand::class, ['environment' => 'local', '--no-interaction' => true])->assertExitCode(0);

    $names = App\Data\ToolInstance::forHost(App\Enums\ClusterTool::SIGN, 'sign.kube');

    // Without a browser every document stays pending; by IP because Chrome's
    // DevTools server rejects any other Host header.
    expect($appliedManifest)
        ->toContain('value: "http://10.43.0.99:9222"')
        ->toContain('NEXT_PUBLIC_USE_INTERNAL_URL_BROWSERLESS')
        ->toContain("value: \"http://{$names->deployment()}.larakube-shared.svc.cluster.local\"")
        ->toContain('secretName: '.$names->name('signing-cert'))
        ->toContain('value: "/app/certs/cert.p12"')
        ->toContain('larakube-tool: sign');

    // S3 keys come from the credentials Secret, never literal env values.
    expect($appliedManifest)->not->toContain('s3-secret"')
        ->and($appliedManifest)->toContain('key: s3-secret-key');

    // The certificate and passphrase travel in files, never in argv.
    Process::assertRan(fn ($process) => str_contains($process->command, 'create secret generic '.$names->name('signing-cert'))
        && str_contains($process->command, '--from-file=passphrase='));
});

test('sign:init keeps an existing signing certificate, so signed documents keep verifying', function (): void {
    $appliedManifest = null;
    $names = App\Data\ToolInstance::forHost(App\Enums\ClusterTool::SIGN, 'sign.kube');

    Process::fake(function ($process) use ($names, &$appliedManifest) {
        $cmd = $process->command;

        return match (true) {
            str_contains($cmd, 'get secret '.$names->name('signing-cert')) => Process::result(output: base64_encode('existing-passphrase')),
            str_contains($cmd, 'get configmap plex-commons') => Process::result(output: json_encode(signCommonsSpec('files.example.com'))),
            str_contains($cmd, 'get configmap plex-registry') => Process::result(output: '', exitCode: 1),
            str_contains($cmd, 'get service headless-shell') => Process::result(output: '10.43.0.99'),
            str_contains($cmd, 'S3_ACCESS_KEY') => Process::result(output: base64_encode('larakube')),
            str_contains($cmd, 'S3_SECRET_KEY') => Process::result(output: base64_encode('s3-secret')),
            default => Process::result(output: ''),
        };
    });

    $this->artisan(SignInitCommand::class, ['environment' => 'local', '--no-interaction' => true])->assertExitCode(0);

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'openssl'));
});

test('sign:init stops when the Commons has no headless Chrome to point Documenso at', function (): void {
    Process::fake(fn ($process) => match (true) {
        str_contains($process->command, 'get configmap plex-commons') => Process::result(output: json_encode(signCommonsSpec('files.example.com'))),
        str_contains($process->command, 'get service headless-shell') => Process::result(output: ''),
        default => Process::result(output: ''),
    });

    $this->artisan(SignInitCommand::class, ['environment' => 'local', '--no-interaction' => true])
        ->expectsOutputToContain('Could not find the Commons headless Chrome service')
        ->assertExitCode(1);

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'apply -f') && str_contains($process->command, 'larakube-sign-documenso'));
});
