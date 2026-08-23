<?php

use App\Http\Integrations\OpenBao\Requests\DynamicNoBodyRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('tool:list detects tools live on the cluster even if missing from registry secret and auto-reconciles their host', function (): void {
    Process::fake([
        // Empty registry secret
        '*get secret larakube-tools-registry*' => Process::result(output: ''),
        // Stalwart (Mail) is present on cluster
        '*deployment stalwart -n larakube-shared*' => Process::result(output: 'deployment.apps/stalwart created'),
        // Ingress holds send.luchtech.dev
        '*get ingress -n larakube-shared -o jsonpath*' => Process::result(output: 'send.luchtech.dev'),
        // Catch-all process
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('tool:list local --json');
    $output = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    $mailRow = array_values(array_filter($output, fn ($r) => $r['tool'] === 'mail'))[0] ?? null;

    expect($mailRow)->not->toBeNull()
        ->and($mailRow['installed'])->toBeTrue()
        ->and($mailRow['url'])->toBe('https://send.luchtech.dev');
});

test('tool:list surfaces OpenBao rotation status for an installed DB-backed tool', function (): void {
    // Regression guard: this rotation column replaces the ad-hoc, now-deleted
    // "Cluster Tools using Plex" section that used to live on plex:show —
    // confirmed live 2026-08-02 that section had drifted (still said 'gitea'
    // after the Forgejo rename) and duplicated logic ClusterTool already owns.
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'mail', 'instance' => '', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'send.luchtech.dev'],
            ])),
        ),
        '*deployment stalwart -n larakube-shared*' => Process::result(output: 'deployment.apps/stalwart created'),
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        DynamicNoBodyRequest::class => openBaoFake([
            '*/database/static-roles/stalwart' => ['data' => ['db_name' => 'plex-postgres']],
        ]),
    ]);

    $exit = Artisan::call('tool:list local --json');
    $output = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    $mailRow = array_values(array_filter($output, fn ($r) => $r['tool'] === 'mail'))[0] ?? null;

    expect($mailRow)->not->toBeNull()
        ->and($mailRow['db_role'])->toBe('stalwart')
        ->and($mailRow['rotation'])->toContain('OpenBao');

    // A tool with no Commons database at all (e.g. DNS) never even checks —
    // no per-row port-forward for something that can never have a schedule.
    $dnsRow = array_values(array_filter($output, fn ($r) => $r['tool'] === 'dns'))[0] ?? null;
    expect($dnsRow['db_role'])->toBeNull()
        ->and($dnsRow['rotation'])->toBe('N/A');
});

test('tool:list lists multiple registered instances of a tool as separate rows', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'notes', 'instance' => '', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'notes.luchtech.dev'],
                ['tool' => 'notes', 'instance' => 'docs', 'installedAt' => '2026-08-02T00:00:00+00:00', 'host' => 'wiki.luchtech.dev'],
            ])),
        ),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('tool:list local --json');
    $output = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    $notesRows = array_values(array_filter($output, fn ($r) => $r['tool'] === 'notes'));
    expect($notesRows)->toHaveCount(2)
        ->and($notesRows[0]['instance'])->toBe('')
        ->and($notesRows[0]['brand'])->toBe('Notes')
        ->and($notesRows[1]['instance'])->toBe('docs')
        ->and($notesRows[1]['brand'])->toBe('Notes [docs]');
});

test('tool:list surfaces OpenBao KV secret sync status for wired and unwired tools', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'mail', 'instance' => 'main', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'send.luchtech.dev'],
                ['tool' => 'notes', 'instance' => 'main', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'notes.luchtech.dev'],
            ])),
        ),
        // Stalwart (Mail) has its OpenBao KV sync ExternalSecret on the cluster
        '*get externalsecret stalwart*' => Process::result(output: 'stalwart  1m  True  SecretSynced'),
        // Outline (Notes) never got its KV sync wired
        '*get externalsecret notes-secrets*' => Process::result(output: '', exitCode: 1),
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        DynamicNoBodyRequest::class => openBaoFake([
            '*static-roles/*' => ['data' => ['db_name' => 'plex-postgres']],
        ]),
    ]);

    $exit = Artisan::call('tool:list local --json');
    $output = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    $mailRow = array_values(array_filter($output, fn ($r) => $r['tool'] === 'mail'))[0] ?? null;
    $notesRow = array_values(array_filter($output, fn ($r) => $r['tool'] === 'notes'))[0] ?? null;

    expect($mailRow['sync'])->toBe('synced')
        ->and($notesRow['sync'])->toBe('unsynced');

    // A tool with no OpenBao KV sync surface (e.g. DNS) never even checks.
    $dnsRow = array_values(array_filter($output, fn ($r) => $r['tool'] === 'dns'))[0] ?? null;
    expect($dnsRow['sync'])->toBe('N/A');
});

test('tool:list also treats the dynamic "{secret}-db" ExternalSecret as synced, not just the bare legacy name', function (): void {
    // Regression guard: secrets:init's static KV-mirror sweep deliberately
    // skips creating the bare-named ExternalSecret once a tool's dynamic
    // '{secret}-db' one exists (secrets:wire's own, to avoid racing it) — so
    // a properly secrets:wire'd tool only ever HAS the '-db' name. Checking
    // just the bare name showed "unsynced" forever for every correctly
    // rotated tool, right next to Rotation showing the opposite.
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'design', 'instance' => 'design-luchtech-dev', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'design.luchtech.dev'],
            ])),
        ),
        '*get externalsecret design-secrets-design-luchtech-dev-db*' => Process::result(output: 'design-secrets-design-luchtech-dev-db  1m  True  SecretSynced'),
        '*get externalsecret design-secrets-design-luchtech-dev *' => Process::result(output: '', exitCode: 1),
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        DynamicNoBodyRequest::class => openBaoFake([
            '*static-roles/*' => ['data' => ['db_name' => 'plex-postgres']],
        ]),
    ]);

    $exit = Artisan::call('tool:list local --json');
    $output = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    $designRow = array_values(array_filter($output, fn ($r) => $r['tool'] === 'design'))[0] ?? null;
    expect($designRow['sync'])->toBe('synced');
});

test('tool:list --installed filters out uninstalled tools', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'mail', 'instance' => 'main', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'send.luchtech.dev'],
            ])),
        ),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('tool:list local --installed --json');
    $output = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    $tools = array_column($output, 'tool');
    expect($tools)->toContain('mail')
        ->not->toContain('analytics');
});

test('tool:list never advertises unshipped tools (analytics, uptime)', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('tool:list local --json');
    $output = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    $tools = array_column($output, 'tool');

    expect($tools)->not->toContain('analytics')
        ->not->toContain('uptime');
});

test('tool:list reports SSO as wired for a CLI-OIDC tool once sso:wire records its forgejo-oidc secret', function (): void {
    // Regression guard (live 2026-08-12): Forgejo's OIDC wiring lives in its
    // DB (`forgejo admin auth add-oauth`), so the sso:wire CLI-OIDC path is
    // what writes the `forgejo-oidc` secret — tool:list's probe depends on it.
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'git', 'instance' => 'main', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'git.luchtech.dev'],
            ])),
        ),
        '*get secret forgejo-oidc*' => Process::result(output: 'forgejo-oidc  Opaque  2  1h'),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('tool:list local --json');
    $output = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    $gitRow = array_values(array_filter($output, fn ($r) => $r['tool'] === 'git'))[0] ?? null;

    expect($gitRow)->not->toBeNull()
        ->and($gitRow['sso'])->toBe('wired');
});
