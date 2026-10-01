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
        '*deployment -l larakube.io/tool=mail -n larakube-shared*' => Process::result(output: 'deployment.apps/stalwart created'),
        // Ingress holds send.luchtech.dev
        '*get ingress -n larakube-shared -o jsonpath*' => Process::result(output: 'send.luchtech.dev'),
        // Catch-all process
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('tool:list local --json');
    $output = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    $mailRow = array_values(array_filter($output, fn ($r) => $r['tool'] === 'stalwart'))[0] ?? null;

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
                openBaoRegistryRow(),
                ['tool' => 'stalwart', 'instance' => '', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'send.luchtech.dev'],
            ])),
        ),
        '*deployment stalwart -n larakube-shared*' => Process::result(output: 'deployment.apps/stalwart created'),
        '*get secret openbao-secrets-secrets-example-com*' => base64_encode('s.test-token'),
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
    $mailRow = array_values(array_filter($output, fn ($r) => $r['tool'] === 'stalwart'))[0] ?? null;

    expect($mailRow)->not->toBeNull()
        ->and($mailRow['db_role'])->toBe('stalwart')
        ->and($mailRow['rotation'])->toContain('OpenBao');

    // A tool with no Commons database at all (e.g. DNS) never even checks —
    // no per-row port-forward for something that can never have a schedule.
    $dnsRow = array_values(array_filter($output, fn ($r) => $r['tool'] === 'external-dns'))[0] ?? null;
    expect($dnsRow['db_role'])->toBeNull()
        ->and($dnsRow['rotation'])->toBe('N/A');
});

test('tool:list lists multiple registered instances of a tool as separate rows', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'outline', 'instance' => '', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'notes.luchtech.dev'],
                ['tool' => 'outline', 'instance' => 'docs', 'installedAt' => '2026-08-02T00:00:00+00:00', 'host' => 'wiki.luchtech.dev'],
            ])),
        ),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('tool:list local --json');
    $output = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    $notesRows = array_values(array_filter($output, fn ($r) => $r['tool'] === 'outline'));
    expect($notesRows)->toHaveCount(2)
        ->and($notesRows[0]['instance'])->toBe('')
        ->and($notesRows[0]['brand'])->toBe('Outline')
        ->and($notesRows[1]['instance'])->toBe('docs')
        ->and($notesRows[1]['brand'])->toBe('Outline [docs]');
});

test('tool:list surfaces OpenBao KV secret sync status for wired and unwired tools', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                openBaoRegistryRow(),
                ['tool' => 'stalwart', 'instance' => 'main', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'send.luchtech.dev'],
                ['tool' => 'outline', 'instance' => 'main', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'notes.luchtech.dev'],
            ])),
        ),
        // Stalwart (Mail) has its OpenBao KV sync ExternalSecret on the cluster
        '*get externalsecret stalwart*' => Process::result(output: 'stalwart  1m  True  SecretSynced'),
        // Outline (Notes) never got its KV sync wired
        '*get externalsecret outline-secrets*' => Process::result(output: '', exitCode: 1),
        '*get secret openbao-secrets-secrets-example-com*' => base64_encode('s.test-token'),
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
    $mailRow = array_values(array_filter($output, fn ($r) => $r['tool'] === 'stalwart'))[0] ?? null;
    $notesRow = array_values(array_filter($output, fn ($r) => $r['tool'] === 'outline'))[0] ?? null;

    expect($mailRow['sync'])->toBe('synced')
        ->and($notesRow['sync'])->toBe('unsynced');

    // A tool with no OpenBao KV sync surface (e.g. DNS) never even checks.
    $dnsRow = array_values(array_filter($output, fn ($r) => $r['tool'] === 'external-dns'))[0] ?? null;
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
                openBaoRegistryRow(),
                ['tool' => 'penpot', 'instance' => 'design-luchtech-dev', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'design.luchtech.dev'],
            ])),
        ),
        '*get externalsecret design-secrets-design-luchtech-dev-db*' => Process::result(output: 'design-secrets-design-luchtech-dev-db  1m  True  SecretSynced'),
        '*get externalsecret design-secrets-design-luchtech-dev *' => Process::result(output: '', exitCode: 1),
        '*get secret openbao-secrets-secrets-example-com*' => base64_encode('s.test-token'),
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
    $designRow = array_values(array_filter($output, fn ($r) => $r['tool'] === 'penpot'))[0] ?? null;
    expect($designRow['sync'])->toBe('synced');
});

test('tool:list --registry-only answers from the registry alone, with no live probes, and marks rows unverified', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode((string) json_encode([
            ['tool' => 'pocketbase', 'instance' => '', 'installedAt' => '2026-09-01T00:00:00+00:00', 'host' => 'pocket.example.com'],
        ]))),
        // Stalwart is live on the cluster but unregistered: only the full check may find it.
        '*deployment stalwart -n larakube-shared*' => Process::result(output: 'deployment.apps/stalwart'),
        '*' => Process::result(output: ''),
    ]);

    expect(Artisan::call('tool:list local --registry-only --json'))->toBe(0);
    $rows = collect(json_decode(Artisan::output(), true))->keyBy('tool');

    expect($rows['pocketbase']['installed'])->toBeTrue()
        ->and($rows['pocketbase']['host'])->toBe('pocket.example.com')
        ->and($rows['pocketbase']['verified'])->toBeFalse()
        ->and($rows['stalwart']['installed'])->toBeFalse()
        ->and($rows['stalwart']['verified'])->toBeFalse()
        ->and($rows['stalwart']['sso'])->toBe('unverified');

    Process::assertNotRan(fn ($process) => str_contains((string) $process->command, 'deployment stalwart'));
    Process::assertNotRan(fn ($process) => str_contains((string) $process->command, 'get ingress'));
});

test('a full tool:list marks its rows verified', function (): void {
    Process::fake(['*' => Process::result(output: '')]);

    Artisan::call('tool:list local --json');

    expect(collect(json_decode(Artisan::output(), true))->pluck('verified')->unique()->all())->toBe([true]);
});

test('tool:list --installed filters out uninstalled tools', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'stalwart', 'instance' => 'main', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'send.luchtech.dev'],
            ])),
        ),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('tool:list local --installed --json');
    $output = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    $tools = array_column($output, 'tool');
    expect($tools)->toContain('stalwart')
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
                ['tool' => 'forgejo', 'instance' => 'main', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'git.luchtech.dev'],
            ])),
        ),
        '*get secret forgejo-oidc*' => Process::result(output: 'forgejo-oidc  Opaque  2  1h'),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('tool:list local --json');
    $output = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    $gitRow = array_values(array_filter($output, fn ($r) => $r['tool'] === 'forgejo'))[0] ?? null;

    expect($gitRow)->not->toBeNull()
        ->and($gitRow['sso'])->toBe('wired');
});

/**
 * Deployments as they actually appear on a real cluster: a mix of
 * convention-following names, deliberately unsuffixed components, a headless
 * controller, and unrelated infrastructure.
 */
function toolListRefreshFakes(string $registryJson = ''): void
{
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: $registryJson),
        '*get deployment *larakube-shared*' => Process::result(output: implode("\n", [
            'outline-notes-luchtech-dev',   // conforms
            'loki-monitor-luchtech-dev',  // conforms (was an enum gap)
            'external-dns-luchtech-dev',          // conforms, but DNS is headless
            'drive-ocis',                         // no suffix -> skipped
            'kube-state-metrics',                 // not a tool at all
        ])),
        '*get deployment -n*' => Process::result(output: ''),
        "*get ingress -n 'larakube-shared'*" => Process::result(
            output: "notes.luchtech.dev\nmonitor.luchtech.dev",
        ),
        '*get ingress -n*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);
}

/**
 * Run the refresh and return BOTH streams: Artisan's own output (tables,
 * $this->line) and Termwind's (laraKubeInfo/laraKubeWarn), which TestCase
 * otherwise points at a NullOutput so it never reaches Artisan::output().
 *
 * @return array{0: string, 1: string}
 */
function toolListRefreshRun(): array
{
    $termwind = new Symfony\Component\Console\Output\BufferedOutput;
    Termwind\renderUsing($termwind);

    try {
        Artisan::call('tool:list local --refresh --dry-run --no-interaction');
    } finally {
        Termwind\renderUsing(new Symfony\Component\Console\Output\NullOutput);
    }

    return [Artisan::output(), $termwind->fetch()];
}

test('a full tool:list adopts live, unregistered convention tools into the registry', function (): void {
    $registry = Tests\Support\FakeToolRegistry::install();
    toolListRefreshFakes();

    expect(Artisan::call('tool:list local --json --no-interaction'))->toBe(0);

    $stored = collect($registry->stored)->keyBy(fn (array $row): string => $row['tool'].'|'.$row['instance']);
    $notes = collect(json_decode(Artisan::output(), true))->firstWhere('tool', 'outline');

    // Adopted with the identity --refresh derives, so the registry-only view sees it next time.
    expect($stored['outline|notes-luchtech-dev']['host'] ?? null)->toBe('notes.luchtech.dev')
        ->and($notes['installed'])->toBeTrue()
        ->and($notes['instance'])->toBe('notes-luchtech-dev')
        // An unsuffixed Deployment is never registered under a guessed instance.
        ->and($stored->keys()->filter(fn (string $key): bool => str_starts_with($key, 'ocis|'))->all())->toBeEmpty();
});

test('tool:list --registry-only never writes to the registry', function (): void {
    $registry = Tests\Support\FakeToolRegistry::install();
    toolListRefreshFakes();

    Artisan::call('tool:list local --json --registry-only --no-interaction');

    expect($registry->writes)->toBeEmpty();
});

test('tool:list --refresh discovers only convention-following deployments', function (): void {
    toolListRefreshFakes();
    [$output] = toolListRefreshRun();

    // Instance and host are derived independently, then matched by round-tripping
    // the host back through instanceSlugFromHost() — not guessed.
    expect($output)->toContain('notes')
        ->toContain('notes-luchtech-dev')
        ->toContain('notes.luchtech.dev')
        // The enum gap that made this invisible is closed.
        ->toContain('monitor-luchtech-dev');
});

test('tool:list --refresh skips unsuffixed deployments and reports them as a migration list', function (): void {
    toolListRefreshFakes();
    [$output, $termwind] = toolListRefreshRun();

    // drive-ocis carries no recoverable identity, so it is listed for migration
    // rather than silently registered under a guessed instance.
    expect($termwind)->toContain('no instance suffix')
        ->and($output)->toContain('drive-ocis')
        ->toContain('kube-state-metrics')
        // ...and never becomes a registry row.
        ->and($output)->not->toContain('drive-ocis  ');
});

test('tool:list --refresh excludes headless tools by design, not as a migration failure', function (): void {
    toolListRefreshFakes();
    [$output, $termwind] = toolListRefreshRun();

    // ExternalDNS conforms to the convention but has no ingress of its own.
    // service() === null already models that, so it is reported as headless —
    // never as a row needing a host, and never as a migration failure.
    expect($termwind)->toContain('headless')
        ->toContain('dns')
        ->and($output)->not->toContain('external-dns');
});

test('tool:list --refresh --dry-run never writes to the registry', function (): void {
    toolListRefreshFakes();
    [, $termwind] = toolListRefreshRun();

    expect($termwind)->toContain('Nothing changed');

    Process::assertNotRan(fn ($process) => str_contains(
        (string) $process->command, 'create secret generic larakube-tools-registry',
    ));
});

test('tool:list maps legacy registered tools to canonical tools with multi-category descriptors', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'crm', 'instance' => 'crm-luchtech-dev', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'crm.luchtech.dev'],
            ])),
        ),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('tool:list local --json');
    $output = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    $twentyRow = array_values(array_filter($output, fn ($r) => $r['tool'] === 'twenty'))[0] ?? null;

    expect($twentyRow)->not->toBeNull()
        ->and($twentyRow['installed'])->toBeTrue()
        ->and($twentyRow['brand'])->toBe('Twenty [crm-luchtech-dev]')
        ->and($twentyRow['categories'])->toContain('communication')
        ->and($twentyRow['categories'])->toContain('productivity')
        ->and($twentyRow['categories'])->toContain('backend')
        ->and($twentyRow['categories'])->toContain('database');
});

test('tool:list prunes leaked subcomponents, duplicate unshipped tools, and invalid single-instance entries', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'netbird', 'instance' => 'vpn-luchtech-dev', 'host' => 'vpn.luchtech.dev'],
                ['tool' => 'netbird', 'instance' => 'client-vpn-luchtech-dev', 'host' => 'vpn.luchtech.dev'],
                ['tool' => 'netbird', 'instance' => 'dashboard-vpn-luchtech-dev', 'host' => 'vpn.luchtech.dev'],
                ['tool' => 'gitea', 'instance' => 'git-luchtech-dev', 'host' => 'git.luchtech.dev'],
                ['tool' => 'forgejo', 'instance' => 'git-luchtech-dev', 'host' => 'git.luchtech.dev'],
                ['tool' => 'grafana', 'instance' => 'monitor-luchtech-dev', 'host' => 'monitor.luchtech.dev'],
                ['tool' => 'grafana', 'instance' => 'matrix-forwarder'],
            ])),
        ),
        '*get deployment -n larakube-shared*' => Process::result(output: ''),
        '*get deployment -n larakube-vpn*' => Process::result(output: ''),
        '*create secret generic larakube-tools-registry*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('tool:list local --json');
    $output = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);

    $netbirdRows = array_values(array_filter($output, fn ($r) => $r['tool'] === 'netbird' && $r['installed']));
    expect($netbirdRows)->toHaveCount(1)
        ->and($netbirdRows[0]['instance'])->toBe('vpn-luchtech-dev');

    $grafanaRows = array_values(array_filter($output, fn ($r) => $r['tool'] === 'grafana' && $r['installed']));
    expect($grafanaRows)->toHaveCount(1)
        ->and($grafanaRows[0]['instance'])->toBe('monitor-luchtech-dev');

    $giteaRows = array_values(array_filter($output, fn ($r) => $r['tool'] === 'gitea' && $r['installed']));
    expect($giteaRows)->toBeEmpty();

    $forgejoRows = array_values(array_filter($output, fn ($r) => $r['tool'] === 'forgejo' && $r['installed']));
    expect($forgejoRows)->toHaveCount(1);
});

test('discoverConventionTools only discovers primary components and ignores subcomponents and forwarders', function (): void {
    Process::fake([
        '*config get-contexts*' => Process::result(output: 'local'),
        '*get secret larakube-tools-registry*' => Process::result(output: ''),
        '*get deployment*larakube-vpn*' => Process::result(
            output: "netbird-vpn-luchtech-dev\nnetbird-client-vpn-luchtech-dev\nnetbird-dashboard-vpn-luchtech-dev\nnetbird-relay-vpn-luchtech-dev\nnetbird-signal-vpn-luchtech-dev",
        ),
        '*get ingress*larakube-vpn*' => Process::result(output: 'vpn.luchtech.dev'),
        '*get deployment*larakube-shared*' => Process::result(
            output: "grafana-matrix-forwarder\ngrafana-monitor-luchtech-dev",
        ),
        '*get ingress*larakube-shared*' => Process::result(output: 'monitor.luchtech.dev'),
        '*create secret generic larakube-tools-registry*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    $command = new class extends App\Commands\Tool\ToolListCommand
    {
        public function testDiscovery(): array
        {
            return $this->discoverConventionTools('kubectl');
        }
    };

    $discovered = $command->testDiscovery();
    expect($discovered['found'])->toHaveKey('netbird|vpn-luchtech-dev')
        ->and($discovered['found'])->toHaveKey('grafana|monitor-luchtech-dev')
        ->and(array_keys($discovered['found']))->not->toContain('netbird|client-vpn-luchtech-dev')
        ->and(array_keys($discovered['found']))->not->toContain('netbird|dashboard-vpn-luchtech-dev')
        ->and(array_keys($discovered['found']))->not->toContain('netbird|relay-vpn-luchtech-dev')
        ->and(array_keys($discovered['found']))->not->toContain('netbird|signal-vpn-luchtech-dev')
        ->and(array_keys($discovered['found']))->not->toContain('grafana|matrix-forwarder')
        ->and($discovered['skipped'])->toContain('netbird-client-vpn-luchtech-dev')
        ->and($discovered['skipped'])->toContain('netbird-dashboard-vpn-luchtech-dev')
        ->and($discovered['skipped'])->toContain('netbird-relay-vpn-luchtech-dev')
        ->and($discovered['skipped'])->toContain('netbird-signal-vpn-luchtech-dev')
        ->and($discovered['skipped'])->toContain('grafana-matrix-forwarder');
});

test('tool:list prunes ghost engine entries sharing the same host when its deployment does not exist on cluster', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'n8n', 'instance' => 'flow-luchtech-dev', 'host' => 'flow.luchtech.dev'],
                ['tool' => 'windmill', 'instance' => '', 'host' => 'flow.luchtech.dev'],
            ])),
        ),
        // Only n8n is deployed
        '*get deployment -n larakube-shared*' => Process::result(output: 'n8n-flow-luchtech-dev'),
        '*create secret generic larakube-tools-registry*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('tool:list local --json');
    $output = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);

    $n8nRows = array_values(array_filter($output, fn ($r) => $r['tool'] === 'n8n' && $r['installed']));
    expect($n8nRows)->toHaveCount(1)
        ->and($n8nRows[0]['host'])->toBe('flow.luchtech.dev');

    $windmillRows = array_values(array_filter($output, fn ($r) => $r['tool'] === 'windmill' && $r['installed']));
    expect($windmillRows)->toBeEmpty();
});

test('tool:list prunes ghost multi-instance entries that lack both host and named instance when valid instances exist', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'pocketbase', 'instance' => 'pocket-test-1', 'host' => 'pocket-test-1.larakube.app'],
                ['tool' => 'pocketbase', 'instance' => 'pocket-test-2', 'host' => 'pocket-test-2.larakube.app'],
                ['tool' => 'pocketbase', 'instance' => null, 'host' => null],
            ])),
        ),
        '*get deployment -n larakube-shared*' => Process::result(output: "pocketbase-pocket-test-1\npocketbase-pocket-test-2"),
        '*create secret generic larakube-tools-registry*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('tool:list local --json');
    $output = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);

    $pocketbaseRows = array_values(array_filter($output, fn ($r) => $r['tool'] === 'pocketbase' && $r['installed']));
    expect($pocketbaseRows)->toHaveCount(2)
        ->and(array_column($pocketbaseRows, 'instance'))->toEqualCanonicalizing(['pocket-test-1', 'pocket-test-2'])
        ->and(array_column($pocketbaseRows, 'host'))->toEqualCanonicalizing(['pocket-test-1.larakube.app', 'pocket-test-2.larakube.app']);
});
