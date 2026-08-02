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

test('sso:wire for Drive installs the ocisRoles claim Action and grants ocisAdmin via --admin-email', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment drive-ocis*' => Process::result(output: 'drive-ocis   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-drive*' => Process::result(output: ''),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*set env deployment/drive-ocis*' => Process::result(output: 'deployment.apps/drive-ocis env updated'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/drive-ocis restarted'),
    ]);

    Http::fake([
        '*apps/_search' => Http::response(['result' => []]),
        '*projects/_search' => Http::response(['result' => []]),
        '*/management/v1/projects' => Http::response(['id' => 'proj-1']),
        '*/apps/oidc' => Http::response(['appId' => 'app-drive', 'clientId' => 'cid-drive', 'clientSecret' => 'csecret-drive']),
        // flattenOcisRoles does not exist yet — it must be created and its
        // script attached to the token flow (triggers 4 and 5). The ocisAdmin
        // role does not exist yet either, so the wire creates it too.
        '*/management/v1/actions/_search' => Http::response(['result' => []]),
        '*/management/v1/actions' => Http::response(['id' => 'action-ocis']),
        '*/management/v1/flows/2/trigger/*' => Http::response([]),
        // Shared project without projectRoleAssertion — the wire must flip it
        // (without projectRoleCheck, which would lock out zero-role members).
        '*/management/v1/projects/proj-1' => Http::response(['project' => ['id' => 'proj-1', 'name' => 'LaraKube Shared Tools']]),
        '*/management/v1/projects/proj-1/roles/_search' => Http::response(['result' => []]),
        '*/management/v1/projects/proj-1/roles' => Http::response([]),
        '*/v2/users' => Http::response(['result' => [['userId' => 'uid-1']]]),
        '*/management/v1/users/grants/_search' => Http::response(['result' => []]),
        '*/management/v1/users/uid-1/grants' => Http::response([]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'drive', '--admin-email' => 'admin@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('wired to Zitadel SSO')
        ->expectsOutputToContain("Granted 'ocisAdmin', 'ocisSpaceAdmin' to admin@luchtech.dev");

    // The org-wide Action was created with the ocisUser fallback so nobody
    // gets locked out under PROXY_ROLE_ASSIGNMENT_DRIVER=oidc — and with the
    // ocisSpaceAdmin upgrade path beside ocisAdmin (admin outranks spaceadmin).
    Http::assertSent(fn ($request) => str_contains($request->url(), '/management/v1/actions')
        && ! str_contains($request->url(), '_search')
        && $request->method() === 'POST'
        && str_contains($request['script'], '"ocisUser"')
        && str_contains($request['script'], '"ocisAdmin"')
        && str_contains($request['script'], '"ocisSpaceAdmin"')
        && str_contains($request['script'], 'roles[0] !== "ocisAdmin"')
        && str_contains($request['script'], 'setClaim("ocisRoles"'));

    // The shared project gains projectRoleAssertion — without it Zitadel
    // resolves an empty grants list into the Action context at runtime and
    // the ocisUser fallback fires for everyone (the "no + New Space button"
    // root cause). projectRoleCheck must stay off: zero-role members rely on
    // the ocisUser fallback to log in at all.
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/management/v1/projects/proj-1')
        && $request['projectRoleAssertion'] === true
        && ($request['projectRoleCheck'] ?? null) !== true);

    // Both drive admin roles were granted to the --admin-email user on the
    // shared project the drive app registers under — each as its own grant.
    foreach (['ocisAdmin', 'ocisSpaceAdmin'] as $roleKey) {
        Http::assertSent(fn ($request) => str_contains($request->url(), '/users/uid-1/grants')
            && ! str_contains($request->url(), '_search')
            && $request->method() === 'POST'
            && $request['roleKeys'] === [$roleKey]);
    }
});

test('sso:wire refreshes a stale flattenOcisRoles script instead of skipping the existing Action', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment drive-ocis*' => Process::result(output: 'drive-ocis   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-drive*' => Process::result(output: ''),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*set env deployment/drive-ocis*' => Process::result(output: 'deployment.apps/drive-ocis env updated'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/drive-ocis restarted'),
    ]);

    // The Action exists but carries the pre-ocisSpaceAdmin script — the exact
    // live state this upgrade ships over. sso:wire must PUT the new script
    // rather than treating the name match as "already done" (the old bug would
    // have left the claim emitter permanently stuck on ocisAdmin/ocisUser).
    Http::fake([
        '*apps/_search' => Http::response(['result' => []]),
        '*projects/_search' => Http::response(['result' => []]),
        '*/management/v1/projects' => Http::response(['id' => 'proj-1']),
        '*/apps/oidc' => Http::response(['appId' => 'app-drive', 'clientId' => 'cid-drive', 'clientSecret' => 'csecret-drive']),
        '*/management/v1/actions/_search' => Http::response(['result' => [['id' => 'action-ocis', 'name' => 'flattenOcisRoles', 'script' => 'function flattenOcisRoles(ctx, api) { let roles = ["ocisUser"]; }']]]),
        '*/management/v1/actions/action-ocis' => Http::response([]),
        '*/management/v1/flows/2/trigger/*' => Http::response([]),
        '*/management/v1/projects/proj-1' => Http::response(['project' => ['id' => 'proj-1', 'name' => 'LaraKube Shared Tools']]),
        '*/management/v1/projects/proj-1/roles/_search' => function ($request) {
            $key = data_get($request, 'queries.0.keyQuery.key', '');

            return Http::response(['result' => [['key' => $key]]]);
        },
    ]);

    $this->artisan('sso:wire', ['--tool' => 'drive', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('wired to Zitadel SSO');

    // The stale script is updated in place with the new ocisSpaceAdmin-aware
    // emitter — never recreated, never silently left behind.
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/management/v1/actions/action-ocis')
        && str_contains($request['script'], '"ocisSpaceAdmin"')
        && $request['fieldMask'] === ['paths' => ['name', 'script']]);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/management/v1/actions')
        && ! str_contains($request->url(), '_search'));
});
