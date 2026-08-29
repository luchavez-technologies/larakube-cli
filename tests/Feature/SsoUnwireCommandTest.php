<?php

use App\Enums\ClusterTool;
use App\Http\Integrations\Netbird\Requests\DeleteIdentityProviderRequest;
use App\Http\Integrations\Netbird\Requests\ListIdentityProvidersRequest;
use App\Http\Integrations\Zitadel\Requests\DeleteProjectAppRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('sso:unwire is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('sso:unwire');
});

test('sso:unwire --domain= targets a specific instance instead of always the default', function (): void {
    // Regression test: sso:unwire had NO instance/domain targeting at all
    // before — it always unwired the tool's single default instance,
    // resolved via oidcEnv($engine) with no $instance argument. This proves
    // it now derives the instance from --domain= the same way sso:wire does.
    // Uses DATA/pocketbase because its oidcEnv() schema is the one that
    // correctly threads $instance through both 'deployment' and 'secret' —
    // Directus's schema has a separate, pre-existing (unrelated) gap where
    // its 'deployment'/'secret' keys are instance-invariant literals.
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment data-pocketbase-blog-example-com*' => Process::result(output: 'data-pocketbase-blog-example-com   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*sso-app-data*project-id*' => Process::result(output: base64_encode('proj-1')),
        '*sso-app-data*app-id*' => Process::result(output: base64_encode('app-1')),
        '*delete secret sso-app-data*' => Process::result(output: 'secret deleted'),
        '*delete secret data-oidc-blog-example-com*' => Process::result(output: 'secret deleted'),
        '*set env deployment/data-pocketbase-blog-example-com*' => Process::result(output: 'env updated'),
        '*rollout restart*' => Process::result(output: 'restarted'),
    ]);

    Saloon::fake([DeleteProjectAppRequest::class => MockResponse::make([], 200)]);

    $this->artisan('sso:unwire', ['--tool' => 'data', '--engine' => 'pocketbase', '--domain' => 'blog.example.com', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('no longer uses Zitadel SSO');

    Process::assertRan(fn ($process) => str_contains($process->command, 'set env deployment/data-pocketbase-blog-example-com'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'delete secret data-oidc-blog-example-com'));
});

test('sso:unwire delegates to sso:wire --remove', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment*grafana*' => Process::result(output: 'monitor-grafana-grafana-dev-test   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*sso-app-monitor*project-id*' => Process::result(output: base64_encode('proj-1')),
        '*sso-app-monitor*app-id*' => Process::result(output: base64_encode('app-1')),
        '*delete secret sso-app-monitor*' => Process::result(output: 'secret deleted'),
        '*set env deployment/*grafana*' => Process::result(output: 'deployment.apps/grafana env updated'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/grafana restarted'),
    ]);

    Saloon::fake([DeleteProjectAppRequest::class => MockResponse::make([], 200)]);

    $this->artisan('sso:unwire', ['--tool' => 'monitor'])
        ->assertExitCode(0)
        ->expectsOutputToContain('no longer uses Zitadel SSO');
});

test('sso:unwire deletes a legacy "Login with SSO" Forgejo source', function (): void {
    // The unwire matcher used to look for the canonical `zitadel` name only,
    // so a source left behind by an older wiring (named after the display
    // label) was never deleted — `sso:unwire` silently did nothing.
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment forgejo*' => Process::result(output: 'forgejo   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*admin auth list*' => Process::result(output: "ID\tName\tType\tEnabled\n".'4'."\t"."Login with SSO\t".'OpenID Connect'."\t".'true'),
        '*admin auth delete*' => Process::result(output: 'source deleted'),
    ]);

    Saloon::fake([DeleteProjectAppRequest::class => MockResponse::make([], 200)]);

    $this->artisan('sso:unwire', ['--tool' => 'git', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('no longer uses Zitadel SSO');

    Process::assertRan(fn ($process) => str_contains($process->command, 'admin auth delete --id 4'));
});

test('sso:unwire deregisters NetBird\'s Zitadel identity provider via its own REST API', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment vpn-management*' => Process::result(output: 'vpn-management   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*sso-app-vpn*project-id*' => Process::result(output: base64_encode('proj-1')),
        '*sso-app-vpn*app-id*' => Process::result(output: base64_encode('app-vpn')),
        '*delete secret sso-app-vpn*' => Process::result(output: 'secret deleted'),
        '*delete secret vpn-management-oidc*' => Process::result(output: 'secret deleted'),
        '*vpn-management-secrets*data.pat*' => Process::result(output: base64_encode('netbird-pat')),
    ]);

    Saloon::fake([
        DeleteProjectAppRequest::class => MockResponse::make([], 200),
        ListIdentityProvidersRequest::class => MockResponse::make([
            ['id' => 'idp-1', 'type' => 'zitadel', 'name' => 'Zitadel'],
        ], 200),
        DeleteIdentityProviderRequest::class => MockResponse::make([], 200),
    ]);

    $this->artisan('sso:unwire', ['--tool' => 'vpn', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('no longer uses Zitadel SSO');

    Saloon::assertSent(fn ($request) => $request instanceof DeleteIdentityProviderRequest
        && str_contains($request->resolveEndpoint(), 'idp-1'));
});

test('sso:unwire for NetBird is a clean no-op when no zitadel identity provider is registered', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment vpn-management*' => Process::result(output: 'vpn-management   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*sso-app-vpn*' => Process::result(output: '', exitCode: 1),
        '*delete secret sso-app-vpn*' => Process::result(output: 'secret deleted'),
        '*delete secret vpn-management-oidc*' => Process::result(output: 'secret deleted'),
        '*vpn-management-secrets*data.pat*' => Process::result(output: base64_encode('netbird-pat')),
    ]);

    Saloon::fake([
        ListIdentityProvidersRequest::class => MockResponse::make([], 200),
    ]);

    $this->artisan('sso:unwire', ['--tool' => 'vpn', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('no longer uses Zitadel SSO');

    Saloon::assertNotSent(DeleteIdentityProviderRequest::class);
});

test('sso:unwire removes the same OIDC secret sso:wire wrote', function (): void {
    // wire wrote vpn-management-oidc-{instance} (via vpnName()) while unwire
    // deleted $schema['secret'], which was unsuffixed — so the marker survived
    // and tool:list kept reporting the tool as SSO-wired after unwiring it.
    $vpn = ClusterTool::VPN;
    $instance = $vpn->instanceSlugFromHost('vpn.luchtech.dev');

    expect($vpn->oidcEnv(instance: $instance)['secret'])
        ->toBe('vpn-management-oidc-vpn-luchtech-dev');

    // Bare stays bare — that is the not-yet-registered case, and what
    // ClusterTool::forDeployment()-style lookups match against.
    expect($vpn->oidcEnv()['secret'])->toBe('vpn-management-oidc');

    // Both halves of the wiring agree by construction.
    expect($vpn->oidcEnv(instance: $instance)['deployment'])
        ->toBe('vpn-management-vpn-luchtech-dev');
});

test('sso:unwire lists only tools that are actually wired, by host', function (): void {
    // It used to list every SSO-CAPABLE tool with no host — offering things
    // never installed, and making an unwire of something unwired look like it
    // did work. "Wired" means the marker Secret sso:wire writes exists.
    Process::fake([
        '*larakube-tools-registry*' => Process::result(output: base64_encode((string) json_encode([
            ['tool' => 'vpn', 'instance' => 'vpn-luchtech-dev', 'host' => 'vpn.luchtech.dev'],
            ['tool' => 'notes', 'instance' => 'notes-luchtech-dev', 'host' => 'notes.luchtech.dev'],
        ]))),
        // Only VPN's marker exists, so only VPN is offered.
        '*get secret vpn-management-oidc-vpn-luchtech-dev*' => Process::result(output: 'vpn-management-oidc-vpn-luchtech-dev  Opaque  2  1d'),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('sso:unwire', ['--no-interaction' => false])
        ->expectsChoice('Which tool do you want to unwire from Zitadel SSO?', 'vpn|vpn.luchtech.dev', [
            'vpn|vpn.luchtech.dev' => 'Zero-Trust VPN Mesh (NetBird) (vpn.luchtech.dev)',
        ]);
});

test('sso:unwire says nothing is wired rather than listing every capable tool', function (): void {
    Process::fake([
        '*larakube-tools-registry*' => Process::result(output: base64_encode((string) json_encode([
            ['tool' => 'vpn', 'instance' => 'vpn-luchtech-dev', 'host' => 'vpn.luchtech.dev'],
        ]))),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('sso:unwire', ['--no-interaction' => false])
        ->assertExitCode(1)
        ->expectsOutputToContain('No tools are currently wired');
});
