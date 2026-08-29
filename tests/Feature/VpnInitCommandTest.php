<?php

use App\Commands\Vpn\VpnInitCommand;
use App\Data\CloudData;
use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Http\Integrations\Netbird\Requests\CreateGroupRequest;
use App\Http\Integrations\Netbird\Requests\CreatePersonalAccessTokenRequest;
use App\Http\Integrations\Netbird\Requests\CreateServiceUserRequest;
use App\Http\Integrations\Netbird\Requests\CreateSetupKeyRequest;
use App\Http\Integrations\Netbird\Requests\ListGroupsRequest;
use App\Http\Integrations\Netbird\Requests\ListUsersRequest;
use App\Http\Integrations\Netbird\Requests\SetupOwnerRequest;
use App\Http\Integrations\OpenBao\Requests\DynamicNoBodyRequest;
use App\Http\Integrations\OpenBao\Requests\DynamicRequest;
use App\State;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Spatie\TemporaryDirectory\TemporaryDirectory;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function vpnInitKubectl(): string
{
    return 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';
}

/**
 * Renders the SAME management.json ensureVpnConfig() would for a steady-state
 * re-run, base64-encoded exactly as `kubectl get secret ... -o jsonpath=...`
 * would return it — an existing-install fixture that content-matches the
 * freshly-rendered template exactly, so ensureVpnConfig() correctly detects
 * "nothing changed" and skips the restart path these tests don't otherwise
 * fake.
 */
function vpnManagementConfigFixture(string $host, string $relaySecret = 'existing-relay-secret', string $encryptionKey = 'existing-encryption-key'): string
{
    return base64_encode(view('k8s.vpn.management-config', [
        'host' => $host,
        'relaySecret' => $relaySecret,
        'dataStoreEncryptionKey' => $encryptionKey,
    ])->render());
}

test('vpn:init deploys netbird vpn to larakube-vpn', function (): void {
    $kubectl = vpnInitKubectl();

    Process::fake([
        '*get namespace larakube-vpn*' => Process::result(output: ''),
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*get configmap plex-registry*' => Process::result(output: '', exitCode: 1),
        '*configmap plex-registry*' => Process::result(output: 'configmap/plex-registry configured'),
        '*get configmap plex-commons*' => Process::result(output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]])),
        '*exec*postgres*' => Process::result(output: 'CREATE ROLE'),
        '*get secret vpn-management-store*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-store*' => Process::result(output: 'secret/vpn-management-store created'),
        '*logs deploy/vpn-management*' => Process::result(output: 'single account mode enabled, accounts number 1'),
        '*create namespace larakube-vpn*' => Process::result(output: 'namespace/larakube-vpn created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout status deploy/vpn-management*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-signal*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-relay*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-dashboard*' => Process::result(output: 'rollout success'),
        '*get secret vpn-management-oidc*' => Process::result(output: '', exitCode: 1),
        '*rollout status deployment/vpn-client*' => Process::result(output: 'rollout success'),
        // Already bootstrapped — vpn:init should skip auth/config setup entirely, no Http calls made.
        '*get secret vpn-management-secrets*' => Process::result(output: 'vpn-management-secrets', exitCode: 0),
        '*get secret vpn-management-config*' => Process::result(output: vpnManagementConfigFixture('vpn.'.GlobalConfigData::load()->getLocalTld()), exitCode: 0),
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*create namespace larakube-shared*' => Process::result(output: 'created'),
    ]);

    $this->artisan('vpn:init local')
        ->assertExitCode(0)
        ->expectsOutputToContain('Ensuring namespace larakube-vpn...')
        ->expectsOutputToContain('Applying NetBird VPN manifests...')
        ->expectsOutputToContain('Waiting for NetBird Management...')
        ->expectsOutputToContain('Waiting for NetBird Signal...')
        ->expectsOutputToContain('Waiting for NetBird Relay...')
        ->expectsOutputToContain('Deploying NetBird Client...')
        ->expectsOutputToContain('NetBird VPN stack is live.');

    Saloon::assertNothingSent();
});

test('vpn:init targets the CHOSEN environment\'s own saved context, never the ambient current context', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    $original = getcwd();
    chdir($dir);

    $config = ConfigData::from([
        'name' => 'demo',
        'database' => 'sqlite',
        'environments' => ['local' => [], 'production' => []],
    ]);
    $config->setCloud('production', new CloudData(ip: '203.0.113.10', user: 'deploy'));
    $config->saveToFile($dir);

    $kubectl = vpnInitKubectl().' --context=larakube-203.0.113.10';

    try {
        Process::fake([
            '*get namespace larakube-vpn*' => Process::result(output: ''),
            '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
            '*get configmap plex-registry*' => Process::result(output: '', exitCode: 1),
            '*configmap plex-registry*' => Process::result(output: 'configmap/plex-registry configured'),
            '*get configmap plex-commons*' => Process::result(output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]])),
            '*exec*postgres*' => Process::result(output: 'CREATE ROLE'),
            '*get secret vpn-management-store*' => Process::result(output: '', exitCode: 1),
            '*create secret generic vpn-management-store*' => Process::result(output: 'secret/vpn-management-store created'),
            '*logs deploy/vpn-management*' => Process::result(output: 'single account mode enabled, accounts number 1'),
            '*create namespace larakube-vpn*' => Process::result(output: 'namespace/larakube-vpn created'),
            '*apply -f *' => Process::result(output: 'applied'),
            '*rollout status deploy/vpn-management*' => Process::result(output: 'rollout success'),
            '*rollout status deploy/vpn-signal*' => Process::result(output: 'rollout success'),
            '*rollout status deploy/vpn-relay*' => Process::result(output: 'rollout success'),
            '*rollout status deploy/vpn-dashboard*' => Process::result(output: 'rollout success'),
            // Not SSO-wired by default: the dashboard is skipped, mirroring a cluster that has not run sso:wire yet.
            '*get secret vpn-management-oidc*' => Process::result(output: '', exitCode: 1),
            '*rollout status deployment/vpn-client*' => Process::result(output: 'rollout success'),
            '*get secret vpn-management-secrets*' => Process::result(output: 'vpn-management-secrets', exitCode: 0),
            '*get secret vpn-management-config*' => Process::result(output: vpnManagementConfigFixture('vpn.example.com'), exitCode: 0),
            '*larakube-tools-registry*' => Process::result(output: ''),
            '*create namespace larakube-shared*' => Process::result(output: 'created'),
        ]);
        Process::preventStrayProcesses();

        $this->artisan('vpn:init production --domain=example.com')
            ->assertExitCode(0)
            ->expectsOutputToContain('NetBird VPN stack is live.');

        Saloon::assertNothingSent();
    } finally {
        chdir($original);
        $temporaryDirectory->delete();
    }
});

test('vpn:remove removes netbird vpn namespace when --remove is passed', function (): void {
    Process::fake([
        '*get namespace larakube-vpn*' => Process::result(output: ''),
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*get configmap plex-registry*' => Process::result(output: '', exitCode: 1),
        '*configmap plex-registry*' => Process::result(output: 'configmap/plex-registry configured'),
        '*get configmap plex-commons*' => Process::result(output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]])),
        '*exec*postgres*' => Process::result(output: 'CREATE ROLE'),
        '*get secret vpn-management-store*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-store*' => Process::result(output: 'secret/vpn-management-store created'),
        '*logs deploy/vpn-management*' => Process::result(output: 'single account mode enabled, accounts number 1'),
        vpnInitKubectl().' delete namespace larakube-vpn*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('vpn:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing NetBird VPN namespace...')
        ->expectsOutputToContain('removed from larakube-vpn');
});

test('vpn:init bootstraps NetBird auth non-interactively on first run', function (): void {
    $kubectl = vpnInitKubectl();

    Process::fake([
        '*get namespace larakube-vpn*' => Process::result(output: ''),
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*get configmap plex-registry*' => Process::result(output: '', exitCode: 1),
        '*configmap plex-registry*' => Process::result(output: 'configmap/plex-registry configured'),
        '*get configmap plex-commons*' => Process::result(output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]])),
        '*exec*postgres*' => Process::result(output: 'CREATE ROLE'),
        '*get secret vpn-management-store*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-store*' => Process::result(output: 'secret/vpn-management-store created'),
        '*logs deploy/vpn-management*' => Process::result(output: 'single account mode enabled, accounts number 1'),
        '*create namespace larakube-vpn*' => Process::result(output: 'namespace/larakube-vpn created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout status deploy/vpn-management*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-signal*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-relay*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-dashboard*' => Process::result(output: 'rollout success'),
        '*get secret vpn-management-oidc*' => Process::result(output: '', exitCode: 1),
        '*rollout status deployment/vpn-client*' => Process::result(output: 'rollout success'),
        '*get secret vpn-management-secrets*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-secrets*' => Process::result(output: 'secret/vpn-management-secrets created'),
        '*get secret vpn-management-config*' => Process::result(output: vpnManagementConfigFixture('vpn.'.GlobalConfigData::load()->getLocalTld()), exitCode: 0),
    ]);
    Saloon::fake([
        SetupOwnerRequest::class => MockResponse::make(['personal_access_token' => 'nbp_owner_token']),
        ListUsersRequest::class => MockResponse::make([]),
        ListGroupsRequest::class => MockResponse::make([]),
        CreateGroupRequest::class => MockResponse::make(['id' => 'grp-routers', 'name' => 'larakube-routers']),
        CreateServiceUserRequest::class => MockResponse::make(['id' => 'svc-1', 'is_service_user' => true]),
        CreatePersonalAccessTokenRequest::class => MockResponse::make(['plain_token' => 'nbp_service_token']),
        CreateSetupKeyRequest::class => MockResponse::make(['key' => 'nb_setup_key_test']),
    ]);

    $this->artisan('vpn:init local')->assertExitCode(0);

    Saloon::assertSent(fn ($request) => $request instanceof SetupOwnerRequest
        && $request->body()->get('create_pat') === true);

    // The owner's token is used once, to mint the service user's — and then
    // dropped. The setup key proves the service token actually works BEFORE
    // anything is written, so a token that cannot mint keys never gets stored.
    Saloon::assertSent(fn ($request, $response) => $request instanceof CreateSetupKeyRequest
        && $response->getPendingRequest()->headers()->get('Authorization') === 'Token nbp_service_token');
    Process::assertRan(fn ($process) => str_contains($process->command, 'create secret generic vpn-management-secrets')
        && str_contains($process->command, 'nbp_service_token'));

    // The gateway is the routing peer every Network will point at, so it has to
    // land in larakube-routers as it enrols — it cannot be moved there later.
    Saloon::assertSent(fn ($request) => $request instanceof CreateSetupKeyRequest
        && $request->body()->get('auto_groups') === ['grp-routers']);

    // The dashboard logs in against the EMBEDDED IdP, so the generated owner
    // password is the only credential that opens it. Discarding it (as this
    // did until 2026-08-28) left the dashboard unreachable, recoverable only
    // via `netbird-mgmt admin user change-password` inside the pod.
    Process::assertRan(fn ($process) => str_contains($process->command, 'create secret generic vpn-management-secrets')
        && str_contains($process->command, '--from-literal=admin-email=')
        && str_contains($process->command, '--from-literal=admin-password='));
});

test('vpn:init warns but does not fail when NetBird auth bootstrap fails', function (): void {
    $kubectl = vpnInitKubectl();

    Process::fake([
        '*get namespace larakube-vpn*' => Process::result(output: ''),
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*get configmap plex-registry*' => Process::result(output: '', exitCode: 1),
        '*configmap plex-registry*' => Process::result(output: 'configmap/plex-registry configured'),
        '*get configmap plex-commons*' => Process::result(output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]])),
        '*exec*postgres*' => Process::result(output: 'CREATE ROLE'),
        '*get secret vpn-management-store*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-store*' => Process::result(output: 'secret/vpn-management-store created'),
        '*logs deploy/vpn-management*' => Process::result(output: 'single account mode enabled, accounts number 1'),
        '*create namespace larakube-vpn*' => Process::result(output: 'namespace/larakube-vpn created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout status deploy/vpn-management*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-signal*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-relay*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-dashboard*' => Process::result(output: 'rollout success'),
        '*get secret vpn-management-oidc*' => Process::result(output: '', exitCode: 1),
        '*rollout status deployment/vpn-client*' => Process::result(output: 'rollout success'),
        '*get secret vpn-management-secrets*' => Process::result(output: '', exitCode: 1),
        '*get secret vpn-management-config*' => Process::result(output: vpnManagementConfigFixture('vpn.'.GlobalConfigData::load()->getLocalTld()), exitCode: 0),
    ]);
    Saloon::fake([
        SetupOwnerRequest::class => MockResponse::make(status: 500),
    ]);

    $this->artisan('vpn:init local')
        ->assertExitCode(0)
        ->expectsOutputToContain('Could not bootstrap NetBird auth automatically');
});

test('vpn:remove also targets the CHOSEN environment\'s own saved context', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    $original = getcwd();
    chdir($dir);

    $config = ConfigData::from([
        'name' => 'demo',
        'database' => 'sqlite',
        'environments' => ['local' => [], 'production' => []],
    ]);
    $config->setCloud('production', new CloudData(ip: '203.0.113.10', user: 'deploy'));
    $config->saveToFile($dir);

    // vpn:remove builds its kubectl through the shared contextKubectl() helper,
    // which shell-escapes the context rather than interpolating it bare.
    $kubectl = vpnInitKubectl()." --context 'larakube-203.0.113.10'";

    try {
        Process::fake([
            '*get namespace larakube-vpn*' => Process::result(output: ''),
            '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
            '*get configmap plex-registry*' => Process::result(output: '', exitCode: 1),
            '*configmap plex-registry*' => Process::result(output: 'configmap/plex-registry configured'),
            '*get configmap plex-commons*' => Process::result(output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]])),
            '*exec*postgres*' => Process::result(output: 'CREATE ROLE'),
            '*get secret vpn-management-store*' => Process::result(output: '', exitCode: 1),
            '*create secret generic vpn-management-store*' => Process::result(output: 'secret/vpn-management-store created'),
            '*logs deploy/vpn-management*' => Process::result(output: 'single account mode enabled, accounts number 1'),
            "{$kubectl} delete namespace larakube-vpn*" => Process::result(output: 'deleted'),
            // The shared base also unregisters the tool from the cluster
            // registry, which the old --remove path skipped entirely.
            '*larakube-tools-registry*' => Process::result(output: ''),
            '*apply -f -*' => Process::result(output: 'configured'),
        ]);
        Process::preventStrayProcesses();

        $this->artisan('vpn:remove production --force')
            ->assertExitCode(0)
            ->expectsOutputToContain('removed from larakube-vpn');
    } finally {
        chdir($original);
        $temporaryDirectory->delete();
    }
});

test('vpn:init re-renders management.json from the PRESERVED relay secret + encryption key, and restarts management only when content actually changed', function (): void {
    // Regression guard for a real live incident, 2026-08-25: the original
    // ensureVpnConfig() skipped entirely once the Secret existed — a
    // genuine template fix (e.g. the /oauth2 issuer suffix, see
    // management-config.blade.php) could never reach an already-deployed
    // cluster. The fix must NEVER regenerate relaySecret/dataStoreEncryptionKey
    // on a re-run — dataStoreEncryptionKey doubles as EmbeddedIdP's own
    // database encryption key, and a fresh random value would make the
    // already-encrypted management database unreadable on next boot.
    $kubectl = vpnInitKubectl();
    $host = 'vpn.'.GlobalConfigData::load()->getLocalTld();

    // An OLDER-shaped config: same real secrets, but missing the /oauth2
    // suffix a template fix since added — content genuinely differs from
    // what the CURRENT template renders for the same secrets.
    $staleConfig = str_replace(
        '"Issuer": "https://'.$host.'/oauth2"',
        '"Issuer": "https://'.$host.'"',
        (string) base64_decode(vpnManagementConfigFixture($host, 'preserved-relay-secret', 'preserved-encryption-key')),
    );
    expect($staleConfig)->not->toContain('/oauth2"');

    Process::fake([
        '*get namespace larakube-vpn*' => Process::result(output: ''),
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*get configmap plex-registry*' => Process::result(output: '', exitCode: 1),
        '*configmap plex-registry*' => Process::result(output: 'configmap/plex-registry configured'),
        '*get configmap plex-commons*' => Process::result(output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]])),
        '*exec*postgres*' => Process::result(output: 'CREATE ROLE'),
        '*get secret vpn-management-store*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-store*' => Process::result(output: 'secret/vpn-management-store created'),
        '*logs deploy/vpn-management*' => Process::result(output: 'single account mode enabled, accounts number 1'),
        '*create namespace larakube-vpn*' => Process::result(output: 'namespace/larakube-vpn created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout status deploy/vpn-management*' => Process::result(output: 'rollout success'),
        '*rollout restart deployment/vpn-management*' => Process::result(output: 'restarted'),
        '*rollout status deploy/vpn-signal*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-relay*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-dashboard*' => Process::result(output: 'rollout success'),
        '*get secret vpn-management-oidc*' => Process::result(output: '', exitCode: 1),
        '*rollout status deployment/vpn-client*' => Process::result(output: 'rollout success'),
        '*get secret vpn-management-secrets*' => Process::result(output: 'vpn-management-secrets', exitCode: 0),
        '*get secret vpn-management-config*' => Process::result(output: base64_encode($staleConfig), exitCode: 0),
        '*create secret generic*' => Process::result(output: 'secret/vpn-management-config configured'),
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*create namespace larakube-shared*' => Process::result(output: 'created'),
    ]);

    $this->artisan('vpn:init local')
        ->assertExitCode(0)
        ->expectsOutputToContain('Restarting NetBird Management to pick up config changes...');

    // The real secrets survive unchanged into the re-rendered config.
    Process::assertRan(fn ($process) => str_contains($process->command, 'kubectl create secret generic vpn-management-config')
        && str_contains($process->command, '--from-literal=relay-secret='.escapeshellarg('preserved-relay-secret')));
    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/vpn-management'));
});

test('vpn:init does NOT restart management when the re-rendered config is byte-identical to what is already deployed', function (): void {
    $kubectl = vpnInitKubectl();
    $host = 'vpn.'.GlobalConfigData::load()->getLocalTld();

    Process::fake([
        '*get namespace larakube-vpn*' => Process::result(output: ''),
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*get configmap plex-registry*' => Process::result(output: '', exitCode: 1),
        '*configmap plex-registry*' => Process::result(output: 'configmap/plex-registry configured'),
        '*get configmap plex-commons*' => Process::result(output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]])),
        '*exec*postgres*' => Process::result(output: 'CREATE ROLE'),
        '*get secret vpn-management-store*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-store*' => Process::result(output: 'secret/vpn-management-store created'),
        '*logs deploy/vpn-management*' => Process::result(output: 'single account mode enabled, accounts number 1'),
        '*create namespace larakube-vpn*' => Process::result(output: 'namespace/larakube-vpn created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout status deploy/vpn-management*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-signal*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-relay*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-dashboard*' => Process::result(output: 'rollout success'),
        '*get secret vpn-management-oidc*' => Process::result(output: '', exitCode: 1),
        '*rollout status deployment/vpn-client*' => Process::result(output: 'rollout success'),
        '*get secret vpn-management-secrets*' => Process::result(output: 'vpn-management-secrets', exitCode: 0),
        '*get secret vpn-management-config*' => Process::result(output: vpnManagementConfigFixture($host, 'preserved-relay-secret', 'preserved-encryption-key'), exitCode: 0),
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*create namespace larakube-shared*' => Process::result(output: 'created'),
    ]);

    $this->artisan('vpn:init local')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('Restarting NetBird Management to pick up config changes...');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'kubectl create secret generic vpn-management-config'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/vpn-management'));
});

test('vpn:init generates the relay secret + management.json on first run', function (): void {
    $kubectl = vpnInitKubectl();

    Process::fake([
        '*get namespace larakube-vpn*' => Process::result(output: ''),
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*get configmap plex-registry*' => Process::result(output: '', exitCode: 1),
        '*configmap plex-registry*' => Process::result(output: 'configmap/plex-registry configured'),
        '*get configmap plex-commons*' => Process::result(output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]])),
        '*exec*postgres*' => Process::result(output: 'CREATE ROLE'),
        '*get secret vpn-management-store*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-store*' => Process::result(output: 'secret/vpn-management-store created'),
        '*logs deploy/vpn-management*' => Process::result(output: 'single account mode enabled, accounts number 1'),
        '*create namespace larakube-vpn*' => Process::result(output: 'namespace/larakube-vpn created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout status deploy/vpn-management*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-signal*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-relay*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-dashboard*' => Process::result(output: 'rollout success'),
        '*get secret vpn-management-oidc*' => Process::result(output: '', exitCode: 1),
        '*rollout status deployment/vpn-client*' => Process::result(output: 'rollout success'),
        '*get secret vpn-management-secrets*' => Process::result(output: 'vpn-management-secrets', exitCode: 0),
        '*get secret vpn-management-config*' => Process::result(output: '', exitCode: 1),
        '*create secret generic*' => Process::result(output: 'secret/vpn-management-config created'),
    ]);

    $this->artisan('vpn:init local')->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'kubectl create secret generic vpn-management-config')
        && str_contains($process->command, '--from-literal=relay-secret=')
        && str_contains($process->command, '--from-file=management.json='));
});

test('the management manifest always carries a single-account domain we chose', function (): void {
    // The mode cannot be turned off — NetBird has run it by default since
    // v0.10.1 and the flag behind this var has its own non-empty default, so an
    // absent var substitutes NetBird's domain for ours rather than disabling
    // anything. Confirmed live 2026-08-29: an empty value still logged
    // "single account mode enabled".
    $manifest = view('k8s.vpn.shared', [
        'host' => 'vpn.example.com',
        'isLocal' => false,
        'ssoDomain' => 'example.com',
        'noPlex' => false,
        'plexNamespace' => 'larakube-plex',
        'storeDb' => 'vpn_management',
        'instance' => '',
    ])->render();

    // As a FLAG, not an env var. netbird-mgmt reads no NETBIRD_MGMT_* names --
    // those belong to upstream's docker-compose template. An env var alone left
    // the binary on its own default, 'netbird.selfhosted', while the Deployment
    // claimed otherwise (live 2026-08-29), which silently splits new SSO users
    // into their own accounts.
    expect($manifest)
        ->toContain('--single-account-mode-domain')
        ->toContain('- "example.com"')
        ->not->toContain('NETBIRD_MGMT_SINGLE_ACCOUNT_MODE_DOMAIN')
        // The CMD's own flags must survive args replacing it...
        ->toContain('- --log-file')
        // ...but NOT the subcommand: 'management' is in the image ENTRYPOINT, and
        // restating it produced a stray positional ("management management") live.
        ->not->toContain('- management');
});

test('vpn:init deploys the dashboard and waits for it', function (): void {
    $kubectl = vpnInitKubectl();

    Process::fake([
        '*get namespace larakube-vpn*' => Process::result(output: ''),
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*get configmap plex-registry*' => Process::result(output: '', exitCode: 1),
        '*configmap plex-registry*' => Process::result(output: 'configmap/plex-registry configured'),
        '*get configmap plex-commons*' => Process::result(output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]])),
        '*exec*postgres*' => Process::result(output: 'CREATE ROLE'),
        '*get secret vpn-management-store*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-store*' => Process::result(output: 'secret/vpn-management-store created'),
        '*logs deploy/vpn-management*' => Process::result(output: 'single account mode enabled, accounts number 1'),
        '*create namespace larakube-vpn*' => Process::result(output: 'namespace/larakube-vpn created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout status deploy/vpn-management*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-signal*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-relay*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-dashboard*' => Process::result(output: 'rollout success'),
        '*rollout status deployment/vpn-client*' => Process::result(output: 'rollout success'),
        // SSO wired: vpn-management-oidc exists, so the dashboard becomes deployable.
        '*get secret vpn-management-oidc*' => Process::result(output: 'Y2xpZW50LWlk', exitCode: 0),
        '*get secret vpn-management-secrets*' => Process::result(output: 'vpn-management-secrets', exitCode: 0),
        '*get secret vpn-management-config*' => Process::result(output: vpnManagementConfigFixture('vpn.'.GlobalConfigData::load()->getLocalTld()), exitCode: 0),
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*create namespace larakube-shared*' => Process::result(output: 'created'),
    ]);

    $this->artisan('vpn:init local')
        ->assertExitCode(0)
        ->expectsOutputToContain('Waiting for NetBird Dashboard...');
});

test('vpn:init warns when single-account mode did not come up', function (): void {
    // The stack can deploy perfectly and still be useless to the next teammate:
    // with the mode off, every SSO login mints its own account and its own /16.
    $kubectl = vpnInitKubectl();

    Process::fake([
        '*get namespace larakube-vpn*' => Process::result(output: ''),
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*get configmap plex-registry*' => Process::result(output: '', exitCode: 1),
        '*configmap plex-registry*' => Process::result(output: 'configmap/plex-registry configured'),
        '*get configmap plex-commons*' => Process::result(output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]])),
        '*exec*postgres*' => Process::result(output: 'CREATE ROLE'),
        '*get secret vpn-management-store*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-store*' => Process::result(output: 'secret/vpn-management-store created'),
        '*logs deploy/vpn-management*' => Process::result(output: 'single account mode disabled, accounts number 4'),
        '*create namespace larakube-vpn*' => Process::result(output: 'namespace/larakube-vpn created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout status deploy/vpn-management*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-signal*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-relay*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-dashboard*' => Process::result(output: 'rollout success'),
        '*get secret vpn-management-oidc*' => Process::result(output: '', exitCode: 1),
        '*rollout status deployment/vpn-client*' => Process::result(output: 'rollout success'),
        '*get secret vpn-management-secrets*' => Process::result(output: 'vpn-management-secrets', exitCode: 0),
        '*get secret vpn-management-config*' => Process::result(output: vpnManagementConfigFixture('vpn.'.GlobalConfigData::load()->getLocalTld()), exitCode: 0),
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*create namespace larakube-shared*' => Process::result(output: 'created'),
    ]);

    // A warning, never a failure — the deploy did succeed, and exiting non-zero
    // would make vpn:init un-runnable on the one cluster that needs fixing.
    $this->artisan('vpn:init local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Single-account mode is OFF')
        ->expectsOutputToContain('4 accounts');
});

test('vpn:init reuses an existing larakube-cli service user rather than creating a second', function (): void {
    $kubectl = vpnInitKubectl();

    Process::fake([
        '*get namespace larakube-vpn*' => Process::result(output: ''),
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*get configmap plex-registry*' => Process::result(output: '', exitCode: 1),
        '*configmap plex-registry*' => Process::result(output: 'configmap/plex-registry configured'),
        '*get configmap plex-commons*' => Process::result(output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]])),
        '*exec*postgres*' => Process::result(output: 'CREATE ROLE'),
        '*get secret vpn-management-store*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-store*' => Process::result(output: 'secret/vpn-management-store created'),
        '*logs deploy/vpn-management*' => Process::result(output: 'single account mode enabled, accounts number 1'),
        '*create namespace larakube-vpn*' => Process::result(output: 'namespace/larakube-vpn created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout status deploy/vpn-management*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-signal*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-relay*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-dashboard*' => Process::result(output: 'rollout success'),
        '*get secret vpn-management-oidc*' => Process::result(output: '', exitCode: 1),
        '*rollout status deployment/vpn-client*' => Process::result(output: 'rollout success'),
        '*get secret vpn-management-secrets*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-secrets*' => Process::result(output: 'secret/vpn-management-secrets created'),
        '*get secret vpn-management-config*' => Process::result(output: vpnManagementConfigFixture('vpn.'.GlobalConfigData::load()->getLocalTld()), exitCode: 0),
    ]);
    Saloon::fake([
        SetupOwnerRequest::class => MockResponse::make(['personal_access_token' => 'nbp_owner_token']),
        ListUsersRequest::class => MockResponse::make([
            ['id' => 'human-1', 'name' => 'James', 'is_service_user' => false],
            ['id' => 'svc-existing', 'name' => 'larakube-cli', 'is_service_user' => true],
        ]),
        ListGroupsRequest::class => MockResponse::make([]),
        CreateGroupRequest::class => MockResponse::make(['id' => 'grp-routers', 'name' => 'larakube-routers']),
        CreatePersonalAccessTokenRequest::class => MockResponse::make(['plain_token' => 'nbp_service_token']),
        CreateSetupKeyRequest::class => MockResponse::make(['key' => 'nb_setup_key_test']),
    ]);

    $this->artisan('vpn:init local')->assertExitCode(0);

    // Two identically-named service users is a confusing thing to leave in
    // someone's dashboard forever, so a retry must adopt the one already there.
    Saloon::assertNotSent(fn ($request) => $request instanceof CreateServiceUserRequest);
    Saloon::assertSent(fn ($request) => $request instanceof CreatePersonalAccessTokenRequest
        && str_contains($request->resolveEndpoint(), 'svc-existing'));
});

test('vpn:init falls back to the owner token when the service user cannot be created', function (): void {
    // A NetBird that will not create a service user is still a working NetBird.
    // Failing the whole deploy over it would leave the cluster with no VPN at
    // all, rather than one with a slightly worse token.
    $kubectl = vpnInitKubectl();

    Process::fake([
        '*get namespace larakube-vpn*' => Process::result(output: ''),
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*get configmap plex-registry*' => Process::result(output: '', exitCode: 1),
        '*configmap plex-registry*' => Process::result(output: 'configmap/plex-registry configured'),
        '*get configmap plex-commons*' => Process::result(output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]])),
        '*exec*postgres*' => Process::result(output: 'CREATE ROLE'),
        '*get secret vpn-management-store*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-store*' => Process::result(output: 'secret/vpn-management-store created'),
        '*logs deploy/vpn-management*' => Process::result(output: 'single account mode enabled, accounts number 1'),
        '*create namespace larakube-vpn*' => Process::result(output: 'namespace/larakube-vpn created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout status deploy/vpn-management*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-signal*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-relay*' => Process::result(output: 'rollout success'),
        '*rollout status deploy/vpn-dashboard*' => Process::result(output: 'rollout success'),
        '*get secret vpn-management-oidc*' => Process::result(output: '', exitCode: 1),
        '*rollout status deployment/vpn-client*' => Process::result(output: 'rollout success'),
        '*get secret vpn-management-secrets*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-secrets*' => Process::result(output: 'secret/vpn-management-secrets created'),
        '*get secret vpn-management-config*' => Process::result(output: vpnManagementConfigFixture('vpn.'.GlobalConfigData::load()->getLocalTld()), exitCode: 0),
    ]);
    Saloon::fake([
        SetupOwnerRequest::class => MockResponse::make(['personal_access_token' => 'nbp_owner_token']),
        ListUsersRequest::class => MockResponse::make([]),
        ListGroupsRequest::class => MockResponse::make([]),
        CreateGroupRequest::class => MockResponse::make(['id' => 'grp-routers', 'name' => 'larakube-routers']),
        CreateServiceUserRequest::class => MockResponse::make(['message' => 'nope'], 403),
        CreateSetupKeyRequest::class => MockResponse::make(['key' => 'nb_setup_key_test']),
    ]);

    $this->artisan('vpn:init local')
        ->assertExitCode(0)
        ->expectsOutputToContain('Could not create a NetBird service user');

    Saloon::assertSent(fn ($request, $response) => $request instanceof CreateSetupKeyRequest
        && $response->getPendingRequest()->headers()->get('Authorization') === 'Token nbp_owner_token');
});

test('the management manifest puts the store on Commons Postgres, engine included', function (): void {
    // NETBIRD_STORE_ENGINE and the DSN are BOTH required: with the engine unset
    // NetBird silently falls back to SQLite on the PVC, which looks identical
    // until the node dies and takes the whole control plane with it.
    $manifest = view('k8s.vpn.shared', [
        'host' => 'vpn.example.com',
        'isLocal' => false,
        'ssoDomain' => 'example.com',
        'noPlex' => false,
        'plexNamespace' => 'larakube-plex',
        'storeDb' => 'vpn_management',
    ])->render();

    expect($manifest)
        ->toContain('name: NETBIRD_STORE_ENGINE')
        ->toContain('value: "postgres"')
        ->toContain('host=postgres.larakube-plex.svc.cluster.local user=vpn_management password=$(DB_PASSWORD) dbname=vpn_management port=5432')
        // ADR 0018: the password reaches the DSN through kubelet's $(VAR)
        // expansion, never as a literal on the Deployment.
        ->toContain('secretKeyRef')
        ->toContain('name: vpn-management-store');
});

test('--no-plex leaves NetBird on its own SQLite store', function (): void {
    $manifest = view('k8s.vpn.shared', [
        'host' => 'vpn.example.com',
        'isLocal' => false,
        'ssoDomain' => 'example.com',
        'noPlex' => true,
        'plexNamespace' => 'larakube-plex',
        'storeDb' => 'vpn_management',
    ])->render();

    expect($manifest)
        ->not->toContain('NETBIRD_STORE_ENGINE')
        ->not->toContain('NB_STORE_ENGINE_POSTGRES_DSN')
        ->not->toContain('vpn-management-store');
});

test('vpn:init defers to the OpenBao-owned password when the tenant is already wired', function (): void {
    // Re-running vpn:init must not clobber a rotated password back to a fresh
    // local one — that leaves the Secret and Postgres disagreeing until the next
    // rotation, which is how tools crash-loop with 28P01 on their next restart.
    $kubectl = vpnInitKubectl();

    Process::fake([
        '*get namespace larakube-vpn*' => Process::result(output: ''),
        '*logs deploy/vpn-management*' => Process::result(output: 'single account mode enabled, accounts number 1'),
        // Must precede the presence check below — readOpenBaoBootstrapSecret()
        // base64-decodes this, and an unmatched catch-all yields a binary token
        // that Guzzle rejects as an invalid header value.
        // removeNamespace() polls for the namespace to disappear rather than
        // blocking on kubectl's finalizer wait.
        '*get namespace larakube-vpn*' => Process::result(output: ''),
        '*get secret openbao-bootstrap*root-token*' => Process::result(output: base64_encode('test-root-token')),
        '*get secret openbao-bootstrap*' => Process::result(output: 'openbao-bootstrap'),
        '*get configmap plex-registry*' => Process::result(output: '', exitCode: 1),
        '*configmap plex-registry*' => Process::result(output: 'configmap/plex-registry configured'),
        '*get configmap plex-commons*' => Process::result(output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]])),
        '*exec*postgres*' => Process::result(output: 'CREATE ROLE'),
        '*get secret vpn-management-store*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-store*' => Process::result(output: 'secret/vpn-management-store created'),
        '*create namespace larakube-vpn*' => Process::result(output: 'created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout status deploy/vpn-*' => Process::result(output: 'rollout success'),
        '*get secret vpn-management-oidc*' => Process::result(output: '', exitCode: 1),
        '*rollout status deployment/vpn-client*' => Process::result(output: 'rollout success'),
        '*get secret vpn-management-secrets*' => Process::result(output: 'vpn-management-secrets', exitCode: 0),
        '*get secret vpn-management-config*' => Process::result(output: vpnManagementConfigFixture('vpn.'.GlobalConfigData::load()->getLocalTld()), exitCode: 0),
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*create namespace larakube-shared*' => Process::result(output: 'created'),
        '*port-forward*' => Process::result(),
        '*' => Process::result(output: ''),
    ]);

    Http::fake(['localhost:*' => Http::response(['data' => ['password' => 'openbao-owned-pw']])]);

    $this->artisan('vpn:init local --force')->assertExitCode(0);
})->group('vpn-openbao');

test('VpnTool satisfies the secrets:wire rotation contract', function (): void {
    // secrets:wire discovers targets purely through these four — a tool missing
    // any one of them is silently absent from --all and from the picker.
    $vpn = ClusterTool::VPN;

    expect($vpn->supportsDatabasePasswordRotation())->toBeTrue()
        ->and($vpn->commonsDatabases())->toBe(['vpn_management'])
        ->and($vpn->deploymentName())->toBe('vpn-management')
        ->and($vpn->dbSecretRef())->toBe([
            'namespace' => 'larakube-vpn',
            'secret' => 'vpn-management-store',
            'key' => 'db-password',
        ]);

    // NOT vpn-management-secrets: secrets:wire's ExternalSecret owns every key in the
    // Secret it targets, so sharing would let a rotation clobber the PAT,
    // setup key and dashboard login stored alongside.
    expect($vpn->dbSecretRef()['secret'])->not->toBe('vpn-management-secrets');
});

test('vpn:remove still unregisters the tool when the namespace is slow to drain', function (): void {
    // Confirmed live 2026-08-28: kubectl's finalizer wait outran the 60s default,
    // the timeout threw, and the exception escaped the teardown loop before
    // unregisterTool() ran — leaving a registry entry claiming VPN was installed
    // on a cluster where the namespace and both PVs were already gone.
    $kubectl = vpnInitKubectl();

    Process::fake([
        // Still Terminating every time we look: the deletion was accepted, so
        // this must not be treated as a failure.
        '*get namespace larakube-vpn*' => Process::result(output: 'larakube-vpn   Terminating   5d'),
        '*delete namespace larakube-vpn*' => Process::result(output: 'namespace "larakube-vpn" deleted'),
        '*larakube-tools-registry*' => Process::result(output: base64_encode((string) json_encode([
            ['tool' => 'vpn', 'instance' => 'vpn-luchtech-dev', 'installedAt' => '2026-08-27T16:45:44+00:00', 'host' => 'vpn.luchtech.dev'],
        ]))),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('vpn:remove local --force')->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete namespace larakube-vpn')
        && str_contains($process->command, '--wait=false'));
});

test('waitForTls refuses to continue when only this machine cannot resolve', function (): void {
    // It used to print the right remedy and then press on anyway — so the run
    // ended in a failed bootstrap and a gateway stuck in
    // CreateContainerConfigError, three steps removed from the actual cause.
    // Confirmed live 2026-08-29, repeatedly.
    $command = new class extends VpnInitCommand
    {
        public array $forcedAcme = [];

        public bool $localResolves = false;

        public function callWaitForTls(string $host): bool
        {
            return $this->waitForTls('kubectl', 'larakube-vpn', $host, false);
        }

        protected function pollForValidTls(string $host, int $maxWait): bool
        {
            return false;
        }

        protected function pollForDnsPropagation(string $host, int $maxWait): bool
        {
            return true;
        }

        protected function hostResolvesLocally(string $host): bool
        {
            return $this->localResolves;
        }

        protected function nudgeExternalDns(string $kubectl): void {}

        protected function forceFreshAcmeAttempt(string $kubectl, string $ns, string $host, bool $isLocal): void
        {
            $this->forcedAcme[] = $host;
        }
    };

    $command->setLaravel($this->app);
    $command->setOutput(new Illuminate\Console\OutputStyle(
        new Symfony\Component\Console\Input\ArrayInput([]),
        new Symfony\Component\Console\Output\BufferedOutput,
    ));

    State::$isTesting = false;
    try {
        $verdict = $command->callWaitForTls('vpn.example.com');
    } finally {
        State::$isTesting = true;
    }

    // No certificate retry — a fresh cert cannot fix a name this machine cannot
    // resolve — and a false verdict so the caller aborts instead of pressing on.
    expect($verdict)->toBeFalse()
        ->and($command->forcedAcme)->toBeEmpty();
});

test('waitForTls still forces a fresh ACME attempt when the name resolves fine', function (): void {
    // The complement: with local resolution healthy, a failing TLS probe really
    // is a certificate problem and the retry is the right response.
    $command = new class extends VpnInitCommand
    {
        public array $forcedAcme = [];

        public function callWaitForTls(string $host): bool
        {
            return $this->waitForTls('kubectl', 'larakube-vpn', $host, false);
        }

        protected function pollForValidTls(string $host, int $maxWait): bool
        {
            return false;
        }

        protected function pollForDnsPropagation(string $host, int $maxWait): bool
        {
            return true;
        }

        protected function hostResolvesLocally(string $host): bool
        {
            return true;
        }

        protected function nudgeExternalDns(string $kubectl): void {}

        protected function forceFreshAcmeAttempt(string $kubectl, string $ns, string $host, bool $isLocal): void
        {
            $this->forcedAcme[] = $host;
        }
    };

    $command->setLaravel($this->app);
    $command->setOutput(new Illuminate\Console\OutputStyle(
        new Symfony\Component\Console\Input\ArrayInput([]),
        new Symfony\Component\Console\Output\BufferedOutput,
    ));

    State::$isTesting = false;
    try {
        $verdict = $command->callWaitForTls('vpn.example.com');
    } finally {
        State::$isTesting = true;
    }

    expect($verdict)->toBeTrue()
        ->and($command->forcedAcme)->toBe(['vpn.example.com']);
});

test('the bootstrap owner gets an address inside the SSO domain, not the operator\'s own', function (): void {
    // NetBird claims an account's private domain from the owner's email on login.
    // A personal gmail.com address is a PUBLIC domain and leaves the account with
    // none — which is exactly what makes single-account mode unusable later.
    // "admin@{$host}" is no better: vpn.example.com is a subdomain of the domain
    // SSO logins actually carry, so it would claim the wrong one.
    $kubectl = vpnInitKubectl();

    Process::fake([
        '*get namespace larakube-vpn*' => Process::result(output: ''),
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*get configmap plex-registry*' => Process::result(output: '', exitCode: 1),
        '*configmap plex-registry*' => Process::result(output: 'configured'),
        '*get configmap plex-commons*' => Process::result(output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]])),
        '*exec*postgres*' => Process::result(output: 'CREATE ROLE'),
        '*get secret vpn-management-store*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-store*' => Process::result(output: 'created'),
        '*create namespace larakube-vpn*' => Process::result(output: 'created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout status deploy/vpn-*' => Process::result(output: 'rollout success'),
        '*get secret vpn-management-oidc*' => Process::result(output: '', exitCode: 1),
        '*rollout status deployment/vpn-client*' => Process::result(output: 'rollout success'),
        '*get secret vpn-management-secrets*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-secrets*' => Process::result(output: 'created'),
        '*get secret vpn-management-config*' => Process::result(output: vpnManagementConfigFixture('vpn.'.GlobalConfigData::load()->getLocalTld()), exitCode: 0),
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*create namespace larakube-shared*' => Process::result(output: 'created'),
    ]);

    Saloon::fake([
        SetupOwnerRequest::class => MockResponse::make(['personal_access_token' => 'nbp_owner']),
        ListUsersRequest::class => MockResponse::make([]),
        CreateServiceUserRequest::class => MockResponse::make(['id' => 'svc-1']),
        CreatePersonalAccessTokenRequest::class => MockResponse::make(['plain_token' => 'nbp_service']),
        ListGroupsRequest::class => MockResponse::make([]),
        CreateGroupRequest::class => MockResponse::make(['id' => 'grp-routers']),
        CreateSetupKeyRequest::class => MockResponse::make(['key' => 'k']),
    ]);

    $this->artisan('vpn:init local')->assertExitCode(0);

    $tld = GlobalConfigData::load()->getLocalTld();
    Saloon::assertSent(fn ($request) => $request instanceof SetupOwnerRequest
        && $request->body()->get('email') === "admin@{$tld}");
});

test('vpn:init explains a 412 from /api/setup instead of blaming the dashboard', function (): void {
    // 412 means the STORE already has an owner — the namespace was rebuilt but
    // the Commons tenant survived, because plain vpn:remove keeps the database.
    // /api/setup can never succeed against that store again, so the generic
    // "log into the dashboard once to finish setup" is precisely wrong.
    $kubectl = vpnInitKubectl();

    Process::fake([
        '*get namespace larakube-vpn*' => Process::result(output: ''),
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*get configmap plex-registry*' => Process::result(output: '', exitCode: 1),
        '*configmap plex-registry*' => Process::result(output: 'configured'),
        '*get configmap plex-commons*' => Process::result(output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]])),
        '*exec*postgres*' => Process::result(output: 'CREATE ROLE'),
        '*get secret vpn-management-store*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-store*' => Process::result(output: 'created'),
        '*create namespace larakube-vpn*' => Process::result(output: 'created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout status deploy/vpn-*' => Process::result(output: 'rollout success'),
        '*get secret vpn-management-oidc*' => Process::result(output: '', exitCode: 1),
        '*rollout status deployment/vpn-client*' => Process::result(output: 'rollout success'),
        '*get secret vpn-management-secrets*' => Process::result(output: '', exitCode: 1),
        '*get secret vpn-management-config*' => Process::result(output: vpnManagementConfigFixture('vpn.'.GlobalConfigData::load()->getLocalTld()), exitCode: 0),
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*create namespace larakube-shared*' => Process::result(output: 'created'),
    ]);

    Saloon::fake([
        SetupOwnerRequest::class => MockResponse::make(['message' => 'setup already completed'], 412),
    ]);

    $this->artisan('vpn:init local')
        ->assertExitCode(0)
        ->expectsOutputToContain('--purge')
        ->expectsOutputToContain('vpn:setup-key');

    // Nothing was written: a half-built vpn-management-secrets would be worse than none.
    Process::assertDidntRun(fn ($p) => str_contains($p->command, 'create secret generic vpn-management-secrets'));
});

test('vpn:init allocates exactly the database vpn:remove --purge will drop', function (): void {
    // These are computed in two different places, and DROP DATABASE IF EXISTS on
    // a name that never existed reports success — so a mismatch is completely
    // silent. Confirmed live 2026-08-29: --purge left the store fully intact,
    // and the next vpn:init failed with "setup already completed".
    $vpn = ClusterTool::VPN;
    $instance = $vpn->instanceSlugFromHost('vpn.luchtech.dev');

    // What vpn:remove --purge drops, via dropCommonsTenants().
    $purgeTarget = $vpn->commonsDatabases($instance)[0];

    expect($purgeTarget)->toBe('vpn_management_vpn_luchtech_dev');

    // And what vpn:init renders into the DSN must be the same string.
    $manifest = view('k8s.vpn.shared', [
        'host' => 'vpn.luchtech.dev',
        'isLocal' => false,
        'ssoDomain' => 'luchtech.dev',
        'noPlex' => false,
        'plexNamespace' => 'larakube-plex',
        'storeDb' => $purgeTarget,
    ])->render();

    expect($manifest)->toContain("user={$purgeTarget} password=\$(DB_PASSWORD) dbname={$purgeTarget}");
});

test('vpn:init seeds the PAT into OpenBao so its ExternalSecret is green from the first install', function (): void {
    // creationPolicy: Merge means an unpopulated KV key parks the ExternalSecret
    // at SecretMissing forever — the same red noise this cluster already carries
    // from data-secrets-db and link-kutt-secrets-db.
    $kubectl = vpnInitKubectl();

    Process::fake([
        '*get namespace larakube-vpn*' => Process::result(output: ''),
        '*get secret openbao-bootstrap*root-token*' => Process::result(output: base64_encode('test-root-token')),
        '*get secret openbao-bootstrap*' => Process::result(output: 'openbao-bootstrap'),
        '*get configmap plex-registry*' => Process::result(output: '', exitCode: 1),
        '*configmap plex-registry*' => Process::result(output: 'configured'),
        '*get configmap plex-commons*' => Process::result(output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]])),
        '*exec*postgres*' => Process::result(output: 'CREATE ROLE'),
        '*get secret vpn-management-store*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-store*' => Process::result(output: 'created'),
        '*get secret vpn-management-secrets*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-secrets*' => Process::result(output: 'created'),
        '*get secret vpn-management-config*' => Process::result(output: vpnManagementConfigFixture('vpn.'.GlobalConfigData::load()->getLocalTld()), exitCode: 0),
        '*get secret vpn-management-oidc*' => Process::result(output: '', exitCode: 1),
        '*create namespace larakube-vpn*' => Process::result(output: 'created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout status deploy/vpn-*' => Process::result(output: 'rollout success'),
        '*rollout status deployment/vpn-client*' => Process::result(output: 'rollout success'),
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*create namespace larakube-shared*' => Process::result(output: 'created'),
        '*port-forward*' => Process::result(),
        '*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        SetupOwnerRequest::class => MockResponse::make(['personal_access_token' => 'nbp_owner']),
        ListUsersRequest::class => MockResponse::make([]),
        CreateServiceUserRequest::class => MockResponse::make(['id' => 'svc-1']),
        CreatePersonalAccessTokenRequest::class => MockResponse::make(['plain_token' => 'nbp_service']),
        ListGroupsRequest::class => MockResponse::make([]),
        CreateGroupRequest::class => MockResponse::make(['id' => 'grp']),
        CreateSetupKeyRequest::class => MockResponse::make(['key' => 'k']),
        // OpenBao speaks Saloon since the transport migration: sys/mounts for
        // the bootstrap probe, then the KV write.
        DynamicNoBodyRequest::class => MockResponse::make(['data' => ['secret/' => ['type' => 'kv']]]),
        DynamicRequest::class => MockResponse::make(['data' => ['value' => 'ok']]),
    ]);

    $this->artisan('vpn:init local')->assertExitCode(0);

    // The service-user token, not the owner's — the one actually stored.
    Saloon::assertSent(fn ($request) => $request instanceof DynamicRequest
        && str_contains($request->resolveEndpoint(), '/VPN_')
        && str_contains($request->resolveEndpoint(), '_PAT'));
});

test('vpn:init recreates the service user and groups after the account was replaced', function (): void {
    // bootstrapVpnAuth() returns early once the credentials Secret exists, so
    // anything it owns is created once per ACCOUNT lifetime — and sso:wire
    // replaces the account under it. Before this was lifted out of that gate, a
    // re-run after the cutover silently skipped both, leaving the documented
    // recovery steps quietly incomplete.
    $kubectl = vpnInitKubectl();

    Process::fake([
        '*get namespace larakube-vpn*' => Process::result(output: ''),
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*get configmap plex-registry*' => Process::result(output: '', exitCode: 1),
        '*configmap plex-registry*' => Process::result(output: 'configured'),
        '*get configmap plex-commons*' => Process::result(output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]])),
        '*exec*postgres*' => Process::result(output: 'CREATE ROLE'),
        '*get secret vpn-management-store*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-store*' => Process::result(output: 'created'),
        // Already bootstrapped, so bootstrapVpnAuth() returns immediately —
        // and the PAT belongs to a HUMAN, the state right after vpn:setup-key
        // adopts one minted by hand in the dashboard.
        '*get secret vpn-management-secrets*' => Process::result(output: base64_encode('nbp_human')),
        '*get secret vpn-management-config*' => Process::result(output: vpnManagementConfigFixture('vpn.'.GlobalConfigData::load()->getLocalTld()), exitCode: 0),
        '*get secret vpn-management-oidc*' => Process::result(output: '', exitCode: 1),
        '*create namespace larakube-vpn*' => Process::result(output: 'created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout status deploy/vpn-*' => Process::result(output: 'rollout success'),
        '*rollout status deployment/vpn-client*' => Process::result(output: 'rollout success'),
        '*patch secret vpn-management-secrets*' => Process::result(output: 'patched'),
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*create namespace larakube-shared*' => Process::result(output: 'created'),
        '*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        ListUsersRequest::class => MockResponse::make([
            ['id' => 'human-1', 'name' => 'James', 'is_service_user' => false, 'is_current' => true],
        ]),
        CreateServiceUserRequest::class => MockResponse::make(['id' => 'svc-new']),
        CreatePersonalAccessTokenRequest::class => MockResponse::make(['plain_token' => 'nbp_service']),
        ListGroupsRequest::class => MockResponse::make([]),
        CreateGroupRequest::class => MockResponse::make(['id' => 'grp']),
    ]);

    $this->artisan('vpn:init local')->assertExitCode(0);

    // The service user is recreated in the NEW account...
    Saloon::assertSent(fn ($request) => $request instanceof CreateServiceUserRequest);
    // ...and both cluster-level groups with it.
    Saloon::assertSent(fn ($request) => $request instanceof CreateGroupRequest);
});

test('vpn:init registers the tool even when the gateway does not settle', function (): void {
    // Registration used to sit AFTER the gateway rollout check, so any run that
    // failed there left VPN unregistered — and the next `vpn:remove --purge`
    // then resolved NO instance, computed the unsuffixed tenant name, and ran
    // DROP DATABASE IF EXISTS against a name that never existed. It reported
    // success while the real database survived untouched. Confirmed live
    // 2026-08-29, twice, and the reason two --purge runs did nothing.
    $kubectl = vpnInitKubectl();

    Process::fake([
        '*get namespace larakube-vpn*' => Process::result(output: ''),
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*get configmap plex-registry*' => Process::result(output: '', exitCode: 1),
        '*configmap plex-registry*' => Process::result(output: 'configured'),
        '*get configmap plex-commons*' => Process::result(output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]])),
        '*exec*postgres*' => Process::result(output: 'CREATE ROLE'),
        '*get secret vpn-management-store*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-store*' => Process::result(output: 'created'),
        '*get secret vpn-management-secrets*' => Process::result(output: base64_encode('nbp_existing')),
        '*get secret vpn-management-config*' => Process::result(output: vpnManagementConfigFixture('vpn.'.GlobalConfigData::load()->getLocalTld()), exitCode: 0),
        '*get secret vpn-management-oidc*' => Process::result(output: '', exitCode: 1),
        '*create namespace larakube-vpn*' => Process::result(output: 'created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout status deploy/vpn-*' => Process::result(output: 'rollout success'),
        // The gateway never settles — the exact failure that hid the tenant.
        '*rollout status deployment/vpn-client*' => Process::result(output: 'timed out', exitCode: 1),
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*create namespace larakube-shared*' => Process::result(output: 'created'),
        '*' => Process::result(output: ''),
    ]);

    Saloon::fake([ListUsersRequest::class => MockResponse::make(['message' => 'nope'], 500)]);

    $this->artisan('vpn:init local')->assertExitCode(1);

    // Registered anyway: a gateway that has not settled is a DEGRADED install,
    // not an absent one, and the registry is what --purge resolves the tenant
    // name from.
    Process::assertRan(fn ($p) => str_contains($p->command, 'larakube-tools-registry')
        && str_contains($p->command, 'create'));
});

test('vpn:init keeps the owner token, which is the only one that can retire the account', function (): void {
    // NetBird permits account deletion to the OWNER only. The larakube-cli
    // service user is an admin and gets 403 — and cannot mint a token for the
    // owner to borrow either (also 403, both confirmed live 2026-08-29). So
    // discarding the owner token after adopting the service one left sso:wire's
    // retire step impossible to perform through the API at all.
    $kubectl = vpnInitKubectl();

    Process::fake([
        '*get namespace larakube-vpn*' => Process::result(output: ''),
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*get configmap plex-registry*' => Process::result(output: '', exitCode: 1),
        '*configmap plex-registry*' => Process::result(output: 'configured'),
        '*get configmap plex-commons*' => Process::result(output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]])),
        '*exec*postgres*' => Process::result(output: 'CREATE ROLE'),
        '*get secret vpn-management-store*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-store*' => Process::result(output: 'created'),
        '*get secret vpn-management-secrets*' => Process::result(output: '', exitCode: 1),
        '*create secret generic vpn-management-secrets*' => Process::result(output: 'created'),
        '*get secret vpn-management-config*' => Process::result(output: vpnManagementConfigFixture('vpn.'.GlobalConfigData::load()->getLocalTld()), exitCode: 0),
        '*get secret vpn-management-oidc*' => Process::result(output: '', exitCode: 1),
        '*create namespace larakube-vpn*' => Process::result(output: 'created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout status deploy/vpn-*' => Process::result(output: 'rollout success'),
        '*rollout status deployment/vpn-client*' => Process::result(output: 'rollout success'),
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*create namespace larakube-shared*' => Process::result(output: 'created'),
        '*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        SetupOwnerRequest::class => MockResponse::make(['personal_access_token' => 'nbp_owner']),
        ListUsersRequest::class => MockResponse::make([]),
        CreateServiceUserRequest::class => MockResponse::make(['id' => 'svc-1']),
        CreatePersonalAccessTokenRequest::class => MockResponse::make(['plain_token' => 'nbp_service']),
        ListGroupsRequest::class => MockResponse::make([]),
        CreateGroupRequest::class => MockResponse::make(['id' => 'grp']),
        CreateSetupKeyRequest::class => MockResponse::make(['key' => 'k']),
    ]);

    $this->artisan('vpn:init local')->assertExitCode(0);

    // Routine work uses the service user's token; the owner's is kept beside it
    // purely for the owner-only operations.
    Process::assertRan(fn ($p) => str_contains($p->command, 'create secret generic vpn-management-secrets')
        && str_contains($p->command, '--from-literal=pat='."'".'nbp_service'."'")
        && str_contains($p->command, '--from-literal=owner-pat='."'".'nbp_owner'."'"));
});
