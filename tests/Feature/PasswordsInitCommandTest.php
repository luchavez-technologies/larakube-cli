<?php

use Illuminate\Support\Facades\Process;

test('passwords:init never registers an OpenBao static role itself — only secrets:wire may hand rotation over', function (): void {
    // Same design principle enforced for git:init/monitor:init: {tool}:init
    // must not know or care whether OpenBao is installed. It writes a
    // locally-generated DATABASE_URL directly into vault-secrets (see the
    // Deployment template's secretKeyRef, rendered straight from the PHP
    // variable). Only secrets:wire may register a tool's DB password as an
    // OpenBao static role. resolveManagedDbPassword() is the one exception:
    // a READ-only check so a re-run doesn't clobber a password OpenBao
    // already owns from a PAST secrets:wire run.
    Process::fake([
        '*get secret vault-secrets*' => Process::result(output: '', exitCode: 1),
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => ['postgres' => ['enabled' => true]],
        ]),
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*port-forward*' => Process::result(output: ''),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*' => Process::result(),
    ]);

    // Only resolveManagedDbPassword()'s read-only lookup should ever hit
    // OpenBao's HTTP API from :init — nothing here is a static-role write.
    Http::fake(function (Illuminate\Http\Client\Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $path === '/v1/sys/mounts' => Http::response(['data' => ['database/' => ['type' => 'database']]]),
            $path === '/v1/database/static-creds/vaultwarden' => Http::response(['data' => []]),
            default => Http::response(['data' => []]),
        };
    });

    $this->artisan('passwords:init local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Vaultwarden stack is live.');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'externalsecret'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/database/static-roles/'));
});
