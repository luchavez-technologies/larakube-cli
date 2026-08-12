<?php

use App\Traits\InteractsWithToolRegistry;
use Illuminate\Support\Facades\Process;

uses(InteractsWithToolRegistry::class);

test('tool:alias adds an alias domain to a registered tool and re-applies ingress', function () {
    $initialJson = json_encode([
        ['tool' => 'mail', 'instance' => 'main', 'host' => 'send.luchtech.dev', 'installedAt' => '2026-08-01T00:00:00+00:00'],
    ]);

    $updatedJson = json_encode([
        ['tool' => 'mail', 'instance' => 'main', 'host' => 'send.luchtech.dev', 'aliases' => ['send.next.site'], 'installedAt' => '2026-08-01T00:00:00+00:00'],
    ]);

    $callCount = 0;
    Process::fake([
        '*get secret larakube-tools-registry*' => function () use (&$callCount, $initialJson, $updatedJson) {
            $callCount++;
            $json = $callCount > 1 ? $updatedJson : $initialJson;

            return Process::result(output: base64_encode($json));
        },
        '*create secret generic larakube-tools-registry*' => Process::result(output: 'registry updated'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
    ]);

    $this->artisan('tool:alias', ['tool' => 'mail', 'alias' => 'send.next.site'])
        ->assertExitCode(0)
        ->expectsOutputToContain('Registered alias domain \'send.next.site\' for Mail Server (Stalwart)')
        ->expectsOutputToContain('https://send.next.site');
});

test('tool:alias --domain= targets the registered instance serving that host, not a derived slug', function () {
    // Regression guard (unified instance resolution, 2026-08-12): mail's
    // default host is send.luchtech.dev but its service hostPrefix is 'send'
    // — the old derivation produced the slug 'send-luchtech-dev' and died
    // with "instance is not registered" even though 'main' serves that host.
    // Host identity must win: --domain resolves to the REGISTERED instance.
    $registryJson = json_encode([
        ['tool' => 'mail', 'instance' => 'main', 'host' => 'send.luchtech.dev', 'installedAt' => '2026-08-01T00:00:00+00:00'],
    ]);

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode($registryJson)),
        '*create secret generic larakube-tools-registry*' => Process::result(output: 'registry updated'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
    ]);

    $this->artisan('tool:alias', ['tool' => 'mail', 'alias' => 'send.next.site', '--domain' => 'send.luchtech.dev'])
        ->assertExitCode(0)
        ->expectsOutputToContain('Registered alias domain \'send.next.site\' for Mail Server (Stalwart)');
});

test('tool:alias --remove removes an alias domain from a registered tool', function () {
    $registryJson = json_encode([
        ['tool' => 'mail', 'instance' => 'main', 'host' => 'send.luchtech.dev', 'aliases' => ['send.next.site'], 'installedAt' => '2026-08-01T00:00:00+00:00'],
    ]);

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode($registryJson)),
        '*create secret generic larakube-tools-registry*' => Process::result(output: 'registry updated'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
    ]);

    $this->artisan('tool:alias', ['tool' => 'mail', 'alias' => 'send.next.site', '--remove' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Removed alias domain \'send.next.site\' from Mail Server (Stalwart)');
});
