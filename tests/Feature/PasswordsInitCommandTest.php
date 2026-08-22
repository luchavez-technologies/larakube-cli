<?php

use App\Http\Integrations\OpenBao\Requests\DynamicNoBodyRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

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
    Saloon::fake([
        DynamicNoBodyRequest::class => openBaoFake([
            '*/v1/sys/mounts' => ['data' => ['database/' => ['type' => 'database']]],
            '*/v1/database/static-creds/vaultwarden' => ['data' => []],
        ], default: ['data' => []]),
    ]);

    $this->artisan('passwords:init local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Vaultwarden stack is live.');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'externalsecret'));
    Saloon::assertNotSent(fn ($request) => str_contains($request->resolveEndpoint(), '/v1/database/static-roles/'));
});
