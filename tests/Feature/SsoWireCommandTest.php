<?php

use App\Commands\Sso\SsoWireCommand;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Http\Integrations\Netbird\Requests\CreateIdentityProviderRequest;
use App\Http\Integrations\Netbird\Requests\ListAccountsRequest;
use App\Http\Integrations\Netbird\Requests\ListIdentityProvidersRequest;
use App\Http\Integrations\Netbird\Requests\UpdateIdentityProviderRequest;
use App\Http\Integrations\OpenBao\Requests\DynamicNoBodyRequest;
use App\Http\Integrations\OpenBao\Requests\DynamicRequest;
use App\Http\Integrations\Zitadel\Requests\CreateActionRequest;
use App\Http\Integrations\Zitadel\Requests\CreateOidcAppRequest;
use App\Http\Integrations\Zitadel\Requests\CreateProjectRequest;
use App\Http\Integrations\Zitadel\Requests\CreateProjectRoleRequest;
use App\Http\Integrations\Zitadel\Requests\CreateUserGrantRequest;
use App\Http\Integrations\Zitadel\Requests\DeleteProjectAppRequest;
use App\Http\Integrations\Zitadel\Requests\GetFlowRequest;
use App\Http\Integrations\Zitadel\Requests\GetProjectAppRequest;
use App\Http\Integrations\Zitadel\Requests\GetProjectRequest;
use App\Http\Integrations\Zitadel\Requests\SearchActionsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchProjectAppsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchProjectRolesRequest;
use App\Http\Integrations\Zitadel\Requests\SearchProjectsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchUserGrantsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchUsersRequest;
use App\Http\Integrations\Zitadel\Requests\SetFlowTriggerActionsRequest;
use App\Http\Integrations\Zitadel\Requests\UpdateActionRequest;
use App\Http\Integrations\Zitadel\Requests\UpdateProjectRequest;
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

        Saloon::fake([
            SearchActionsRequest::class => MockResponse::make(['result' => []]),
            CreateActionRequest::class => MockResponse::make(['id' => 'action-1']),
            GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
            SetFlowTriggerActionsRequest::class => MockResponse::make([]),
            SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
            SearchProjectsRequest::class => MockResponse::make(['result' => []]),
            CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
            CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-1', 'clientId' => 'cid-1', 'clientSecret' => 'csecret-1']),
            GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
            SearchProjectRolesRequest::class => MockResponse::make(['result' => []]),
            CreateProjectRoleRequest::class => MockResponse::make([]),
        ]);

        $this->artisan('sso:wire', ['environment' => 'production', '--tool' => 'dashboard', '--no-interaction' => true])
            ->assertExitCode(0)
            ->doesntExpectOutputToContain('No host is configured')
            ->expectsOutputToContain('wired to Zitadel SSO');

        Saloon::assertSent(fn ($request) => $request instanceof CreateOidcAppRequest
            && str_contains($request->body()->get('redirectUris')[0] ?? '', 'dashboard.luchtech.dev'));
    } finally {
        chdir($cwd);
        $temporaryDirectory->delete();
    }
});

test('sso:wire registers a new OIDC client and wires it to Grafana', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment*grafana*' => Process::result(output: 'monitor-grafana-grafana-dev-test   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-monitor*' => Process::result(output: ''),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*set env deployment/*grafana*' => Process::result(output: 'deployment.apps/monitor-grafana env updated'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/monitor-grafana restarted'),
    ]);

    Saloon::fake([
        SearchActionsRequest::class => MockResponse::make(['result' => []]),
        CreateActionRequest::class => MockResponse::make(['id' => 'action-1']),
        GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
        SetFlowTriggerActionsRequest::class => MockResponse::make([]),
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-1', 'clientId' => 'cid-1', 'clientSecret' => 'csecret-1']),
        // Grafana is RBAC-gated (ClusterTool::requiresRbacGating) — sso:wire
        // also ensures the LaraKube RBAC project's role-assertion, the
        // grafana-user role, and the org-wide claim-flattening Action.
        GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        SearchProjectRolesRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRoleRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'monitor', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Registering Monitoring Stack (Grafana + Loki + Prometheus) as an OIDC client in Zitadel')
        ->expectsOutputToContain('wired to Zitadel SSO');

    Saloon::assertSent(fn ($request) => $request instanceof CreateOidcAppRequest
        && $request->body()->get('redirectUris')[0] === 'https://grafana.'.GlobalConfigData::load()->getLocalTld().'/login/generic_oauth');

    // The actual point of the 2026-08-20 per-tool-project change: Monitor
    // registers under its OWN project, not the old shared 'LaraKube RBAC'
    // bucket every RBAC-gated tool used to share (which meant a grant for
    // ANY one of them authenticated into ALL of them — projectRoleCheck is
    // project-wide in Zitadel, not per-role). The project name is the exact
    // live Deployment name (2026-08-20, replacing the earlier "LaraKube
    // RBAC: {brand}" scheme) — no separate naming convention to keep in sync.
    Saloon::assertSent(fn ($request) => $request instanceof CreateProjectRequest
        && str_starts_with($request->body()->get('name'), 'monitor-grafana'));
});

test('sso:wire --sso-only writes sso_only_vars into the Secret declaratively, never as a literal env override', function (): void {
    // ADR 0018: sso_only_vars merged into $staticVars must land in the
    // Secret (reached via --from=secret) — a literal `set env KEY=value`
    // pass would desync kubectl apply's bookkeeping for the next
    // monitor:init re-apply, exactly the bug this test guards against.
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment*grafana*' => Process::result(output: 'monitor-grafana-grafana-dev-test   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-monitor*' => Process::result(output: ''),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*set env deployment/*grafana*' => Process::result(output: 'deployment.apps/monitor-grafana env updated'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/monitor-grafana restarted'),
    ]);

    Saloon::fake([
        SearchActionsRequest::class => MockResponse::make(['result' => []]),
        CreateActionRequest::class => MockResponse::make(['id' => 'action-1']),
        GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
        SetFlowTriggerActionsRequest::class => MockResponse::make([]),
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-1', 'clientId' => 'cid-1', 'clientSecret' => 'csecret-1']),
        GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        SearchProjectRolesRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRoleRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'monitor', '--sso-only' => true, '--no-interaction' => true])
        ->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'create secret generic grafana-oidc')
        && str_contains($process->command, "GF_AUTH_DISABLE_LOGIN_FORM='true'")
        && str_contains($process->command, "GF_USERS_ALLOW_SIGN_UP='false'"));

    // No literal `set env deployment/grafana GF_AUTH_DISABLE_LOGIN_FORM=...`
    // override — only --from=secret (declarative) and the harmless KEY-
    // unset shape are allowed to touch the Deployment directly.
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'set env deployment/')
        && str_contains($process->command, 'GF_AUTH_DISABLE_LOGIN_FORM='));
});

test('sso:wire without --sso-only unsets a previously-written sso_only_var instead of leaving it stuck on', function (): void {
    // The one legitimate remaining imperative Deployment touch (ADR 0018
    // point 4): there's no declarative way to remove an env var a PAST
    // --sso-only run may have written, so toggling back off still needs
    // `kubectl set env deployment/X KEY-`.
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment*grafana*' => Process::result(output: 'monitor-grafana-grafana-dev-test   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-monitor*' => Process::result(output: ''),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*set env deployment/*grafana*' => Process::result(output: 'deployment.apps/monitor-grafana env updated'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/monitor-grafana restarted'),
    ]);

    Saloon::fake([
        SearchActionsRequest::class => MockResponse::make(['result' => []]),
        CreateActionRequest::class => MockResponse::make(['id' => 'action-1']),
        GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
        SetFlowTriggerActionsRequest::class => MockResponse::make([]),
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-1', 'clientId' => 'cid-1', 'clientSecret' => 'csecret-1']),
        GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        SearchProjectRolesRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRoleRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'monitor', '--no-interaction' => true])
        ->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'set env deployment/')
        && str_contains($process->command, 'GF_AUTH_DISABLE_LOGIN_FORM-')
        && str_contains($process->command, 'GF_USERS_ALLOW_SIGN_UP-'));

    // The unset command must be a pure removal — never carry a value for
    // the same key alongside the `-` suffix.
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'set env deployment/')
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
    Saloon::fake([
        SearchActionsRequest::class => MockResponse::make(['result' => []]),
        CreateActionRequest::class => MockResponse::make(['id' => 'action-1']),
        GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
        SetFlowTriggerActionsRequest::class => MockResponse::make([]),
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-drive', 'clientId' => 'cid-drive']),
        // Already asserted (both flags true) — this test isn't about the
        // projectRoleAssertion/projectRoleCheck state-transition dance,
        // that has its own dedicated coverage below.
        GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'drive-ocis', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        SearchProjectRolesRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRoleRequest::class => MockResponse::make([]),
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
    Saloon::assertSent(fn ($request) => $request instanceof CreateOidcAppRequest
        && $request->body()->get('authMethodType') === 'OIDC_AUTH_METHOD_TYPE_NONE'
        && $request->body()->get('redirectUris') === [
            "https://drive.{$tld}/oidc-callback.html",
            "https://drive.{$tld}/oidc-silent-redirect.html",
        ]
        && ($request->body()->get('postLogoutRedirectUris') ?? null) === ["https://drive.{$tld}/"]);

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

    Saloon::fake([
        // The ocisRoles Action must be ensured (and found already attached),
        // and the ocisAdmin role must already exist on the tool's own
        // project — Drive is rbacRoles()+ssoAdminRoles() together
        // (2026-08-20), so flattenLaraKubeRoles must also be ensured
        // (falls through to a fresh create, not found in this fake).
        SearchActionsRequest::class => MockResponse::make(['result' => [['id' => 'action-ocis', 'name' => 'flattenOcisRoles', 'script' => SsoWireCommand::OCIS_ROLES_SCRIPT]]]),
        CreateActionRequest::class => MockResponse::make(['id' => 'action-larakube']),
        GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
        SetFlowTriggerActionsRequest::class => MockResponse::make([]),
        // SsoWireCommand's own staleness pre-check GET (by the CACHED app-id).
        GetProjectAppRequest::class => MockResponse::make(['app' => ['id' => 'app-stale', 'oidcConfig' => ['redirectUris' => ["https://drive.{$tld}/"]]]]),
        SearchProjectAppsRequest::class => MockResponse::make(['result' => [['id' => 'app-stale']]]),
        SearchProjectsRequest::class => MockResponse::make(['result' => [['id' => 'proj-1']]]),
        DeleteProjectAppRequest::class => MockResponse::make([]),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-drive', 'clientId' => 'cid-drive']),
        GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'drive-ocis', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        SearchProjectRolesRequest::class => function ($pendingRequest) {
            $key = $pendingRequest->getRequest()->body()->get('queries')[0]['keyQuery']['key'] ?? '';

            return MockResponse::make(['result' => [['key' => $key]]]);
        },
    ]);

    $this->artisan('sso:wire', ['--tool' => 'drive', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('wired to Zitadel SSO');

    // The stale confidential registration is deleted and replaced by a public
    // PKCE client with the real callback pages, not silently reused.
    Saloon::assertSent(fn ($request) => $request instanceof DeleteProjectAppRequest
        && $request->resolveEndpoint() === 'management/v1/projects/proj-1/apps/app-stale');
    Saloon::assertSent(fn ($request) => $request instanceof CreateOidcAppRequest
        && $request->body()->get('authMethodType') === 'OIDC_AUTH_METHOD_TYPE_NONE'
        && $request->body()->get('redirectUris') === [
            "https://drive.{$tld}/oidc-callback.html",
            "https://drive.{$tld}/oidc-silent-redirect.html",
        ]);
    Saloon::assertNotSent(fn ($request) => $request instanceof CreateOidcAppRequest
        && $request->body()->get('authMethodType') !== 'OIDC_AUTH_METHOD_TYPE_NONE');
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
    Saloon::fake([
        SearchActionsRequest::class => MockResponse::make(['result' => [['id' => 'action-ocis', 'name' => 'flattenOcisRoles', 'script' => SsoWireCommand::OCIS_ROLES_SCRIPT]]]),
        CreateActionRequest::class => MockResponse::make(['id' => 'action-larakube']),
        GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
        SetFlowTriggerActionsRequest::class => MockResponse::make([]),
        GetProjectAppRequest::class => MockResponse::make(['app' => ['id' => 'app-live', 'oidcConfig' => ['redirectUris' => [
            "https://drive.{$tld}/oidc-callback.html",
            "https://drive.{$tld}/oidc-silent-redirect.html",
        ]]]]),
        // The name-keyed search inside zitadelCreateOidcApp finds the old app
        // and deletes it, then the fresh registration is created below.
        SearchProjectAppsRequest::class => MockResponse::make(['result' => [['id' => 'app-live']]]),
        SearchProjectsRequest::class => MockResponse::make(['result' => [['id' => 'proj-1']]]),
        DeleteProjectAppRequest::class => MockResponse::make([]),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-drive', 'clientId' => 'cid-drive']),
        GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'drive-ocis', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        SearchProjectRolesRequest::class => function ($pendingRequest) {
            $key = $pendingRequest->getRequest()->body()->get('queries')[0]['keyQuery']['key'] ?? '';

            return MockResponse::make(['result' => [['key' => $key]]]);
        },
    ]);

    $this->artisan('sso:wire', ['--tool' => 'drive', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('wired to Zitadel SSO');

    // The old app is replaced (delete + re-register) and the new registration
    // carries the origin root as a post-logout redirect URI.
    Saloon::assertSent(fn ($request) => $request instanceof DeleteProjectAppRequest
        && $request->resolveEndpoint() === 'management/v1/projects/proj-1/apps/app-live');
    Saloon::assertSent(fn ($request) => $request instanceof CreateOidcAppRequest
        && ($request->body()->get('postLogoutRedirectUris') ?? null) === ["https://drive.{$tld}/"]);
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
    Saloon::fake([
        // Neither Action exists yet — both must be created.
        SearchActionsRequest::class => MockResponse::make(['result' => []]),
        CreateActionRequest::class => MockResponse::make(['id' => 'action-ocis']),
        GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
        SetFlowTriggerActionsRequest::class => MockResponse::make([]),
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-drive', 'clientId' => 'cid-drive']),
        GetProjectRequest::class => function () use (&$projectGated) {
            return MockResponse::make(['project' => [
                'id' => 'proj-1',
                'name' => 'drive-ocis',
                'projectRoleAssertion' => $projectGated,
                'projectRoleCheck' => $projectGated,
            ]]);
        },
        UpdateProjectRequest::class => function () use (&$projectGated) {
            $projectGated = true;

            return MockResponse::make(['details' => []]);
        },
        SearchProjectRolesRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRoleRequest::class => MockResponse::make([]),
        SearchUsersRequest::class => MockResponse::make(['result' => [['userId' => 'uid-1']]]),
        SearchUserGrantsRequest::class => MockResponse::make(['result' => []]),
        CreateUserGrantRequest::class => MockResponse::make([]),
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
    Saloon::assertSent(fn ($request) => $request instanceof CreateProjectRoleRequest
        && $request->body()->get('roleKey') === 'ocisUser');

    // The org-wide flattenOcisRoles Action was created with the ocisUser
    // fallback so nobody gets locked out under PROXY_ROLE_ASSIGNMENT_DRIVER=
    // oidc — and with the ocisSpaceAdmin upgrade path beside ocisAdmin
    // (admin outranks spaceadmin).
    Saloon::assertSent(fn ($request) => $request instanceof CreateActionRequest
        && str_contains($request->body()->get('script'), '"ocisUser"')
        && str_contains($request->body()->get('script'), '"ocisAdmin"')
        && str_contains($request->body()->get('script'), '"ocisSpaceAdmin"')
        && str_contains($request->body()->get('script'), 'roles[0] !== "ocisAdmin"')
        && str_contains($request->body()->get('script'), 'setClaim("ocisRoles"'));

    // Drive's own project gains BOTH flags — projectRoleCheck is the actual
    // login gate, unlike a plain ssoAdminRoles()-only tool (e.g. a future
    // one) where it must stay off so zero-role members can still log in.
    Saloon::assertSent(fn ($request) => $request instanceof UpdateProjectRequest
        && $request->body()->get('projectRoleAssertion') === true
        && $request->body()->get('projectRoleCheck') === true);

    // Both drive admin roles were granted to the --admin-email user on
    // Drive's own project.
    foreach (['ocisAdmin', 'ocisSpaceAdmin'] as $roleKey) {
        Saloon::assertSent(fn ($request) => $request instanceof CreateUserGrantRequest
            && $request->body()->get('roleKeys') === [$roleKey]);
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
    Saloon::fake([
        // Only flattenOcisRoles exists (stale script) — flattenLaraKubeRoles
        // (needed now that Drive also has rbacRoles()) doesn't, so it gets
        // freshly created via the generic CreateActionRequest.
        SearchActionsRequest::class => MockResponse::make(['result' => [['id' => 'action-ocis', 'name' => 'flattenOcisRoles', 'script' => 'function flattenOcisRoles(ctx, api) { let roles = ["ocisUser"]; }']]]),
        UpdateActionRequest::class => MockResponse::make([]),
        CreateActionRequest::class => MockResponse::make(['id' => 'action-larakube']),
        GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
        SetFlowTriggerActionsRequest::class => MockResponse::make([]),
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-drive', 'clientId' => 'cid-drive']),
        GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'drive-ocis', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        SearchProjectRolesRequest::class => function ($pendingRequest) {
            $key = $pendingRequest->getRequest()->body()->get('queries')[0]['keyQuery']['key'] ?? '';

            return MockResponse::make(['result' => [['key' => $key]]]);
        },
    ]);

    $this->artisan('sso:wire', ['--tool' => 'drive', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('wired to Zitadel SSO');

    // The stale script is updated in place with the new ocisSpaceAdmin-aware
    // emitter — never recreated, never silently left behind.
    Saloon::assertSent(fn ($request) => $request instanceof UpdateActionRequest
        && $request->resolveEndpoint() === 'management/v1/actions/action-ocis'
        && str_contains($request->body()->get('script'), '"ocisSpaceAdmin"')
        && $request->body()->get('fieldMask') === ['paths' => ['name', 'script']]);
    // flattenOcisRoles specifically is never recreated — the create that does
    // happen (flattenLaraKubeRoles, since it didn't exist yet) is for a
    // different Action and carries a different script entirely.
    Saloon::assertNotSent(fn ($request) => $request instanceof CreateActionRequest
        && str_contains($request->body()->get('script') ?? '', 'flattenOcisRoles'));
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

    Saloon::fake([
        SearchActionsRequest::class => MockResponse::make(['result' => [
            ['id' => 'action-rbac', 'name' => 'flattenLaraKubeRoles', 'script' => 'function flattenLaraKubeRoles(ctx, api) { api.v1.claims.setClaim("larakube_roles", []); }'],
        ]]),
        UpdateActionRequest::class => MockResponse::make([]),
        GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
        SetFlowTriggerActionsRequest::class => MockResponse::make([]),
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-1', 'clientId' => 'cid-1', 'clientSecret' => 'csecret-1']),
        GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        SearchProjectRolesRequest::class => function ($pendingRequest) {
            $key = $pendingRequest->getRequest()->body()->get('queries')[0]['keyQuery']['key'] ?? '';

            return MockResponse::make(['result' => [['key' => $key]]]);
        },
    ]);

    $this->artisan('sso:wire', ['--tool' => 'dashboard', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('wired to Zitadel SSO');

    Saloon::assertSent(fn ($request) => $request instanceof UpdateActionRequest
        && $request->resolveEndpoint() === 'management/v1/actions/action-rbac'
        && str_contains($request->body()->get('script'), 'setClaim("groups", roles)')
        && $request->body()->get('fieldMask') === ['paths' => ['name', 'script']]);
});

test('sso:wire turns projectRoleCheck on immediately, not just projectRoleAssertion', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment*grafana*' => Process::result(output: 'monitor-grafana-grafana-dev-test   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-monitor*' => Process::result(output: ''),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*set env deployment/*grafana*' => Process::result(output: 'deployment.apps/monitor-grafana env updated'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/monitor-grafana restarted'),
    ]);

    Saloon::fake([
        SearchActionsRequest::class => MockResponse::make(['result' => []]),
        CreateActionRequest::class => MockResponse::make(['id' => 'action-1']),
        GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
        SetFlowTriggerActionsRequest::class => MockResponse::make([]),
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-1', 'clientId' => 'cid-1', 'clientSecret' => 'csecret-1']),
        // A fresh/never-configured project — neither flag set yet. This is
        // the actual "first tool wired on this cluster" scenario, distinct
        // from the other tests' already-both-true steady state.
        GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC']]),
        UpdateProjectRequest::class => MockResponse::make(['details' => []]),
        SearchProjectRolesRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRoleRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'monitor', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Nobody, including you, can SSO into Monitoring Stack (Grafana + Loki + Prometheus) until then');

    Saloon::assertSent(fn ($request) => $request instanceof UpdateProjectRequest
        && $request->body()->get('projectRoleAssertion') === true
        && $request->body()->get('projectRoleCheck') === true);
});

test('sso:wire aborts before registering an OIDC client if role-gating setup fails', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment*grafana*' => Process::result(output: 'monitor-grafana-grafana-dev-test   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    // Simulates the real bug found live 2026-07-30: the claim-flattening
    // Action's search 400s. ensureRbacGating() must now surface this as a
    // hard failure instead of silently proceeding to register an OIDC app
    // and wire bound_claims/role_attribute_path against infrastructure that
    // was never confirmed to exist.
    Saloon::fake([
        SearchActionsRequest::class => MockResponse::make(['code' => 3, 'message' => 'proto: syntax error'], 400),
        CreateActionRequest::class => MockResponse::make(['code' => 6, 'message' => 'Errors.Action.AlreadyExists'], 409),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'monitor', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('Could not set up role-gated access');

    Saloon::assertNotSent(CreateOidcAppRequest::class);
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

    Saloon::fake([
        SearchActionsRequest::class => MockResponse::make(['result' => []]),
        CreateActionRequest::class => MockResponse::make(['id' => 'action-1']),
        GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
        SetFlowTriggerActionsRequest::class => MockResponse::make([]),
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-1', 'clientId' => 'proxy-cid', 'clientSecret' => 'proxy-csecret']),
        // record's own RBAC gating, same mechanism as the native-OIDC path.
        GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        SearchProjectRolesRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRoleRequest::class => MockResponse::make([]),
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

    Saloon::fake([
        SearchActionsRequest::class => MockResponse::make(['code' => 3, 'message' => 'proto: syntax error'], 400),
        CreateActionRequest::class => MockResponse::make(['code' => 6, 'message' => 'Errors.Action.AlreadyExists'], 409),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'record', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('Could not set up role-gated access');

    // Never gets as far as registering the shared proxy's own OIDC app.
    Saloon::assertNotSent(CreateOidcAppRequest::class);
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

    Saloon::fake([
        SearchActionsRequest::class => MockResponse::make(['result' => []]),
        CreateActionRequest::class => MockResponse::make(['id' => 'action-1']),
        GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
        SetFlowTriggerActionsRequest::class => MockResponse::make([]),
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-1', 'clientId' => 'cid-1', 'clientSecret' => 'csecret-1']),
        // Deliberately reports both flags off, unlike the other RBAC tests
        // in this file — so the PUT that actually turns them on gets
        // exercised and asserted below, instead of short-circuiting.
        GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => false, 'projectRoleCheck' => false]]),
        UpdateProjectRequest::class => MockResponse::make([]),
        SearchProjectRolesRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRoleRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'notes', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('wired to Zitadel SSO');

    Saloon::assertSent(fn ($request) => $request instanceof CreateOidcAppRequest
        && $request->body()->get('redirectUris')[0] === 'https://notes.'.GlobalConfigData::load()->getLocalTld().'/auth/oidc.callback');

    // The project role check must actually get turned on — this is what
    // would have stopped the incident: a zero-role login denied at the
    // Zitadel layer, before Outline's own app is ever reached.
    Saloon::assertSent(fn ($request) => $request instanceof UpdateProjectRequest
        && ($request->body()->get('projectRoleCheck') ?? null) === true
        && ($request->body()->get('projectRoleAssertion') ?? null) === true);
});

test('sso:wire reuses an already-registered OIDC client', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment*grafana*' => Process::result(output: 'monitor-grafana-grafana-dev-test   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*sso-app-monitor*client-id*' => Process::result(output: base64_encode('cached-cid')),
        '*sso-app-monitor*client-secret*' => Process::result(output: base64_encode('cached-secret')),
        '*sso-app-monitor*app-id*' => Process::result(output: base64_encode('cached-appid')),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*set env deployment/*grafana*' => Process::result(output: 'deployment.apps/monitor-grafana env updated'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/monitor-grafana restarted'),
    ]);

    // Keyed on the APP id, not the client id. Zitadel's app endpoint 404s on a
    // client id, so faking that URL only ever agreed with the bug.
    $tld = GlobalConfigData::load()->getLocalTld();

    Saloon::fake([
        SearchActionsRequest::class => MockResponse::make(['result' => []]),
        CreateActionRequest::class => MockResponse::make(['id' => 'action-1']),
        GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
        SetFlowTriggerActionsRequest::class => MockResponse::make([]),
        // The GET returns the registered app's current redirect URIs — the wire
        // command compares them against the tool's desired set and only reuses
        // when they still match (a stale registration, like drive's old
        // confidential root-only app, must be re-registered instead).
        GetProjectAppRequest::class => MockResponse::make(['app' => ['id' => 'cached-appid', 'oidcConfig' => ['redirectUris' => ["https://grafana.{$tld}/login/generic_oauth"]]]]),
        SearchProjectsRequest::class => MockResponse::make(['result' => [['id' => 'proj-1']]]),
        // Grafana is RBAC-gated — see the equivalent fakes in the "registers
        // a new OIDC client" test above for what each endpoint is for.
        GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        SearchProjectRolesRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRoleRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'monitor', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain("Reusing Monitoring Stack (Grafana + Loki + Prometheus)'s existing Zitadel OIDC client")
        ->expectsOutputToContain('wired to Zitadel SSO');

    // Reuse means reuse: no re-registration, so the client secret is not rotated.
    Saloon::assertNotSent(CreateOidcAppRequest::class);
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

    Saloon::fake([
        SearchActionsRequest::class => MockResponse::make(['result' => []]),
        CreateActionRequest::class => MockResponse::make(['id' => 'action-1']),
        GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
        SetFlowTriggerActionsRequest::class => MockResponse::make([]),
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-1', 'clientId' => 'cid-1', 'clientSecret' => 'csecret-1']),
        // sso:wire mirrors the client secret into OpenBao KV over a port-forward.
        // Unmocked it reaches a REAL localhost port, which under --parallel
        // intermittently picked up an unrelated service and failed only sometimes
        // — ADR 0019's exact warning, on the Saloon side.
        DynamicNoBodyRequest::class => MockResponse::make(['data' => ['secret/' => ['type' => 'kv']]]),
        DynamicRequest::class => MockResponse::make(['data' => ['value' => 'ok']]),
        GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        SearchProjectRolesRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRoleRequest::class => MockResponse::make([]),
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

    Saloon::fake([
        SearchActionsRequest::class => MockResponse::make(['result' => []]),
        CreateActionRequest::class => MockResponse::make(['id' => 'action-1']),
        GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
        SetFlowTriggerActionsRequest::class => MockResponse::make([]),
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-1', 'clientId' => 'cid-1', 'clientSecret' => 'csecret-1']),
        // Kutt is RBAC-gated (ClusterTool::requiresRbacGating, added
        // 2026-08-20 — a partner org's SSO login could otherwise reach ANY
        // login-only-wired tool) — sso:wire also ensures the LaraKube RBAC
        // project's role-assertion, the kutt-user role, and the org-wide
        // claim-flattening Action.
        GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        SearchProjectRolesRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRoleRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'link', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Registering Link Management (Kutt) as an OIDC client in Zitadel')
        ->expectsOutputToContain('Link Management (Kutt) is wired to Zitadel SSO');

    Saloon::assertSent(fn ($request) => $request instanceof CreateOidcAppRequest
        && $request->body()->get('redirectUris')[0] === 'https://link.'.GlobalConfigData::load()->getLocalTld().'/login/oidc');

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
    Saloon::assertSent(fn ($request) => $request instanceof CreateProjectRequest
        && $request->body()->get('name') === "link-kutt-{$expectedSlug}");
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

    Saloon::fake([
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-data', 'clientId' => 'cid-data', 'clientSecret' => 'csecret-data']),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'data', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Registering Headless CMS & Data API (PocketBase or Directus) as an OIDC client in Zitadel')
        ->expectsOutputToContain('Headless CMS & Data API (PocketBase or Directus) is wired to Zitadel SSO');

    Saloon::assertSent(fn ($request) => $request instanceof CreateOidcAppRequest
        && $request->body()->get('redirectUris')[0] === 'https://data.'.GlobalConfigData::load()->getLocalTld().'/auth/login/zitadel/callback');

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

    Saloon::fake([
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-data', 'clientId' => 'cid-data', 'clientSecret' => 'csecret-data']),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
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

        Saloon::assertSent(fn ($request) => $request instanceof CreateOidcAppRequest
            && $request->body()->get('redirectUris')[0] === 'https://pocket.luchtech.dev/api/oauth2-callback');
    } finally {
        chdir($cwd);
        $temporaryDirectory->delete();
    }
});

test('sso:wire --remove deregisters the app and unsets the tool\'s env vars', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment*grafana*' => Process::result(output: 'monitor-grafana-grafana-dev-test   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*sso-app-monitor*project-id*' => Process::result(output: base64_encode('proj-1')),
        '*sso-app-monitor*app-id*' => Process::result(output: base64_encode('app-1')),
        '*delete secret sso-app-monitor*' => Process::result(output: 'secret deleted'),
        '*set env deployment/*grafana*' => Process::result(output: 'deployment.apps/monitor-grafana env updated'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/monitor-grafana restarted'),
    ]);

    Saloon::fake([DeleteProjectAppRequest::class => MockResponse::make([], 200)]);

    $this->artisan('sso:unwire', ['--tool' => 'monitor', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('no longer uses Zitadel SSO');

    Saloon::assertSent(fn ($request) => $request instanceof DeleteProjectAppRequest
        && $request->resolveEndpoint() === 'management/v1/projects/proj-1/apps/app-1');
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

    Saloon::fake([
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-data', 'clientId' => 'cid-data', 'clientSecret' => 'csecret-data']),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'data', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('is wired to Zitadel SSO');

    // Directus's schema, not PocketBase's — proves the main instance's own
    // engine was resolved, not the OTHER instance's.
    Saloon::assertSent(fn ($request) => $request instanceof CreateOidcAppRequest
        && $request->body()->get('redirectUris')[0] === 'https://data.'.GlobalConfigData::load()->getLocalTld().'/auth/login/zitadel/callback');
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

    Saloon::fake([
        SearchActionsRequest::class => MockResponse::make(['result' => []]),
        CreateActionRequest::class => MockResponse::make(['id' => 'action-1']),
        GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
        SetFlowTriggerActionsRequest::class => MockResponse::make([]),
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-design', 'clientId' => 'cid-design', 'clientSecret' => 'csecret-design']),
        // Design (Penpot) is RBAC-gated (added 2026-08-21 — confirmed live
        // that a partner-org identity could reach it via plain Zitadel SSO,
        // same exposure Outline had).
        GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'design-penpot', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        SearchProjectRolesRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRoleRequest::class => MockResponse::make([]),
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
        '*get deployment git-forgejo*' => Process::result(output: 'forgejo   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-git*' => Process::result(output: ''),
        '*create secret generic*' => Process::result(output: 'secret created'),
        // Forgejo's `admin auth list` is a tab-separated table (ID, Name,
        // Type, Enabled) — the legacy source holds the display label.
        '*admin auth list*' => Process::result(output: "ID\tName\tType\tEnabled\n".'1'."\t"."Login with SSO\t".'OpenID Connect'."\t".'true'),
        '*admin auth update-oauth*' => Process::result(output: 'source updated'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/forgejo restarted'),
    ]);

    Saloon::fake([
        SearchActionsRequest::class => MockResponse::make(['result' => []]),
        CreateActionRequest::class => MockResponse::make(['id' => 'action-1']),
        GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
        SetFlowTriggerActionsRequest::class => MockResponse::make([]),
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-git', 'clientId' => 'cid-git', 'clientSecret' => 'csecret-git']),
        // Git is RBAC-gated (added 2026-08-20 at the user's explicit
        // request — a git forge holding real source/CI credentials must
        // not be reachable by every org member, e.g. a future partner).
        GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'forgejo', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        SearchProjectRolesRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRoleRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'git', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('is wired to Zitadel SSO');

    // update-oauth runs in place, renames the legacy source to the canonical
    // `zitadel` name, and add-oauth never runs — the "already exists" 500 is
    // the symptom this regression guards against.
    Process::assertRan(fn ($process) => str_contains($process->command, 'admin auth update-oauth --id 1')
        && str_contains($process->command, "--name 'zitadel'")
        // Live failure 2026-08-24: with no explicit scopes the source only
        // requested implicit `openid`, so Zitadel's userinfo returned just
        // `sub` (no email) and Forgejo could never auto-link — every user
        // landed on the "Link to an existing account" screen.
        && str_contains($process->command, '--scopes profile --scopes email'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'admin auth add-oauth'));

    // Confirmed live 2026-08-21: Forgejo caches login sources in memory and
    // kept authorizing against the OLD client-id for a real, unknown
    // stretch of time after update-oauth reported success — a restart is
    // required to make the new client-id take effect immediately.
    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/git-forgejo'));
});

test('sso:wire registers the Forgejo login source under the canonical `zitadel` name', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment git-forgejo*' => Process::result(output: 'forgejo   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-git*' => Process::result(output: ''),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*admin auth list*' => Process::result(output: "ID\tName\tType\tEnabled\n"),
        '*admin auth add-oauth*' => Process::result(output: 'source created'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/forgejo restarted'),
    ]);

    Saloon::fake([
        SearchActionsRequest::class => MockResponse::make(['result' => []]),
        CreateActionRequest::class => MockResponse::make(['id' => 'action-1']),
        GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
        SetFlowTriggerActionsRequest::class => MockResponse::make([]),
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-git', 'clientId' => 'cid-git', 'clientSecret' => 'csecret-git']),
        GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'forgejo', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        SearchProjectRolesRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRoleRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'git', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('is wired to Zitadel SSO');

    // The source NAME becomes the OAuth2 callback path in Forgejo, so it must
    // be `zitadel` to agree with the redirect URI registered in Zitadel.
    // Scopes are required so the userinfo response carries an email claim —
    // Forgejo's auto-link path needs one (live failure 2026-08-24).
    Process::assertRan(fn ($process) => str_contains($process->command, 'admin auth add-oauth')
        && str_contains($process->command, "--name 'zitadel'")
        && str_contains($process->command, '--scopes profile --scopes email'));

    // Regression guard: tool:list marks OIDC tools as wired by probing for the
    // `{tool}-oidc` Secret, so this CLI-OIDC path must write `forgejo-oidc`
    // like every env-var-wired tool's applyToolEnv() does — otherwise a
    // freshly-wired Forgejo shows X on tool:list forever.
    Process::assertRan(fn ($process) => str_contains($process->command, 'create secret generic forgejo-oidc -n larakube-shared')
        && str_contains($process->command, '--from-literal=client-id=')
        && str_contains($process->command, 'cid-git'));
});

test('sso:wire registers NetBird as a Zitadel identity provider via its own REST API', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment vpn-management*' => Process::result(output: 'vpn-management   1/1   1   1   10d'),
        '*rollout restart deployment/vpn-dashboard*' => Process::result(output: 'restarted'),
        '*patch secret sso-app-vpn*' => Process::result(output: 'patched'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-vpn*' => Process::result(output: ''),
        // vpnName() resolves the instance suffix from the tool registry.
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*vpn-management-secrets*data.pat*' => Process::result(output: base64_encode('netbird-pat')),
        '*create secret generic*' => Process::result(output: 'secret created'),
    ]);

    Saloon::fake([
        SearchActionsRequest::class => MockResponse::make(['result' => []]),
        CreateActionRequest::class => MockResponse::make(['id' => 'action-1']),
        GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
        SetFlowTriggerActionsRequest::class => MockResponse::make([]),
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-vpn', 'clientId' => 'cid-vpn', 'clientSecret' => 'csecret-vpn']),
        GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'vpn-management', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        SearchProjectRolesRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRoleRequest::class => MockResponse::make([]),
        // Empty list — first-time registration, so wireNetbirdOidc() must
        // POST a new provider, not PUT an update.
        ListIdentityProvidersRequest::class => MockResponse::make([], 200),
        // wireNetbirdOidc() now inspects the account afterwards to decide whether
        // the domain-less bootstrap account needs retiring. One with a domain is
        // already correct, so this leaves it alone.
        ListAccountsRequest::class => MockResponse::make([['id' => 'acc-1', 'domain' => 'example.com']], 200),
        CreateIdentityProviderRequest::class => MockResponse::make(['id' => 'idp-1', 'type' => 'zitadel', 'name' => 'Zitadel'], 200),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'vpn', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('is wired to Zitadel SSO');

    Saloon::assertSent(function ($request) {
        // Untyped on purpose: the dashboard's public client is registered as a
        // second Zitadel app, so more request types now flow through this
        // closure and a typed parameter would TypeError before matching.
        if (! $request instanceof CreateIdentityProviderRequest) {
            return false;
        }

        $body = $request->body()->all();

        return $body['type'] === 'zitadel'
            && $body['client_id'] === 'cid-vpn'
            && $body['client_secret'] === 'csecret-vpn'
            && $body['issuer'] === 'https://sso.'.GlobalConfigData::load()->getLocalTld();
    });

    // Regression guard, same reasoning as OpenBao/Forgejo above: tool:list
    // marks an OIDC tool as SSO-wired by probing for the `{tool}-oidc`
    // Secret — NetBird's wiring lives in its own storage (the API call
    // above), so wireNetbirdOidc() must write the marker secret itself.
    Process::assertRan(fn ($process) => str_contains($process->command, 'create secret generic vpn-management-oidc -n larakube-vpn')
        && str_contains($process->command, '--from-literal=client-id=')
        && str_contains($process->command, 'cid-vpn'));

    // No auth-* keys: the dashboard logs in against the EMBEDDED IdP with its
    // own static client, and Dex federates to the Zitadel client above.
    // Writing them here is the retired standalone-IdP topology.
    Process::assertRan(fn ($process) => str_contains($process->command, 'create secret generic vpn-management-oidc')
        && ! str_contains($process->command, 'auth-authority=')
        && ! str_contains($process->command, 'auth-client-id='));

    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/vpn-dashboard'));

    // VPN grants private network access, not just a web login — gated via
    // rbacRoles(), not open to any org member.
    // Derived, not hardcoded: the project name follows deploymentName(), which
    // is instance-suffixed since VPN joined the naming convention (2026-08-29).
    $expectedProject = ClusterTool::VPN->deploymentName(
        ClusterTool::VPN->instanceSlugFromHost('vpn.'.GlobalConfigData::load()->getLocalTld()),
    );

    Saloon::assertSent(fn ($request) => $request instanceof CreateProjectRequest
        && $request->body()->get('name') === $expectedProject);
});

test('sso:wire re-wiring NetBird updates the existing identity provider via PUT, not a duplicate POST', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment vpn-management*' => Process::result(output: 'vpn-management   1/1   1   1   10d'),
        '*rollout restart deployment/vpn-dashboard*' => Process::result(output: 'restarted'),
        '*patch secret sso-app-vpn*' => Process::result(output: 'patched'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*sso-app-vpn*project-id*' => Process::result(output: base64_encode('proj-1')),
        '*sso-app-vpn*app-id*' => Process::result(output: base64_encode('app-vpn')),
        '*sso-app-vpn*client-id*' => Process::result(output: base64_encode('cid-vpn')),
        '*sso-app-vpn*client-secret*' => Process::result(output: base64_encode('csecret-vpn')),
        '*vpn-management-secrets*data.pat*' => Process::result(output: base64_encode('netbird-pat')),
        '*create secret generic*' => Process::result(output: 'secret created'),
    ]);

    Saloon::fake([
        SearchActionsRequest::class => MockResponse::make(['result' => []]),
        CreateActionRequest::class => MockResponse::make(['id' => 'action-1']),
        GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
        SetFlowTriggerActionsRequest::class => MockResponse::make([]),
        // zitadelEnsureProject() always resolves the project id first, reuse
        // or not.
        SearchProjectsRequest::class => MockResponse::make(['result' => [['id' => 'proj-1', 'name' => 'vpn-management']]]),
        // Registered app still exists, redirect URIs still match — reused,
        // not re-registered.
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        GetProjectAppRequest::class => MockResponse::make(['app' => [
            'oidcConfig' => [
                'redirectUris' => ['https://vpn.'.GlobalConfigData::load()->getLocalTld().'/oauth2/callback'],
                'postLogoutRedirectUris' => ['https://vpn.'.GlobalConfigData::load()->getLocalTld().'/oauth2/logout/callback'],
                'authMethodType' => 'OIDC_AUTH_METHOD_TYPE_BASIC',
            ],
        ]]),
        GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'vpn-management', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        SearchProjectRolesRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRoleRequest::class => MockResponse::make([]),
        // The 'zitadel' entry already exists from a previous wire — must PUT.
        // The dashboard's public client is a SECOND app in the same project.
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'dash-app-1', 'clientId' => 'dash-cid-1', 'clientSecret' => '']),
        ListIdentityProvidersRequest::class => MockResponse::make([
            ['id' => 'idp-1', 'type' => 'zitadel', 'name' => 'Zitadel'],
        ], 200),
        UpdateIdentityProviderRequest::class => MockResponse::make(['id' => 'idp-1', 'type' => 'zitadel', 'name' => 'Zitadel'], 200),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'vpn', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('is wired to Zitadel SSO');

    Saloon::assertSent(function (UpdateIdentityProviderRequest $request) {
        $body = $request->body()->all();

        return $body['type'] === 'zitadel' && str_contains($request->resolveEndpoint(), 'idp-1');
    });
    Saloon::assertNotSent(CreateIdentityProviderRequest::class);
});

test('plain sso:wire lists each registered instance by its host', function (): void {
    // Live DX bug 2026-08-24: the installed-filter probed oidcEnv()'s deployment
    // name without an instance, so git never appeared even while Forgejo ran as
    // git-forgejo-git-luchtech-dev — and VPN vanished the same way on 2026-08-29.
    // The name is {category}-{component}-{instance} and the instance is not known
    // until a host is, which is what this picker exists to establish; CHAT, DATA
    // and GIT each grew a bespoke probe around that. The registry already records
    // tool, instance and host, so nothing needs deriving — and one option per
    // INSTANCE makes a tool installed twice selectable at all.
    Process::fake([
        '*larakube-tools-registry*' => Process::result(output: base64_encode((string) json_encode([
            ['tool' => 'git', 'instance' => 'git-luchtech-dev', 'host' => 'git.luchtech.dev'],
        ]))),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment git-forgejo*' => Process::result(output: 'forgejo   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-git*' => Process::result(output: ''),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*admin auth list*' => Process::result(output: "ID\tName\tType\tEnabled\n"),
        '*admin auth add-oauth*' => Process::result(output: 'source created'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/forgejo restarted'),
        '*get deployment*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        SearchActionsRequest::class => MockResponse::make(['result' => []]),
        CreateActionRequest::class => MockResponse::make(['id' => 'action-1']),
        GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
        SetFlowTriggerActionsRequest::class => MockResponse::make([]),
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-git', 'clientId' => 'cid-git', 'clientSecret' => 'csecret-git']),
        GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'forgejo', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        SearchProjectRolesRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRoleRequest::class => MockResponse::make([]),
    ]);

    // One registered instance and nothing to disambiguate, so the picker takes
    // it rather than asking a question with a single answer. The host-labelled,
    // string-keyed list is exercised by the two-instance test below.
    $this->artisan('sso:wire', ['--no-interaction' => false])
        ->assertExitCode(0)
        ->expectsOutputToContain('is wired to Zitadel SSO');

    // Selected via the picker, the wiring still registers under the
    // canonical name with the email/profile scopes required for auto-link.
    Process::assertRan(fn ($process) => str_contains($process->command, 'admin auth add-oauth')
        && str_contains($process->command, "--name 'zitadel'")
        && str_contains($process->command, '--scopes profile --scopes email'));
});

test('a tool installed twice is selectable per instance', function (): void {
    // The old picker listed TOOLS, keyed by slug — so two instances of one tool
    // collapsed into a single unselectable entry and whichever host
    // targetHost() happened to return won. Listing instances is the only way to
    // express the choice at all.
    Process::fake([
        '*larakube-tools-registry*' => Process::result(output: base64_encode((string) json_encode([
            ['tool' => 'notes', 'instance' => 'notes-luchtech-dev', 'host' => 'notes.luchtech.dev'],
            ['tool' => 'notes', 'instance' => 'wiki-luchtech-dev', 'host' => 'wiki.luchtech.dev'],
        ]))),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('sso:wire', ['--no-interaction' => false])
        ->expectsChoice('Wire which tool to Zitadel SSO?', 'notes|wiki.luchtech.dev', [
            'notes|notes.luchtech.dev' => 'Team Wiki & Knowledge Base (Outline) (notes.luchtech.dev)',
            'notes|wiki.luchtech.dev' => 'Team Wiki & Knowledge Base (Outline) (wiki.luchtech.dev)',
        ]);
});

test('sso:wire says nothing is registered rather than nothing is installed', function (): void {
    // An empty registry means no tool went through its own :init here — which is
    // a different thing from "your deployments are missing", and the old message
    // named Vaultwarden and Grafana as if they were expected to exist.
    Process::fake([
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('sso:wire', ['--no-interaction' => false])
        ->assertExitCode(1)
        ->expectsOutputToContain('No OIDC-capable tools are registered');
});
