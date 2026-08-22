<?php

use App\Commands\Sso\SsoWireCommand;
use App\Data\GlobalConfigData;
use App\Http\Integrations\Zitadel\Requests\SearchUsersRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Spatie\TemporaryDirectory\TemporaryDirectory;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('sso:wire is registered', function (): void {
    $this->artisan('list --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('sso:wire');
});

test('sso:wire rejects a tool with no OIDC support', function (): void {
    $this->artisan('sso:wire', ['--tool' => 'dns', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain("can't be wired to SSO");
});

test('sso:wire rejects an unknown tool', function (): void {
    $this->artisan('sso:wire', ['--tool' => 'not-a-real-tool', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain("Unknown tool 'not-a-real-tool'");
});

test('sso:wire errors when Zitadel is not installed', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: ''),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'monitor', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('Zitadel is not installed');
});

test('sso:wire resolves a cloud tool host from the cluster registry when .larakube.json has none', function (): void {
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
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
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
        $temporaryDirectory->delete();
    }
});

test('sso:wire registers a new OIDC client and wires it to Grafana', function (): void {
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

    // The actual point of the 2026-08-20 per-tool-project change: Monitor
    // registers under its OWN project, not the old shared 'LaraKube RBAC'
    // bucket every RBAC-gated tool used to share (which meant a grant for
    // ANY one of them authenticated into ALL of them — projectRoleCheck is
    // project-wide in Zitadel, not per-role). The project name is the exact
    // live Deployment name (2026-08-20, replacing the earlier "LaraKube
    // RBAC: {brand}" scheme) — no separate naming convention to keep in sync.
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/management/v1/projects')
        && $request->method() === 'POST'
        && $request['name'] === 'grafana');
});

test('sso:wire --sso-only writes sso_only_vars into the Secret declaratively, never as a literal env override', function (): void {
    // ADR 0018: sso_only_vars merged into $staticVars must land in the
    // Secret (reached via --from=secret) — a literal `set env KEY=value`
    // pass would desync kubectl apply's bookkeeping for the next
    // monitor:init re-apply, exactly the bug this test guards against.
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
        '*/management/v1/projects/proj-1' => Http::response(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        '*/management/v1/projects/proj-1/roles/_search' => Http::response(['result' => []]),
        '*/management/v1/projects/proj-1/roles' => Http::response([]),
        '*/management/v1/actions/_search' => Http::response(['result' => []]),
        '*/management/v1/actions' => Http::response(['id' => 'action-1']),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
        '*/management/v1/flows/2/trigger/*' => Http::response([]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'monitor', '--sso-only' => true, '--no-interaction' => true])
        ->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'create secret generic grafana-oidc')
        && str_contains($process->command, "GF_AUTH_DISABLE_LOGIN_FORM='true'")
        && str_contains($process->command, "GF_USERS_ALLOW_SIGN_UP='false'"));

    // No literal `set env deployment/grafana GF_AUTH_DISABLE_LOGIN_FORM=...`
    // override — only --from=secret (declarative) and the harmless KEY-
    // unset shape are allowed to touch the Deployment directly.
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'set env deployment/grafana')
        && str_contains($process->command, 'GF_AUTH_DISABLE_LOGIN_FORM='));
});

test('sso:wire without --sso-only unsets a previously-written sso_only_var instead of leaving it stuck on', function (): void {
    // The one legitimate remaining imperative Deployment touch (ADR 0018
    // point 4): there's no declarative way to remove an env var a PAST
    // --sso-only run may have written, so toggling back off still needs
    // `kubectl set env deployment/X KEY-`.
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
        '*/management/v1/projects/proj-1' => Http::response(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        '*/management/v1/projects/proj-1/roles/_search' => Http::response(['result' => []]),
        '*/management/v1/projects/proj-1/roles' => Http::response([]),
        '*/management/v1/actions/_search' => Http::response(['result' => []]),
        '*/management/v1/actions' => Http::response(['id' => 'action-1']),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
        '*/management/v1/flows/2/trigger/*' => Http::response([]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'monitor', '--no-interaction' => true])
        ->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'set env deployment/grafana')
        && str_contains($process->command, 'GF_AUTH_DISABLE_LOGIN_FORM-')
        && str_contains($process->command, 'GF_USERS_ALLOW_SIGN_UP-'));

    // The unset command must be a pure removal — never carry a value for
    // the same key alongside the `-` suffix.
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'set env deployment/grafana')
        && str_contains($process->command, "GF_AUTH_DISABLE_LOGIN_FORM='true'"));
});

test('sso:wire registers oCIS Drive as a public PKCE client with its real callback URIs', function (): void {
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
    //
    // Drive moved to rbacRoles() alongside ssoAdminRoles() 2026-08-20 (at
    // the user's explicit request — a future partner given Drive access
    // must not thereby get default access to every other open-to-org tool)
    // — both ensureRbacGating() and ensureSsoAdminGating() now run against
    // Drive's own project, so this fakes both: the flattenLaraKubeRoles AND
    // flattenOcisRoles Actions, and all three roles (ocisUser from
    // rbacRoles(), ocisAdmin/ocisSpaceAdmin from ssoAdminRoles()).
    Http::fake([
        '*apps/_search' => Http::response(['result' => []]),
        '*projects/_search' => Http::response(['result' => []]),
        '*/management/v1/projects' => Http::response(['id' => 'proj-1']),
        '*/apps/oidc' => Http::response(['appId' => 'app-drive', 'clientId' => 'cid-drive']),
        '*/management/v1/actions/_search' => Http::response(['result' => []]),
        '*/management/v1/actions' => Http::response(['id' => 'action-1']),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
        '*/management/v1/flows/2/trigger/*' => Http::response([]),
        // Already asserted (both flags true) — this test isn't about the
        // projectRoleAssertion/projectRoleCheck state-transition dance,
        // that has its own dedicated coverage below.
        '*/management/v1/projects/proj-1' => Http::response(['project' => ['id' => 'proj-1', 'name' => 'drive-ocis', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        '*/management/v1/projects/proj-1/roles/_search' => Http::response(['result' => []]),
        '*/management/v1/projects/proj-1/roles' => Http::response([]),
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

test('sso:wire re-registers a Drive app whose Zitadel registration is stale (confidential, wrong redirect URI)', function (): void {
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
        // and the ocisAdmin role must already exist on the tool's own
        // project — Drive is rbacRoles()+ssoAdminRoles() together
        // (2026-08-20), so flattenLaraKubeRoles must also be ensured
        // (falls through to a fresh create, not found in this fake).
        '*/management/v1/actions/_search' => Http::response(['result' => [['id' => 'action-ocis', 'name' => 'flattenOcisRoles', 'script' => SsoWireCommand::OCIS_ROLES_SCRIPT]]]),
        '*/management/v1/actions' => Http::response(['id' => 'action-larakube']),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
        '*/management/v1/flows/2/trigger/*' => Http::response([]),
        '*/management/v1/projects/proj-1' => Http::response(['project' => ['id' => 'proj-1', 'name' => 'drive-ocis', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
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

test('sso:wire re-registers a Drive app whose redirect URIs match but post-logout URIs are missing', function (): void {
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
        '*/management/v1/actions' => Http::response(['id' => 'action-larakube']),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
        '*/management/v1/flows/2/trigger/*' => Http::response([]),
        '*/management/v1/projects/proj-1' => Http::response(['project' => ['id' => 'proj-1', 'name' => 'drive-ocis', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
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

test('sso:wire for Drive installs the ocisRoles claim Action, gates login via rbacRoles(), and grants ocisAdmin via --admin-email', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment drive-ocis*' => Process::result(output: 'drive-ocis   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-drive*' => Process::result(output: ''),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*set env deployment/drive-ocis*' => Process::result(output: 'deployment.apps/drive-ocis env updated'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/drive-ocis restarted'),
    ]);

    // Drive moved to rbacRoles() alongside ssoAdminRoles() 2026-08-20 (at
    // the user's explicit request) — ensureRbacGating() runs FIRST and sets
    // projectRoleAssertion+projectRoleCheck both true; ensureSsoAdminGating()
    // runs second and, seeing assertion already true, does NOT re-PUT (its
    // own check is satisfied by the first step alone) — this stateful fake
    // mirrors that real sequential dependency instead of a static snapshot.
    $projectGated = false;
    Http::fake([
        '*apps/_search' => Http::response(['result' => []]),
        '*projects/_search' => Http::response(['result' => []]),
        '*/management/v1/projects' => Http::response(['id' => 'proj-1']),
        '*/apps/oidc' => Http::response(['appId' => 'app-drive', 'clientId' => 'cid-drive']),
        // Neither Action exists yet — both must be created.
        '*/management/v1/actions/_search' => Http::response(['result' => []]),
        '*/management/v1/actions' => Http::response(['id' => 'action-ocis']),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
        '*/management/v1/flows/2/trigger/*' => Http::response([]),
        '*/management/v1/projects/proj-1' => function ($request) use (&$projectGated) {
            if ($request->method() === 'PUT') {
                $projectGated = true;

                return Http::response(['details' => []]);
            }

            return Http::response(['project' => [
                'id' => 'proj-1',
                'name' => 'drive-ocis',
                'projectRoleAssertion' => $projectGated,
                'projectRoleCheck' => $projectGated,
            ]]);
        },
        '*/management/v1/projects/proj-1/roles/_search' => Http::response(['result' => []]),
        '*/management/v1/projects/proj-1/roles' => Http::response([]),
        '*/management/v1/users/grants/_search' => Http::response(['result' => []]),
        '*/management/v1/users/uid-1/grants' => Http::response([]),
    ]);
    Saloon::fake([
        SearchUsersRequest::class => MockResponse::make(['result' => [['userId' => 'uid-1']]]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'drive', '--admin-email' => 'admin@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('wired to Zitadel SSO')
        // rbacRoles() now gates Drive's login itself — the same warning
        // every other RBAC-gated tool prints.
        ->expectsOutputToContain('Role-gated tool — login is denied until you grant a role')
        ->expectsOutputToContain('ocisUser')
        // The admin-tier section must NOT claim the tool is open-to-org —
        // that was true before Drive also had rbacRoles(), and printing it
        // now would directly contradict the role-gated warning above.
        ->expectsOutputToContain('Admin tiers on top of the role-gated login above')
        ->doesntExpectOutputToContain('Open-to-org tool')
        ->expectsOutputToContain("Granted 'ocisAdmin', 'ocisSpaceAdmin' to admin@luchtech.dev");

    // The ocisUser role (rbacRoles()) is created on Drive's own project —
    // the base gate an operator grants for plain "can log in" access.
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/management/v1/projects/proj-1/roles')
        && $request->method() === 'POST'
        && $request['roleKey'] === 'ocisUser');

    // The org-wide flattenOcisRoles Action was created with the ocisUser
    // fallback so nobody gets locked out under PROXY_ROLE_ASSIGNMENT_DRIVER=
    // oidc — and with the ocisSpaceAdmin upgrade path beside ocisAdmin
    // (admin outranks spaceadmin).
    Http::assertSent(fn ($request) => str_contains($request->url(), '/management/v1/actions')
        && ! str_contains($request->url(), '_search')
        && $request->method() === 'POST'
        && str_contains($request['script'], '"ocisUser"')
        && str_contains($request['script'], '"ocisAdmin"')
        && str_contains($request['script'], '"ocisSpaceAdmin"')
        && str_contains($request['script'], 'roles[0] !== "ocisAdmin"')
        && str_contains($request['script'], 'setClaim("ocisRoles"'));

    // Drive's own project gains BOTH flags — projectRoleCheck is the actual
    // login gate, unlike a plain ssoAdminRoles()-only tool (e.g. a future
    // one) where it must stay off so zero-role members can still log in.
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/management/v1/projects/proj-1')
        && $request['projectRoleAssertion'] === true
        && $request['projectRoleCheck'] === true);

    // Both drive admin roles were granted to the --admin-email user on
    // Drive's own project.
    foreach (['ocisAdmin', 'ocisSpaceAdmin'] as $roleKey) {
        Http::assertSent(fn ($request) => str_contains($request->url(), '/users/uid-1/grants')
            && ! str_contains($request->url(), '_search')
            && $request->method() === 'POST'
            && $request['roleKeys'] === [$roleKey]);
    }
});

test('sso:wire refreshes a stale flattenOcisRoles script instead of skipping the existing Action', function (): void {
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
        // Only flattenOcisRoles exists (stale script) — flattenLaraKubeRoles
        // (needed now that Drive also has rbacRoles()) doesn't, so it gets
        // freshly created via the generic '/management/v1/actions' POST.
        '*/management/v1/actions/_search' => Http::response(['result' => [['id' => 'action-ocis', 'name' => 'flattenOcisRoles', 'script' => 'function flattenOcisRoles(ctx, api) { let roles = ["ocisUser"]; }']]]),
        '*/management/v1/actions/action-ocis' => Http::response([]),
        '*/management/v1/actions' => Http::response(['id' => 'action-larakube']),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
        '*/management/v1/flows/2/trigger/*' => Http::response([]),
        '*/management/v1/projects/proj-1' => Http::response(['project' => ['id' => 'proj-1', 'name' => 'drive-ocis', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
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
    // flattenOcisRoles specifically is never recreated — the create POST
    // that does happen (flattenLaraKubeRoles, since it didn't exist yet) is
    // for a different Action and carries a different script entirely.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/management/v1/actions')
        && ! str_contains($request->url(), '_search')
        && $request->method() === 'POST'
        && str_contains($request['script'] ?? '', 'flattenOcisRoles'));
});

test('sso:wire refreshes a stale flattenLaraKubeRoles script to add the groups claim', function (): void {
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

test('sso:wire turns projectRoleCheck on immediately, not just projectRoleAssertion', function (): void {
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

test('sso:wire aborts before registering an OIDC client if role-gating setup fails', function (): void {
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

test('sso:wire gates the ForwardAuth proxy with --allowed-group for a role-gated tool', function (): void {
    // Record (Sendrec) has no native OIDC of its own — it's gated at the
    // ingress via the shared sso-proxy (ADR 0006). Added 2026-08-20: that
    // ADR's own "non-goals" section already named this gap
    // ("--email-domain=* admits any Zitadel user... for real authz, add
    // --allowed-groups fed by Zitadel project roles") — this proves it's
    // actually wired now, not just documented as a TODO.
    $proxyManifest = null;
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment record-sendrec*' => Process::result(output: 'record-sendrec   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get crd middlewares.traefik.io*' => Process::result(output: 'middlewares.traefik.io   2026-01-01T00:00:00Z'),
        '*get secret sso-app-proxy*' => Process::result(output: '', exitCode: 1),
        // applyManifest() deletes its temp file before this test can read it
        // back, so capture the content here — while it still exists, inside
        // the same synchronous Process::run() call that writes it.
        '*apply -f *larakube-sso-proxy.yaml' => function ($process) use (&$proxyManifest) {
            $file = trim(substr($process->command, strrpos($process->command, ' ') + 1));
            $proxyManifest = file_get_contents($file);

            return Process::result(output: 'applied');
        },
        '*apply -f *' => Process::result(output: 'applied'),
    ]);

    Http::fake([
        '*apps/_search' => Http::response(['result' => []]),
        '*projects/_search' => Http::response(['result' => []]),
        '*/management/v1/projects' => Http::response(['id' => 'proj-1']),
        '*/apps/oidc' => Http::response(['appId' => 'app-1', 'clientId' => 'proxy-cid', 'clientSecret' => 'proxy-csecret']),
        // record's own RBAC gating, same mechanism as the native-OIDC path.
        '*/management/v1/projects/proj-1' => Http::response(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        '*/management/v1/projects/proj-1/roles/_search' => Http::response(['result' => []]),
        '*/management/v1/projects/proj-1/roles' => Http::response([]),
        '*/management/v1/actions/_search' => Http::response(['result' => []]),
        '*/management/v1/actions' => Http::response(['id' => 'action-1']),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
        '*/management/v1/flows/2/trigger/*' => Http::response([]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'record', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('is gated behind Zitadel SSO')
        ->expectsOutputToContain('record-user')
        ->expectsOutputToContain('Nobody, including you, can reach');

    expect($proxyManifest)->not->toBeNull()
        ->and($proxyManifest)->toContain('--oidc-groups-claim=larakube_roles')
        ->and($proxyManifest)->toContain('--allowed-group=record-user');
});

test('sso:wire aborts before deploying the ForwardAuth proxy if role-gating setup fails', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment record-sendrec*' => Process::result(output: 'record-sendrec   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get crd middlewares.traefik.io*' => Process::result(output: 'middlewares.traefik.io   2026-01-01T00:00:00Z'),
        '*apply -f *' => Process::result(output: 'applied'),
    ]);

    Http::fake([
        '*projects/_search' => Http::response(['result' => []]),
        '*/management/v1/projects' => Http::response(['id' => 'proj-1']),
        '*/management/v1/projects/proj-1' => Http::response(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        '*/management/v1/actions/_search' => Http::response(['code' => 3, 'message' => 'proto: syntax error'], 400),
        '*/management/v1/actions' => Http::response(['code' => 6, 'message' => 'Errors.Action.AlreadyExists'], 409),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'record', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('Could not set up role-gated access');

    // Never gets as far as registering the shared proxy's own OIDC app.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/apps/oidc'));
});

test('sso:wire gates Outline behind Zitadel roles — the actual tool from the live 2026-08-20 incident', function (): void {
    // A partner org's ORG_OWNER (created by sso:org, a real and legitimate
    // Zitadel identity) could read internal Outline docs, because Outline
    // had no rbacRoles() and every SSO-wired tool without one admits any
    // authenticated Zitadel user regardless of org. This is the direct
    // regression guard for that incident, not just a generic RBAC test.
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment notes-outline*' => Process::result(output: 'notes-outline   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-notes*' => Process::result(output: ''),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*set env deployment/notes-outline*' => Process::result(output: 'deployment.apps/notes-outline env updated'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/notes-outline restarted'),
    ]);

    Http::fake([
        '*apps/_search' => Http::response(['result' => []]),
        '*projects/_search' => Http::response(['result' => []]),
        '*/management/v1/projects' => Http::response(['id' => 'proj-1']),
        '*/apps/oidc' => Http::response(['appId' => 'app-1', 'clientId' => 'cid-1', 'clientSecret' => 'csecret-1']),
        // Deliberately reports both flags off, unlike the other RBAC tests
        // in this file — so the PUT that actually turns them on gets
        // exercised and asserted below, instead of short-circuiting.
        '*/management/v1/projects/proj-1' => Http::sequence()
            ->push(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => false, 'projectRoleCheck' => false]])
            ->whenEmpty(Http::response([])),
        '*/management/v1/projects/proj-1/roles/_search' => Http::response(['result' => []]),
        '*/management/v1/projects/proj-1/roles' => Http::response([]),
        '*/management/v1/actions/_search' => Http::response(['result' => []]),
        '*/management/v1/actions' => Http::response(['id' => 'action-1']),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
        '*/management/v1/flows/2/trigger/*' => Http::response([]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'notes', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('wired to Zitadel SSO');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/apps/oidc')
        && $request['redirectUris'][0] === 'https://notes.'.GlobalConfigData::load()->getLocalTld().'/auth/oidc.callback');

    // The project role check must actually get turned on — this is what
    // would have stopped the incident: a zero-role login denied at the
    // Zitadel layer, before Outline's own app is ever reached.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/management/v1/projects/proj-1')
        && $request->method() === 'PUT'
        && ($request['projectRoleCheck'] ?? null) === true
        && ($request['projectRoleAssertion'] ?? null) === true);
});

test('sso:wire reuses an already-registered OIDC client', function (): void {
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

test('sso:wire writes three bound_claims-gated roles to OpenBao, not one unconditional-admin role', function (): void {
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

    // Regression guard (live 2026-08-12): tool:list marks OIDC tools as
    // wired by probing for the {tool}-oidc Secret. OpenBao's config lives in
    // its own storage (`bao auth enable oidc` above), so this CLI path is
    // what must record the openbao-oidc marker itself — without it, tool:list
    // reports a working SSO login as unwired.
    Process::assertRan(fn ($process) => str_contains($process->command, 'create secret generic openbao-oidc -n larakube-secrets')
        && str_contains($process->command, '--from-literal=client-id='));
});

test('sso:wire refuses webmail — Bulwark SSO is disabled (see docs/decisions/0001)', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment webmail-bulwark*' => Process::result(output: 'webmail-bulwark   1/1   1   1   10d'),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'webmail', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain("can't be wired to SSO");
});

test('sso:wire registers a new OIDC client and wires it to Kutt (link)', function (): void {
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
        // Kutt is RBAC-gated (ClusterTool::requiresRbacGating, added
        // 2026-08-20 — a partner org's SSO login could otherwise reach ANY
        // login-only-wired tool) — sso:wire also ensures the LaraKube RBAC
        // project's role-assertion, the kutt-user role, and the org-wide
        // claim-flattening Action.
        '*/management/v1/projects/proj-1' => Http::response(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        '*/management/v1/projects/proj-1/roles/_search' => Http::response(['result' => []]),
        '*/management/v1/projects/proj-1/roles' => Http::response([]),
        '*/management/v1/actions/_search' => Http::response(['result' => []]),
        '*/management/v1/actions' => Http::response(['id' => 'action-1']),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
        '*/management/v1/flows/2/trigger/*' => Http::response([]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'link', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Registering Link Management (Kutt) as an OIDC client in Zitadel')
        ->expectsOutputToContain('Link Management (Kutt) is wired to Zitadel SSO');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/apps/oidc')
        && $request['redirectUris'][0] === 'https://link.'.GlobalConfigData::load()->getLocalTld().'/login/oidc');

    // Wiring must still patch the deployment with the link-oidc secret and
    // flip OIDC_ENABLED on. Per ADR 0018, OIDC_ENABLED reaches the
    // Deployment declaratively (in the link-oidc Secret, pulled in via
    // --from=secret), never as a literal `set env KEY=value` override.
    Process::assertRan(fn ($process) => str_contains($process->command, 'create secret generic link-oidc')
        && str_contains($process->command, 'OIDC_ENABLED'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'set env deployment/link-kutt')
        && str_contains($process->command, '--from=secret/link-oidc'));

    // Link registers under its OWN project, not the shared 'LaraKube RBAC'
    // bucket Monitor is also on — the two must be DIFFERENT projects, or a
    // grant for one would keep authenticating into the other. Unlike
    // Monitor (single-instance, always unsuffixed), Link genuinely
    // supportsMultipleInstances() — resolveInstanceForDomain() derives a
    // real slug from its host the moment nothing's registered yet (the
    // exact same mechanism that gave Outline its 'notes-luchtech-dev' name
    // on its very first wire), so its project name carries that suffix
    // from day one too, not just once a literal second instance exists.
    $expectedSlug = 'link-'.GlobalConfigData::load()->getLocalTld();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/management/v1/projects')
        && $request->method() === 'POST'
        && $request['name'] === "link-kutt-{$expectedSlug}");
});

test('sso:wire registers a new OIDC client and wires it to Directus (data)', function (): void {
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

test('sso:wire registers a new OIDC client and wires it to PocketBase (data)', function (): void {
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

    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
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
        $temporaryDirectory->delete();
    }
});

test('sso:wire --remove deregisters the app and unsets the tool\'s env vars', function (): void {
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

test('sso:wire resolves the main DATA instance\'s own engine, not contaminated by a second instance\'s', function (): void {
    // Regression test: the OLD resolveToolEngine() queried ALL data
    // Deployments in the namespace by label selector with no instance
    // scoping, so a second PocketBase instance's Deployment name containing
    // "pocketbase" would make sso:wire wrongly conclude the MAIN
    // (Directus) instance was PocketBase too. The new instance-scoped
    // resolution checks data-directus (main) specifically, never confused
    // by a second data-pocketbase-blog-example-com Deployment coexisting.
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment data-directus*' => Process::result(output: 'data-directus   1/1   1   1   10d'),
        '*get deployment data-pocketbase-blog-example-com*' => Process::result(output: 'data-pocketbase-blog-example-com   1/1   1   1   1d'),
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
        ->expectsOutputToContain('is wired to Zitadel SSO');

    // Directus's schema, not PocketBase's — proves the main instance's own
    // engine was resolved, not the OTHER instance's.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/apps/oidc')
        && $request['redirectUris'][0] === 'https://data.'.GlobalConfigData::load()->getLocalTld().'/auth/login/zitadel/callback');
    Process::assertRan(fn ($process) => str_contains($process->command, 'set env deployment/data-directus'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'set env deployment/data-pocketbase'));
});

test('sso:wire also patches Penpot\'s frontend deployment with the same OIDC secret (also_patch)', function (): void {
    // Regression test for the ClusterTool component refactor: DESIGN's
    // oidcEnv() used to carry a one-off 'frontend_deployment' key that only
    // this tool had; it's now the general 'also_patch' list derived from
    // components() with sharesPrimarySecret: true. This proves the
    // frontend deployment still gets `set env --from=secret` + a rollout
    // restart, exactly like the old special case did.
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment design-penpot-backend*' => Process::result(output: 'design-penpot-backend   1/1   1   1   10d'),
        '*get deployment design-penpot-frontend*' => Process::result(output: 'design-penpot-frontend   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-design*' => Process::result(output: ''),
        '*get secret design-oidc*' => Process::result(output: ''),
        '*get secret design-smtp*' => Process::result(output: ''),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*set env deployment/design-penpot-backend*' => Process::result(output: 'deployment.apps/design-penpot-backend env updated'),
        '*set env deployment/design-penpot-frontend*' => Process::result(output: 'deployment.apps/design-penpot-frontend env updated'),
        '*rollout restart*' => Process::result(output: 'restarted'),
    ]);

    Http::fake([
        '*apps/_search' => Http::response(['result' => []]),
        '*projects/_search' => Http::response(['result' => []]),
        '*/management/v1/projects' => Http::response(['id' => 'proj-1']),
        '*/apps/oidc' => Http::response(['appId' => 'app-design', 'clientId' => 'cid-design', 'clientSecret' => 'csecret-design']),
        // Design (Penpot) is RBAC-gated (added 2026-08-21 — confirmed live
        // that a partner-org identity could reach it via plain Zitadel SSO,
        // same exposure Outline had).
        '*/management/v1/projects/proj-1' => Http::response(['project' => ['id' => 'proj-1', 'name' => 'design-penpot', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        '*/management/v1/projects/proj-1/roles/_search' => Http::response(['result' => []]),
        '*/management/v1/projects/proj-1/roles' => Http::response([]),
        '*/management/v1/actions/_search' => Http::response(['result' => []]),
        '*/management/v1/actions' => Http::response(['id' => 'action-1']),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
        '*/management/v1/flows/2/trigger/*' => Http::response([]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'design', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Design & Prototyping (Penpot) is wired to Zitadel SSO');

    Process::assertRan(fn ($process) => str_contains($process->command, 'set env deployment/design-penpot-backend')
        && str_contains($process->command, '--from=secret/design-oidc'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'set env deployment/design-penpot-frontend')
        && str_contains($process->command, '--from=secret/design-oidc'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/design-penpot-frontend'));
});

test('sso:wire updates a legacy "Login with SSO" Forgejo source in place (rename to the canonical `zitadel` name)', function (): void {
    // Live failure 2026-08-12: Forgejo refused `forgejo admin auth add-oauth`
    // with "login source already exists [name: Login with SSO]" because an
    // earlier broken wiring created the source under the display label while
    // the dedup matcher only recognized the canonical `zitadel` name — and
    // the Zitadel redirect URI (`/user/oauth2/zitadel/callback`) only agrees
    // with the source named `zitadel` anyway.
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment forgejo*' => Process::result(output: 'forgejo   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-git*' => Process::result(output: ''),
        '*create secret generic*' => Process::result(output: 'secret created'),
        // Forgejo's `admin auth list` is a tab-separated table (ID, Name,
        // Type, Enabled) — the legacy source holds the display label.
        '*admin auth list*' => Process::result(output: "ID\tName\tType\tEnabled\n".'1'."\t"."Login with SSO\t".'OpenID Connect'."\t".'true'),
        '*admin auth update-oauth*' => Process::result(output: 'source updated'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/forgejo restarted'),
    ]);

    Http::fake([
        '*apps/_search' => Http::response(['result' => []]),
        '*projects/_search' => Http::response(['result' => []]),
        '*/management/v1/projects' => Http::response(['id' => 'proj-1']),
        '*/apps/oidc' => Http::response(['appId' => 'app-git', 'clientId' => 'cid-git', 'clientSecret' => 'csecret-git']),
        // Git is RBAC-gated (added 2026-08-20 at the user's explicit
        // request — a git forge holding real source/CI credentials must
        // not be reachable by every org member, e.g. a future partner).
        '*/management/v1/projects/proj-1' => Http::response(['project' => ['id' => 'proj-1', 'name' => 'forgejo', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        '*/management/v1/projects/proj-1/roles/_search' => Http::response(['result' => []]),
        '*/management/v1/projects/proj-1/roles' => Http::response([]),
        '*/management/v1/actions/_search' => Http::response(['result' => []]),
        '*/management/v1/actions' => Http::response(['id' => 'action-1']),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
        '*/management/v1/flows/2/trigger/*' => Http::response([]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'git', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('is wired to Zitadel SSO');

    // update-oauth runs in place, renames the legacy source to the canonical
    // `zitadel` name, and add-oauth never runs — the "already exists" 500 is
    // the symptom this regression guards against.
    Process::assertRan(fn ($process) => str_contains($process->command, 'admin auth update-oauth --id 1')
        && str_contains($process->command, "--name 'zitadel'"));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'admin auth add-oauth'));

    // Confirmed live 2026-08-21: Forgejo caches login sources in memory and
    // kept authorizing against the OLD client-id for a real, unknown
    // stretch of time after update-oauth reported success — a restart is
    // required to make the new client-id take effect immediately.
    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/forgejo'));
});

test('sso:wire registers the Forgejo login source under the canonical `zitadel` name', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment forgejo*' => Process::result(output: 'forgejo   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-git*' => Process::result(output: ''),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*admin auth list*' => Process::result(output: "ID\tName\tType\tEnabled\n"),
        '*admin auth add-oauth*' => Process::result(output: 'source created'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/forgejo restarted'),
    ]);

    Http::fake([
        '*apps/_search' => Http::response(['result' => []]),
        '*projects/_search' => Http::response(['result' => []]),
        '*/management/v1/projects' => Http::response(['id' => 'proj-1']),
        '*/apps/oidc' => Http::response(['appId' => 'app-git', 'clientId' => 'cid-git', 'clientSecret' => 'csecret-git']),
        '*/management/v1/projects/proj-1' => Http::response(['project' => ['id' => 'proj-1', 'name' => 'forgejo', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        '*/management/v1/projects/proj-1/roles/_search' => Http::response(['result' => []]),
        '*/management/v1/projects/proj-1/roles' => Http::response([]),
        '*/management/v1/actions/_search' => Http::response(['result' => []]),
        '*/management/v1/actions' => Http::response(['id' => 'action-1']),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
        '*/management/v1/flows/2/trigger/*' => Http::response([]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'git', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('is wired to Zitadel SSO');

    // The source NAME becomes the OAuth2 callback path in Forgejo, so it must
    // be `zitadel` to agree with the redirect URI registered in Zitadel.
    Process::assertRan(fn ($process) => str_contains($process->command, 'admin auth add-oauth')
        && str_contains($process->command, "--name 'zitadel'"));

    // Regression guard: tool:list marks OIDC tools as wired by probing for the
    // `{tool}-oidc` Secret, so this CLI-OIDC path must write `forgejo-oidc`
    // like every env-var-wired tool's applyToolEnv() does — otherwise a
    // freshly-wired Forgejo shows X on tool:list forever.
    Process::assertRan(fn ($process) => str_contains($process->command, 'create secret generic forgejo-oidc -n larakube-shared')
        && str_contains($process->command, '--from-literal=client-id=')
        && str_contains($process->command, 'cid-git'));
});
