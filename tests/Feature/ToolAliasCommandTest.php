<?php

use App\Traits\InteractsWithToolRegistry;
use Illuminate\Support\Facades\Process;

uses(InteractsWithToolRegistry::class);

test('tool:alias adds an alias domain to a registered tool and re-applies ingress', function () {
    $initialJson = json_encode([
        'mail' => [
            'host' => 'send.luchtech.dev',
            'installed_at' => 1700000000,
        ],
    ]);

    $updatedJson = json_encode([
        'mail' => [
            'host' => 'send.luchtech.dev',
            'alias_hosts' => ['send.next.site'],
            'installed_at' => 1700000000,
        ],
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

    $this->artisan('tool:alias', ['tool' => 'mail', 'domain' => 'send.next.site'])
        ->assertExitCode(0)
        ->expectsOutputToContain('Registered alias domain \'send.next.site\' for Mail Server (Stalwart)')
        ->expectsOutputToContain('https://send.next.site');
});

test('tool:alias --remove removes an alias domain from a registered tool', function () {
    $registryJson = json_encode([
        'mail' => [
            'host' => 'send.luchtech.dev',
            'alias_hosts' => ['send.next.site'],
            'installed_at' => 1700000000,
        ],
    ]);

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode($registryJson)),
        '*create secret generic larakube-tools-registry*' => Process::result(output: 'registry updated'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
    ]);

    $this->artisan('tool:alias', ['tool' => 'mail', 'domain' => 'send.next.site', '--remove' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Removed alias domain \'send.next.site\' from Mail Server (Stalwart)');
});
