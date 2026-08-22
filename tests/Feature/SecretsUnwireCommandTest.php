<?php

use App\Http\Integrations\OpenBao\Requests\DynamicNoBodyRequest;
use App\Traits\InteractsWithToolRegistry;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

pest()->use(InteractsWithToolRegistry::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

// staticRoleExists()/deleteStaticRole() reach OpenBao over a REAL localhost
// HTTP call (openBaoApi() port-forwards then sends a Saloon request to
// http://localhost:{port}) — the port-forward Process itself is faked, but
// without this Saloon::fake(), the HTTP call was genuinely unmocked, and
// almost always failed fast (connection refused, interpreted as "role
// unreachable") by sheer luck. Confirmed live: under `pest --parallel`,
// this intermittently picked up a REAL response from an unrelated local
// service that happened to be listening on the random port
// (30100-31100), returning a real HTTP 404 that flipped
// staticRoleExists() from null to false and skipped the whole unwire path.
function fakeOpenBaoUnwireHttp(): void
{
    Saloon::fake([
        DynamicNoBodyRequest::class => MockResponse::make(['data' => ['rotation_period' => '86400s']]),
    ]);
}

test('secrets:unwire is registered and unwires OpenBao DB rotation for a tool', function (): void {
    fakeOpenBaoUnwireHttp();
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('root-token')),
        '*get secret sign-secrets*' => Process::result(output: 'found'),
        '*exec deploy/openbao-backend*' => Process::result(output: '{"data":{"rotation_period":"86400s"}}'),
        '*delete externalsecret*' => Process::result(output: 'deleted'),
        '*delete vaultdynamicsecret*' => Process::result(output: 'deleted'),
        '*bao delete database/static-roles*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('secrets:unwire local --tool=sign --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('DB password is now static');
});

test('secrets:unwire errors when OpenBao is not deployed', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('secrets:unwire local --tool=sign --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('OpenBao is not deployed on this cluster');
});

test('secrets:unwire supports unwiring git, notes, sheets, and chat tools', function (): void {
    foreach (['git' => 'forgejo', 'notes' => 'notes-secrets', 'sheets' => 'sheet-secrets', 'chat' => 'chat-secrets'] as $toolSlug => $secretName) {
        fakeOpenBaoUnwireHttp();
        Process::fake([
            '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('root-token')),
            "*get secret {$secretName}*" => Process::result(output: 'found'),
            '*exec deploy/openbao-backend*' => Process::result(output: '{"data":{"rotation_period":"86400s"}}'),
            '*delete externalsecret*' => Process::result(output: 'deleted'),
            '*delete vaultdynamicsecret*' => Process::result(output: 'deleted'),
            '*bao delete database/static-roles*' => Process::result(output: 'deleted'),
        ]);

        $this->artisan("secrets:unwire local --tool={$toolSlug} --force")
            ->assertExitCode(0)
            ->expectsOutputToContain('DB password is now static');
    }
});

test('secrets:unwire resolves environment context correctly for non-local environment (production)', function (): void {
    fakeOpenBaoUnwireHttp();
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('root-token')),
        '*get secret sign-secrets*' => Process::result(output: 'found'),
        '*exec deploy/openbao-backend*' => Process::result(output: '{"data":{"rotation_period":"86400s"}}'),
        '*delete externalsecret*' => Process::result(output: 'deleted'),
        '*delete vaultdynamicsecret*' => Process::result(output: 'deleted'),
        '*bao delete database/static-roles*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('secrets:unwire production --tool=sign --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('DB password is now static');
});
