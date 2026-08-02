<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

test('tool:list detects tools live on the cluster even if missing from registry secret and auto-reconciles their host', function () {
    Process::fake([
        // Empty registry secret
        '*get secret larakube-tools-registry*' => Process::result(output: ''),
        // Stalwart (Mail) is present on cluster
        '*get deployment stalwart -n larakube-shared*' => Process::result(output: 'deployment.apps/stalwart created'),
        // Ingress holds send.luchtech.dev
        '*get ingress -n larakube-shared -o jsonpath*' => Process::result(output: 'send.luchtech.dev'),
        // Catch-all process
        '*' => Process::result(output: ''),
    ]);

    $exit = Illuminate\Support\Facades\Artisan::call('tool:list local --json');
    $output = json_decode(Illuminate\Support\Facades\Artisan::output(), true);

    expect($exit)->toBe(0);
    $mailRow = array_values(array_filter($output, fn ($r) => $r['tool'] === 'mail'))[0] ?? null;

    expect($mailRow)->not->toBeNull()
        ->and($mailRow['installed'])->toBeTrue()
        ->and($mailRow['url'])->toBe('https://send.luchtech.dev');
});

test('tool:list surfaces OpenBao rotation status for an installed DB-backed tool', function () {
    // Regression guard: this rotation column replaces the ad-hoc, now-deleted
    // "Cluster Tools using Plex" section that used to live on plex:show —
    // confirmed live 2026-08-02 that section had drifted (still said 'gitea'
    // after the Forgejo rename) and duplicated logic ClusterTool already owns.
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                'mail' => ['installed_at' => 1700000000, 'host' => 'send.luchtech.dev'],
            ])),
        ),
        '*get deployment stalwart -n larakube-shared*' => Process::result(output: 'deployment.apps/stalwart created'),
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    Http::fake(function ($request) {
        if (str_contains($request->url(), '/database/static-roles/stalwart')) {
            return Http::response(['data' => ['db_name' => 'plex-postgres']], 200);
        }

        return Http::response([], 204);
    });

    $exit = Illuminate\Support\Facades\Artisan::call('tool:list local --json');
    $output = json_decode(Illuminate\Support\Facades\Artisan::output(), true);

    expect($exit)->toBe(0);
    $mailRow = array_values(array_filter($output, fn ($r) => $r['tool'] === 'mail'))[0] ?? null;

    expect($mailRow)->not->toBeNull()
        ->and($mailRow['db_role'])->toBe('stalwart')
        ->and($mailRow['rotation'])->toContain('OpenBao');

    // A tool with no Commons database at all (e.g. DNS) never even checks —
    // no per-row port-forward for something that can never have a schedule.
    $dnsRow = array_values(array_filter($output, fn ($r) => $r['tool'] === 'dns'))[0] ?? null;
    expect($dnsRow['db_role'])->toBeNull()
        ->and($dnsRow['rotation'])->toBeNull();
});

test('tool:list --installed filters out uninstalled tools', function () {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                'mail' => ['installed_at' => 1700000000, 'host' => 'send.luchtech.dev'],
            ])),
        ),
        '*' => Process::result(output: ''),
    ]);

    $exit = Illuminate\Support\Facades\Artisan::call('tool:list local --installed --json');
    $output = json_decode(Illuminate\Support\Facades\Artisan::output(), true);

    expect($exit)->toBe(0);
    $tools = array_column($output, 'tool');
    expect($tools)->toContain('mail')
        ->not->toContain('analytics');
});
