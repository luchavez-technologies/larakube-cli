<?php

use App\Commands\Sso\SsoWireCommand;
use App\Data\GlobalConfigData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

test('sso:wire is registered', function () {
    $this->artisan('list --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('sso:wire');
});

test('sso:wire rejects a tool with no OIDC support', function () {
    $this->artisan('sso:wire', ['--tool' => 'dns', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain("can't be wired to SSO");
});

test('sso:wire rejects an unknown tool', function () {
    $this->artisan('sso:wire', ['--tool' => 'not-a-real-tool', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain("Unknown tool 'not-a-real-tool'");
});

test('sso:wire errors when Zitadel is not installed', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: ''),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'monitor', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('Zitadel is not installed');
});

test('sso:wire resolves a cloud tool host from the cluster registry when .larakube.json has none', function () {
    // Regression for a real live failure 2026-08-06: dashboard:init records
    // Headlamp's host via ResolvesToolHost::promptForCloudHost(), which
    // persists to the CLUSTER REGISTRY, not .larakube.json — the project
    // file's `hosts` map never gets a `dashboard` entry at all. sso:wire's
    // targetHost() only ever read .larakube.json, so it reported "No host is
    // configured for Kubernetes Control Plane (Headlamp) or Zitadel" even
    // though both :init commands had clearly already run. This fixture
    // mirrors that exact state: .larakube.json knows about `sso` (an older
    // tool, wired before the registry migration) but nothing about
    // `dashboard`, whose host lives only in the registry.
    $dir = sys_get_temp_dir().'/larakube-ssowire-test-'.uniqid();
    mkdir($dir);
    $cwd = getcwd();

    try {
        file_put_contents($dir.'/.larakube.json', json_encode([
            'name' => 'luchtech',
            'environments' => [
                'production' => [
                    'hosts' => ['sso' => 'sso.luchtech.dev'],
                ],
            ],
        ]));
        chdir($dir);

        Process::fake([
            '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
            '*get deployment dashboard-headlamp*' => Process::result(output: 'dashboard-headlamp   1/1   1   1   10d'),
            '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
            '*get secret sso-app-dashboard*' => Process::result(output: ''),
            '*get secret larakube-tools-registry*' => Process::result(
                output: base64_encode((string) json_encode([
                    ['tool' => 'dashboard', 'instance' => 'main', 'installed_at' => '2026-08-01T00:00:00+00:00', 'host' => 'dashboard.luchtech.dev'],
                ])),
            ),
            '*create secret generic*' => Process::result(output: 'secret created'),
            '*apply -f*' => Process::result(output: 'ingress applied'),
            '*set env deployment/dashboard-headlamp*' => Process::result(output: 'deployment.apps/dashboard-headlamp env updated'),
            '*rollout restart*' => Process::result(output: 'deployment.apps/dashboard-headlamp restarted'),
        ]);

        Http::fake([
            '*apps/_search' => Http::response(['result' => []]),
            '*projects/_search' => Http::response(['result' => []]),
            '*/management/v1/projects' => Http::response(['id' => 'proj-1']),
            '*/apps/oidc' => Http::response(['appId' => 'app-1', 'clientId' => 'cid-1', 'clientSecret' => 'csecret-1']),
            '*/management/v1/projects/proj-1' => Http::response(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
            '*/management/v1/projects/proj-1/roles/_search' => Http::response(['result' => []]),
            '*/management/v1/projects/proj-1/roles' => Http::response([]),
            '*/management/v1/actions/_search' => Http::response(['result' => []]),
            '*/management/v1/actions' => Http::response(['id' => 'action-1']),
            '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
            '*/management/v1/flows/2/trigger/*' => Http::response([]),
        ]);

        $this->artisan('sso:wire', ['environment' => 'production', '--tool' => 'dashboard', '--no-interaction' => true])
            ->assertExitCode(0)
            ->doesntExpectOutputToContain('No host is configured')
            ->expectsOutputToContain('wired to Zitadel SSO');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/apps/oidc')
            && str_contains($request['redirectUris'][0] ?? '', 'dashboard.luchtech.dev'));
    } finally {
        chdir($cwd);
        @unlink($dir.'/.larakube.json');
        @rmdir($dir);
    }
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
        // Grafana is RBAC-gated (ClusterTool::requiresRbacGating) — sso:wire
        // also ensures the LaraKube RBAC project's role-assertion, the
        // grafana-user role, and the org-wide claim-flattening Action.
        '*/management/v1/projects/proj-1' => Http::response(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        '*/management/v1/projects/proj-1/roles/_search' => Http::response(['result' => []]),
        '*/management/v1/projects/proj-1/roles' => Http::response([]),
        '*/management/v1/actions/_search' => Http::response(['result' => []]),
        '*/management/v1/actions' => Http::response(['id' => 'action-1']),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
        '*/management/v1/flows/2/trigger/*' => Http::response([]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'monitor', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Registering Monitoring Stack (Grafana + Loki + Prometheus) as an OIDC client in Zitadel')
        ->expectsOutputToContain('wired to Zitadel SSO');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/apps/oidc')
        && $request['redirectUris'][0] === 'https://grafana.'.GlobalConfigData::load()->getLocalTld().'/login/generic_oauth');
});

test('sso:wire registers oCIS Drive as a public PKCE client with its real callback URIs', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment drive-ocis*' => Process::result(output: 'drive-ocis   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-drive*' => Process::result(output: ''),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*set env deployment/drive-ocis*' => Process::result(output: 'deployment.apps/drive-ocis env updated'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/drive-ocis restarted'),
    ]);

    // Public SPA clients have no clientSecret — Zitadel omits it from the
    // create response. The wire must accept that and not choke.
    Http::fake([
        '*apps/_search' => Http::response(['result' => []]),
        '*projects/_search' => Http::response(['result' => []]),
        '*/management/v1/projects' => Http::response(['id' => 'proj-1']),
        '*/apps/oidc' => Http::response(['appId' => 'app-drive', 'clientId' => 'cid-drive']),
        // Drive ships PROXY_ROLE_ASSIGNMENT_DRIVER=oidc, so sso:wire must
        // ensure the flattenOcisRoles Action is attached to the token flow
        // and the ocisAdmin role already exists on the shared project.
        '*/management/v1/actions/_search' => Http::response(['result' => [['id' => 'action-ocis', 'name' => 'flattenOcisRoles', 'script' => SsoWireCommand::OCIS_ROLES_SCRIPT]]]),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
        '*/management/v1/flows/2/trigger/*' => Http::response([]),
        // projectRoleAssertion is what lets the Action see user grants at
        // runtime (the "no + New Space button" root cause) — a shared
        // project without the flag must be upgraded by the wire.
        '*/management/v1/projects/proj-1' => Http::response(['project' => ['id' => 'proj-1', 'name' => 'LaraKube Shared Tools']]),
        '*/management/v1/projects/proj-1/roles/_search' => function ($request) {
            $key = data_get($request, 'queries.0.keyQuery.key', '');

            return Http::response(['result' => [['key' => $key]]]);
        },
    ]);

    $tld = GlobalConfigData::load()->getLocalTld();

    $this->artisan('sso:wire', ['--tool' => 'drive', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('wired to Zitadel SSO');

    // oCIS web is an SPA: no client secret (authMethodType NONE, PKCE) and the
    // redirect URIs must be the callback pages the browser actually hits —
    // the tool root would make Zitadel 400 every authorize request. oCIS web's
    // RP-initiated logout also sends its origin root to end_session, so that
    // root is registered as the post-logout redirect URI (missing it 400s every
    // logout — the live bug fixed 2026-08-01).
    Http::assertSent(fn ($request) => str_contains($request->url(), '/apps/oidc')
        && $request['authMethodType'] === 'OIDC_AUTH_METHOD_TYPE_NONE'
        && $request['redirectUris'] === [
            "https://drive.{$tld}/oidc-callback.html",
            "https://drive.{$tld}/oidc-silent-redirect.html",
        ]
        && ($request['postLogoutRedirectUris'] ?? null) === ["https://drive.{$tld}/"]);

    // No client secret is stored for the public client, so nothing stale leaks
    // onto the deployment when applyToolEnv rewrites the secret.
    Process::assertRan(fn ($process) => str_contains($process->command, 'create secret generic sso-app-drive')
        && str_contains($process->command, '--from-literal=client-secret=')
        && ! str_contains($process->command, '--from-literal=client-secret=\'cid-drive'));
});

test('sso:wire re-registers a Drive app whose Zitadel registration is stale (confidential, wrong redirect URI)', function () {
    $tld = GlobalConfigData::load()->getLocalTld();

    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment drive-ocis*' => Process::result(output: 'drive-ocis   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        // A previous sso:wire run cached creds for a confidential client whose
        // only redirect URI was the tool root — the state found live on
        // production 2026-07-31, where the pod already ran the corrected env.
        '*sso-app-drive*client-id*' => Process::result(output: base64_encode('cid-stale')),
        '*sso-app-drive*client-secret*' => Process::result(output: base64_encode('secret-stale')),
        '*sso-app-drive*app-id*' => Process::result(output: base64_encode('app-stale')),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*set env deployment/drive-ocis*' => Process::result(output: 'deployment.apps/drive-ocis env updated'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/drive-ocis restarted'),
    ]);

    Http::fake([
        '*apps/_search' => Http::response(['result' => [['id' => 'app-stale']]]),
        '*projects/_search' => Http::response(['result' => [['id' => 'proj-1']]]),
        '*/management/v1/projects/proj-1/apps/app-stale' => Http::response(['app' => ['id' => 'app-stale', 'oidcConfig' => ['redirectUris' => ["https://drive.{$tld}/"]]]]),
        '*/apps/oidc' => Http::response(['appId' => 'app-drive', 'clientId' => 'cid-drive']),
        // The ocisRoles Action must be ensured (and found already attached),
        // and the ocisAdmin role must already exist on the shared project.
        '*/management/v1/actions/_search' => Http::response(['result' => [['id' => 'action-ocis', 'name' => 'flattenOcisRoles', 'script' => SsoWireCommand::OCIS_ROLES_SCRIPT]]]),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
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

    // The stale confidential registration is deleted and replaced by a public
    // PKCE client with the real callback pages, not silently reused.
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/projects/proj-1/apps/app-stale'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/apps/oidc')
        && $request['authMethodType'] === 'OIDC_AUTH_METHOD_TYPE_NONE'
        && $request['redirectUris'] === [
            "https://drive.{$tld}/oidc-callback.html",
            "https://drive.{$tld}/oidc-silent-redirect.html",
        ]);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/apps/oidc')
        && $request['authMethodType'] !== 'OIDC_AUTH_METHOD_TYPE_NONE');
});

test('sso:wire re-registers a Drive app whose redirect URIs match but post-logout URIs are missing', function () {
    $tld = GlobalConfigData::load()->getLocalTld();

    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment drive-ocis*' => Process::result(output: 'drive-ocis   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*sso-app-drive*client-id*' => Process::result(output: base64_encode('cid-live')),
        '*sso-app-drive*client-secret*' => Process::result(output: base64_encode('')),
        '*sso-app-drive*app-id*' => Process::result(output: base64_encode('app-live')),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*set env deployment/drive-ocis*' => Process::result(output: 'deployment.apps/drive-ocis env updated'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/drive-ocis restarted'),
    ]);

    // This is the exact production state found live 2026-08-01: the public app
    // has the correct authorize/silent-redirect URIs, but was registered before
    // post_logout_redirect_uri support, so it carries NO postLogoutRedirectUris
    // and every logout 400s with "post_logout_redirect_uri invalid". Matching
    // redirect URIs alone must NOT gate reuse — the post-logout set has to be
    // compared too, or re-wiring silently leaves logout broken.
    Http::fake([
        '*projects/_search' => Http::response(['result' => [['id' => 'proj-1']]]),
        // The name-keyed search inside zitadelCreateOidcApp finds the old app
        // and deletes it, then the fresh registration is created below.
        '*apps/_search' => Http::response(['result' => [['id' => 'app-live']]]),
        '*/management/v1/projects/proj-1/apps/app-live' => Http::response(['app' => ['id' => 'app-live', 'oidcConfig' => ['redirectUris' => [
            "https://drive.{$tld}/oidc-callback.html",
            "https://drive.{$tld}/oidc-silent-redirect.html",
        ]]]]),
        '*/apps/oidc' => Http::response(['appId' => 'app-drive', 'clientId' => 'cid-drive']),
        '*/management/v1/actions/_search' => Http::response(['result' => [['id' => 'action-ocis', 'name' => 'flattenOcisRoles', 'script' => SsoWireCommand::OCIS_ROLES_SCRIPT]]]),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
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

    // The old app is replaced (delete + re-register) and the new registration
    // carries the origin root as a post-logout redirect URI.
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/projects/proj-1/apps/app-live'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/apps/oidc')
        && ($request['postLogoutRedirectUris'] ?? null) === ["https://drive.{$tld}/"]);
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
        '*/apps/oidc' => Http::response(['appId' => 'app-drive', 'clientId' => 'cid-drive']),
        // flattenOcisRoles does not exist yet — it must be created and its
        // script attached to the token flow (triggers 4 and 5). The ocisAdmin
        // role does not exist yet either, so the wire creates it too.
        '*/management/v1/actions/_search' => Http::response(['result' => []]),
        '*/management/v1/actions' => Http::response(['id' => 'action-ocis']),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
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
        '*/apps/oidc' => Http::response(['appId' => 'app-drive', 'clientId' => 'cid-drive']),
        '*/management/v1/actions/_search' => Http::response(['result' => [['id' => 'action-ocis', 'name' => 'flattenOcisRoles', 'script' => 'function flattenOcisRoles(ctx, api) { let roles = ["ocisUser"]; }']]]),
        '*/management/v1/actions/action-ocis' => Http::response([]),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
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

test('sso:wire refreshes a stale flattenLaraKubeRoles script to add the groups claim', function () {
    // Regression for a real live gap (2026-08-06): granting dashboard-admin
    // in Zitadel did nothing on the cluster, because Headlamp authorizes via
    // Kubernetes impersonation (Impersonate-User/-Group), not its own OIDC
    // role check — and flattenLaraKubeRoles only ever emitted larakube_roles,
    // never the `groups` claim Headlamp forwards as Impersonate-Group. This
    // asserts an EXISTING (pre-groups) Action gets its script updated in
    // place, same self-heal as flattenOcisRoles above.
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment dashboard-headlamp*' => Process::result(output: 'dashboard-headlamp   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-dashboard*' => Process::result(output: ''),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*set env deployment/dashboard-headlamp*' => Process::result(output: 'deployment.apps/dashboard-headlamp env updated'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/dashboard-headlamp restarted'),
    ]);

    Http::fake([
        '*apps/_search' => Http::response(['result' => []]),
        '*projects/_search' => Http::response(['result' => []]),
        '*/management/v1/projects' => Http::response(['id' => 'proj-1']),
        '*/apps/oidc' => Http::response(['appId' => 'app-1', 'clientId' => 'cid-1', 'clientSecret' => 'csecret-1']),
        '*/management/v1/projects/proj-1' => Http::response(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        '*/management/v1/projects/proj-1/roles/_search' => function ($request) {
            $key = data_get($request, 'queries.0.keyQuery.key', '');

            return Http::response(['result' => [['key' => $key]]]);
        },
        '*/management/v1/actions/_search' => Http::response(['result' => [
            ['id' => 'action-rbac', 'name' => 'flattenLaraKubeRoles', 'script' => 'function flattenLaraKubeRoles(ctx, api) { api.v1.claims.setClaim("larakube_roles", []); }'],
        ]]),
        '*/management/v1/actions/action-rbac' => Http::response([]),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
        '*/management/v1/flows/2/trigger/*' => Http::response([]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'dashboard', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('wired to Zitadel SSO');

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/management/v1/actions/action-rbac')
        && str_contains($request['script'], 'setClaim("groups", roles)')
        && $request['fieldMask'] === ['paths' => ['name', 'script']]);
});

test('sso:wire turns projectRoleCheck on immediately, not just projectRoleAssertion', function () {
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
        // A fresh/never-configured project — neither flag set yet. This is
        // the actual "first tool wired on this cluster" scenario, distinct
        // from the other tests' already-both-true steady state.
        '*/management/v1/projects/proj-1' => Http::response(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC']]),
        '*/management/v1/projects/proj-1/roles/_search' => Http::response(['result' => []]),
        '*/management/v1/projects/proj-1/roles' => Http::response([]),
        '*/management/v1/actions/_search' => Http::response(['result' => []]),
        '*/management/v1/actions' => Http::response(['id' => 'action-1']),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
        '*/management/v1/flows/2/trigger/*' => Http::response([]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'monitor', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Nobody, including you, can SSO into Monitoring Stack (Grafana + Loki + Prometheus) until then');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/management/v1/projects/proj-1')
        && $request->method() === 'PUT'
        && $request['projectRoleAssertion'] === true
        && $request['projectRoleCheck'] === true);
});

test('sso:wire aborts before registering an OIDC client if role-gating setup fails', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment grafana*' => Process::result(output: 'grafana   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    // Simulates the real bug found live 2026-07-30: the claim-flattening
    // Action's search 400s. ensureRbacGating() must now surface this as a
    // hard failure instead of silently proceeding to register an OIDC app
    // and wire bound_claims/role_attribute_path against infrastructure that
    // was never confirmed to exist.
    Http::fake([
        '*projects/_search' => Http::response(['result' => []]),
        '*/management/v1/projects' => Http::response(['id' => 'proj-1']),
        '*/management/v1/projects/proj-1' => Http::response(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        '*/management/v1/actions/_search' => Http::response(['code' => 3, 'message' => 'proto: syntax error'], 400),
        '*/management/v1/actions' => Http::response(['code' => 6, 'message' => 'Errors.Action.AlreadyExists'], 409),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'monitor', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('Could not set up role-gated access');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/apps/oidc'));
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
    $tld = GlobalConfigData::load()->getLocalTld();
    Http::fake([
        '*/management/v1/projects/_search' => Http::response(['result' => [['id' => 'proj-1']]]),
        // The GET returns the registered app's current redirect URIs — the wire
        // command compares them against the tool's desired set and only reuses
        // when they still match (a stale registration, like drive's old
        // confidential root-only app, must be re-registered instead).
        '*/management/v1/projects/proj-1/apps/cached-appid' => Http::response(['app' => ['id' => 'cached-appid', 'oidcConfig' => ['redirectUris' => ["https://grafana.{$tld}/login/generic_oauth"]]]]),
        // Grafana is RBAC-gated — see the equivalent fakes in the "registers
        // a new OIDC client" test above for what each endpoint is for.
        '*/management/v1/projects/proj-1' => Http::response(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        '*/management/v1/projects/proj-1/roles/_search' => Http::response(['result' => []]),
        '*/management/v1/projects/proj-1/roles' => Http::response([]),
        '*/management/v1/actions/_search' => Http::response(['result' => []]),
        '*/management/v1/actions' => Http::response(['id' => 'action-1']),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
        '*/management/v1/flows/2/trigger/*' => Http::response([]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'monitor', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain("Reusing Monitoring Stack (Grafana + Loki + Prometheus)'s existing Zitadel OIDC client")
        ->expectsOutputToContain('wired to Zitadel SSO');

    // Reuse means reuse: no re-registration, so the client secret is not rotated.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/apps/oidc'));
});

test('sso:wire writes three bound_claims-gated roles to OpenBao, not one unconditional-admin role', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment openbao-backend*' => Process::result(output: 'openbao-backend   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-secrets*' => Process::result(output: ''),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('root-tok')),
        '*bao auth list*' => Process::result(output: '{}'),
        '*bao auth enable oidc*' => Process::result(),
        '*bao policy write*' => Process::result(),
        '*bao write auth/oidc/config*' => Process::result(),
        '*bao delete auth/oidc/role/user*' => Process::result(),
        '*bao write auth/oidc/role/*' => Process::result(),
    ]);

    Http::fake([
        '*apps/_search' => Http::response(['result' => []]),
        '*projects/_search' => Http::response(['result' => []]),
        '*/management/v1/projects' => Http::response(['id' => 'proj-1']),
        '*/apps/oidc' => Http::response(['appId' => 'app-1', 'clientId' => 'cid-1', 'clientSecret' => 'csecret-1']),
        '*/management/v1/projects/proj-1' => Http::response(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        '*/management/v1/projects/proj-1/roles/_search' => Http::response(['result' => []]),
        '*/management/v1/projects/proj-1/roles' => Http::response([]),
        '*/management/v1/actions/_search' => Http::response(['result' => []]),
        '*/management/v1/actions' => Http::response(['id' => 'action-1']),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
        '*/management/v1/flows/2/trigger/*' => Http::response([]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'secrets', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('wired to Zitadel SSO');

    // The old unconditional-admin role must be gone, and config must no
    // longer fall back to it by default.
    Process::assertRan(fn ($process) => str_contains($process->command, 'bao delete auth/oidc/role/user'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'bao write auth/oidc/config')
        && ! str_contains($process->command, 'default_role'));

    // Each tier is gated on its own larakube_roles value, not shared. The
    // role is written as one JSON document piped over stdin (`write PATH
    // -`), not per-field key=value args — bao's k=v parser sends every
    // value as a JSON string, and never coerces a string into the "map"
    // type bound_claims needs, so per-field bound_claims='{"...":".."}'
    // 400s server-side even though the string is valid JSON. Confirmed live.
    foreach (['admin' => 'openbao-admin', 'operator' => 'openbao-operator', 'auditor' => 'openbao-auditor'] as $role => $roleKey) {
        Process::assertRan(function ($process) use ($role, $roleKey) {
            $expectedJson = json_encode([
                'bound_claims' => ['larakube_roles' => $roleKey],
                'bound_claims_type' => 'string',
                'policies' => ["{$role}-policy"],
                'default_ttl' => '30m',
                'max_ttl' => '4h',
                'max_age' => '3600',
            ]);
            // Built as one associative array in the app code (different key
            // order than this test's), so compare the decoded payload
            // rather than the raw string.
            if (! str_contains($process->command, "bao write auth/oidc/role/{$role} -")) {
                return false;
            }

            if (! preg_match('/printf "%s" (\'.*\') \|/', $process->command, $m)) {
                return false;
            }

            $piped = json_decode(trim($m[1], "'"), true);
            $expected = json_decode($expectedJson, true);

            return $piped !== null
                && $piped['bound_claims'] === $expected['bound_claims']
                && $piped['bound_claims_type'] === $expected['bound_claims_type']
                && $piped['policies'] === $expected['policies']
                && $piped['default_ttl'] === $expected['default_ttl']
                && $piped['max_ttl'] === $expected['max_ttl']
                && $piped['max_age'] === $expected['max_age'];
        });
    }
});

test('sso:wire refuses webmail — Bulwark SSO is disabled (see docs/decisions/0001)', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment webmail-bulwark*' => Process::result(output: 'webmail-bulwark   1/1   1   1   10d'),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'webmail', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain("can't be wired to SSO");
});

test('sso:wire registers a new OIDC client and wires it to Kutt (link)', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment link-kutt*' => Process::result(output: 'link-kutt   1/1   1   1   1d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-link*' => Process::result(output: ''),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*set env deployment/link-kutt*' => Process::result(output: 'deployment.apps/link-kutt env updated'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/link-kutt restarted'),
    ]);

    Http::fake([
        '*apps/_search' => Http::response(['result' => []]),
        '*projects/_search' => Http::response(['result' => []]),
        '*/management/v1/projects' => Http::response(['id' => 'proj-1']),
        '*/apps/oidc' => Http::response(['appId' => 'app-1', 'clientId' => 'cid-1', 'clientSecret' => 'csecret-1']),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'link', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Registering Link Management (Kutt) as an OIDC client in Zitadel')
        ->expectsOutputToContain('Link Management (Kutt) is wired to Zitadel SSO');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/apps/oidc')
        && $request['redirectUris'][0] === 'https://link.'.GlobalConfigData::load()->getLocalTld().'/login/oidc');

    // Kutt is an open-to-org tool: wiring must patch the deployment with the
    // link-kutt-oidc secret and flip OIDC_ENABLED on — no RBAC roles.
    Process::assertRan(fn ($process) => str_contains($process->command, 'set env deployment/link-kutt')
        && str_contains($process->command, 'OIDC_ENABLED'));
});

test('sso:wire registers a new OIDC client and wires it to Directus (data)', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment data-directus*' => Process::result(output: 'data-directus   1/1   1   1   1d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-data*' => Process::result(output: ''),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*set env deployment/data-directus*' => Process::result(output: 'deployment.apps/data-directus env updated'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/data-directus restarted'),
    ]);

    Http::fake([
        '*apps/_search' => Http::response(['result' => []]),
        '*projects/_search' => Http::response(['result' => []]),
        '*/management/v1/projects' => Http::response(['id' => 'proj-1']),
        '*/apps/oidc' => Http::response(['appId' => 'app-data', 'clientId' => 'cid-data', 'clientSecret' => 'csecret-data']),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'data', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Registering Headless CMS & Data API (PocketBase or Directus) as an OIDC client in Zitadel')
        ->expectsOutputToContain('Headless CMS & Data API (PocketBase or Directus) is wired to Zitadel SSO');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/apps/oidc')
        && $request['redirectUris'][0] === 'https://data.'.GlobalConfigData::load()->getLocalTld().'/auth/login/zitadel/callback');

    Process::assertRan(fn ($process) => str_contains($process->command, 'set env deployment/data-directus'));
});

test('sso:wire registers a new OIDC client and wires it to PocketBase (data)', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment data-pocketbase*' => Process::result(output: 'data-pocketbase-pocket-luchtech-dev   1/1   1   1   10d'),
        '*get deployment -n larakube-shared*' => Process::result(output: 'data-pocketbase-pocket-luchtech-dev   1/1   1   1   10d'),
        '*get configmap larakube-registry*' => Process::result(output: json_encode([
            'services' => [
                'sso' => ['host' => 'sso.luchtech.dev'],
                'data' => ['host' => 'pocket.luchtech.dev', 'engine' => 'pocketbase'],
            ],
        ])),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-data*' => Process::result(output: ''),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*set env deployment*' => Process::result(output: 'env updated'),
        '*rollout restart*' => Process::result(output: 'restarted'),
    ]);

    Http::fake([
        '*apps/_search' => Http::response(['result' => []]),
        '*projects/_search' => Http::response(['result' => []]),
        '*/apps/oidc' => Http::response(['appId' => 'app-data', 'clientId' => 'cid-data', 'clientSecret' => 'csecret-data']),
        '*/management/v1/projects' => Http::response(['id' => 'proj-1']),
    ]);

    $dir = sys_get_temp_dir().'/larakube-pb-ssowire-test-'.uniqid();
    mkdir($dir);
    $cwd = getcwd();

    try {
        file_put_contents($dir.'/.larakube.json', json_encode([
            'name' => 'luchtech',
            'environments' => [
                'production' => [
                    'hosts' => ['sso' => 'sso.luchtech.dev', 'data' => 'pocket.luchtech.dev'],
                ],
            ],
        ]));
        chdir($dir);

        $this->artisan('sso:wire', ['environment' => 'production', '--tool' => 'data', '--engine' => 'pocketbase', '--no-interaction' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Registering Headless CMS & Data API (PocketBase or Directus) as an OIDC client in Zitadel')
            ->expectsOutputToContain('Headless CMS & Data API (PocketBase or Directus) is wired to Zitadel SSO');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/apps/oidc')
            && $request['redirectUris'][0] === 'https://pocket.luchtech.dev/api/oauth2-callback');
    } finally {
        chdir($cwd);
    }
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

    $this->artisan('sso:unwire', ['--tool' => 'monitor', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('no longer uses Zitadel SSO');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/management/v1/projects/proj-1/apps/app-1') && $request->method() === 'DELETE');
});
