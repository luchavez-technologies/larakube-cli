<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

test('sso:wire is registered', function () {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('sso:wire');
});

test('sso:wire rejects a tool with no OIDC support', function () {
    $this->artisan('sso:wire', ['--tool' => 'git'])
        ->assertExitCode(1)
        ->expectsOutputToContain("can't be wired to SSO");
});

test('sso:wire rejects an unknown tool', function () {
    $this->artisan('sso:wire', ['--tool' => 'not-a-real-tool'])
        ->assertExitCode(1)
        ->expectsOutputToContain("Unknown tool 'not-a-real-tool'");
});

test('sso:wire errors when Zitadel is not installed', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: ''),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'monitor'])
        ->assertExitCode(1)
        ->expectsOutputToContain('Zitadel is not installed');
});

test('sso:wire registers a new OIDC client and wires it to Grafana', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment grafana*' => Process::result(output: 'grafana   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-monitor*' => Process::result(output: ''),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*set env deployment/grafana*' => Process::result(output: 'deployment.apps/grafana env updated'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/grafana restarted'),
    ]);

    Http::fake([
        '*apps/_search' => Http::response(['result' => []]),
        '*projects/_search' => Http::response(['result' => []]),
        '*/management/v1/projects' => Http::response(['id' => 'proj-1']),
        '*/apps/oidc' => Http::response(['appId' => 'app-1', 'clientId' => 'cid-1', 'clientSecret' => 'csecret-1']),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'monitor'])
        ->assertExitCode(0)
        ->expectsOutputToContain('Registering Monitoring Stack (Grafana + Loki + Prometheus) as an OIDC client in Zitadel')
        ->expectsOutputToContain('wired to Zitadel SSO');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/apps/oidc')
        && $request['redirectUris'][0] === 'https://grafana.'.App\Data\GlobalConfigData::load()->getLocalTld().'/login/generic_oauth');
});

test('sso:wire reuses an already-registered OIDC client', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment grafana*' => Process::result(output: 'grafana   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*sso-app-monitor*client-id*' => Process::result(output: base64_encode('cached-cid')),
        '*sso-app-monitor*client-secret*' => Process::result(output: base64_encode('cached-secret')),
        '*sso-app-monitor*app-id*' => Process::result(output: base64_encode('cached-appid')),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*set env deployment/grafana*' => Process::result(output: 'deployment.apps/grafana env updated'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/grafana restarted'),
    ]);

    // Keyed on the APP id, not the client id. Zitadel's app endpoint 404s on a
    // client id, so faking that URL only ever agreed with the bug.
    Http::fake([
        '*/management/v1/projects/_search' => Http::response(['result' => [['id' => 'proj-1']]]),
        '*/management/v1/projects/proj-1/apps/cached-appid' => Http::response(['app' => ['id' => 'cached-appid']]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'monitor'])
        ->assertExitCode(0)
        ->expectsOutputToContain("Reusing Monitoring Stack (Grafana + Loki + Prometheus)'s existing Zitadel OIDC client")
        ->expectsOutputToContain('wired to Zitadel SSO');

    // Reuse means reuse: no re-registration, so the client secret is not rotated.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/apps/oidc'));
});

test('sso:wire refuses webmail — Bulwark SSO is disabled (see docs/decisions/0001)', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment webmail-bulwark*' => Process::result(output: 'webmail-bulwark   1/1   1   1   10d'),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'webmail'])
        ->assertExitCode(1)
        ->expectsOutputToContain("can't be wired to SSO");
});

test('sso:wire --remove deregisters the app and unsets the tool\'s env vars', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment grafana*' => Process::result(output: 'grafana   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*sso-app-monitor*project-id*' => Process::result(output: base64_encode('proj-1')),
        '*sso-app-monitor*app-id*' => Process::result(output: base64_encode('app-1')),
        '*delete secret sso-app-monitor*' => Process::result(output: 'secret deleted'),
        '*set env deployment/grafana*' => Process::result(output: 'deployment.apps/grafana env updated'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/grafana restarted'),
    ]);

    Http::fake(['*/management/v1/projects/proj-1/apps/app-1' => Http::response([], 200)]);

    $this->artisan('sso:wire', ['--tool' => 'monitor', '--remove' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('no longer uses Zitadel SSO');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/management/v1/projects/proj-1/apps/app-1') && $request->method() === 'DELETE');
});
