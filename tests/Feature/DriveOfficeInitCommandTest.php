<?php

use App\Commands\Drive\Office\OfficeInitCommand;
use Illuminate\Support\Facades\Process;

/** @return array<string, mixed> */
function driveOfficeFakes(string $driveDeployment = 'deployment.apps/drive-ocis'): array
{
    return [
        '*get deployment drive-ocis*' => Process::result(output: $driveDeployment),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*create secret*' => Process::result(output: 'secret/drive-office-secrets created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout status*' => Process::result(output: 'rolled out'),
        '*' => Process::result(output: ''),
    ];
}

/** @return array<string, mixed> */
function driveOfficeViewData(bool $office): array
{
    return [
        'host' => 'drive.test',
        'office' => $office,
        'officeHost' => 'office.drive.test',
        's3Creds' => null,
        'plexNamespace' => 'larakube-plex',
        'noPlex' => true,
        'vpnOnly' => false,
        'isLocal' => true,
        'proxied' => false,
        'extensions' => [],
    ];
}

test('drive:office:init refuses when Drive itself is not installed', function (): void {
    Process::fake(driveOfficeFakes(driveDeployment: ''));

    $this->artisan('drive:office:init', ['environment' => 'local', '--force' => true])
        ->assertExitCode(1);

    Process::assertNotRan(fn ($process): bool => str_contains($process->command, 'apply -f'));
});

test('the oCIS manifest is unchanged when the office layer is absent', function (): void {
    $rendered = view('k8s.drive.ocis', driveOfficeViewData(office: false))->render();

    // The whole point of the @if gates: a plain drive:init must not gain a
    // sidecar, a second Service, or any Collabora wiring.
    expect($rendered)
        ->not->toContain('collaboration')
        ->not->toContain('drive-office-secrets')
        ->not->toContain('COLLABORATION_WOPI_SECRET');
});

test('the office layer adds the WOPI bridge as a sidecar sharing the oCIS pod', function (): void {
    $rendered = view('k8s.drive.ocis', driveOfficeViewData(office: true))->render();

    expect($rendered)
        // Loopback, not a Service DNS name: every oCIS service binds 127.0.0.1,
        // so the bridge only works inside this pod's network namespace.
        ->toContain('MICRO_REGISTRY_ADDRESS')
        ->toContain('127.0.0.1:9233')
        ->toContain('name: collaboration')
        ->toContain('args: ["collaboration", "server"]')
        // Collabora never sends WopiProof headers — with verification on, every
        // CheckFileInfo failed with "Invalid timestamp" and the editor stayed blank.
        ->toContain('COLLABORATION_APP_PROOF_DISABLE')
        ->toContain('name: drive-collaboration');
});

test('the bridge signs WOPI tokens with its own secret, never oCIS internal JWT', function (): void {
    $rendered = view('k8s.drive.ocis', driveOfficeViewData(office: true))->render();

    // drive-secrets' jwt-secret is oCIS's service-to-service JWT — a different
    // trust domain despite the similar name.
    expect($rendered)->toContain('key: wopi-secret')
        ->and($rendered)->toContain('name: drive-office-secrets');
});

test('the CODE manifest pins an exact image and terminates TLS at Traefik', function (): void {
    $rendered = view('k8s.drive.code', [
        'host' => 'drive.test',
        'officeHost' => 'office.drive.test',
        'codeImage' => OfficeInitCommand::CODE_IMAGE,
        'codeAdminPassword' => 'secret',
        'isLocal' => true,
        'vpnOnly' => false,
        'proxied' => false,
    ])->render();

    expect($rendered)
        ->toContain('image: collabora/code:')
        ->not->toContain('collabora/code:latest')
        // Without ssl.termination CODE builds http:// URLs the browser blocks
        // as mixed content; frame_ancestors lets oCIS iframe the editor.
        ->toContain('--o:ssl.termination=true')
        ->toContain('--o:net.frame_ancestors=drive.test')
        ->toContain('targetPort: 9980');
});

test('the CSP lets the parent page frame the editor only when office is enabled', function (): void {
    $with = view('k8s.drive.ocis', driveOfficeViewData(office: true))->render();
    $without = view('k8s.drive.ocis', driveOfficeViewData(office: false))->render();

    // oCIS's default frame-src is 'self' blob: embed.diagrams.net — the editor
    // host missing from it renders "This content is blocked" in the document
    // area, no matter what CODE's own frame-ancestors allows.
    expect($with)->toContain("'https://office.drive.test/'")
        ->and($without)->not->toContain('office.drive.test');
});

test('a CSP change rolls the pod instead of applying silently', function (): void {
    $with = view('k8s.drive.ocis', driveOfficeViewData(office: true))->render();
    $without = view('k8s.drive.ocis', driveOfficeViewData(office: false))->render();

    // csp.yaml is a subPath mount: it never picks up a ConfigMap update, and the
    // running pod keeps serving the old policy until something changes the pod
    // template. The checksum annotation is that something.
    preg_match('/csp-checksum: "([a-f0-9]+)"/', $with, $a);
    preg_match('/csp-checksum: "([a-f0-9]+)"/', $without, $b);

    expect($a[1] ?? null)->not->toBeNull()
        ->and($b[1] ?? null)->not->toBeNull()
        ->and($a[1])->not->toBe($b[1]);
});
