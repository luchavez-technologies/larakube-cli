<?php

use App\Data\CloudData;
use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Http\Integrations\Netbird\Requests\CreateSetupKeyRequest;
use App\Http\Integrations\Netbird\Requests\SetupOwnerRequest;
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
        "{$kubectl} create namespace larakube-vpn*" => Process::result(output: 'namespace/larakube-vpn created'),
        "{$kubectl} apply -f *" => Process::result(output: 'applied'),
        "{$kubectl} rollout status deploy/netbird-management -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deploy/netbird-signal -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deploy/netbird-relay -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deployment/netbird-client -n *" => Process::result(output: 'rollout success'),
        // Already bootstrapped — vpn:init should skip auth/config setup entirely, no Http calls made.
        "{$kubectl} get secret vpn-secrets -n larakube-vpn*" => Process::result(output: 'vpn-secrets', exitCode: 0),
        "{$kubectl} get secret netbird-relay-secret -n larakube-vpn*" => Process::result(output: vpnManagementConfigFixture('vpn.'.GlobalConfigData::load()->getLocalTld()), exitCode: 0),
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
            "{$kubectl} create namespace larakube-vpn*" => Process::result(output: 'namespace/larakube-vpn created'),
            "{$kubectl} apply -f *" => Process::result(output: 'applied'),
            "{$kubectl} rollout status deploy/netbird-management -n larakube-vpn*" => Process::result(output: 'rollout success'),
            "{$kubectl} rollout status deploy/netbird-signal -n larakube-vpn*" => Process::result(output: 'rollout success'),
            "{$kubectl} rollout status deploy/netbird-relay -n larakube-vpn*" => Process::result(output: 'rollout success'),
            "{$kubectl} rollout status deployment/netbird-client -n *" => Process::result(output: 'rollout success'),
            "{$kubectl} get secret vpn-secrets -n larakube-vpn*" => Process::result(output: 'vpn-secrets', exitCode: 0),
            "{$kubectl} get secret netbird-relay-secret -n larakube-vpn*" => Process::result(output: vpnManagementConfigFixture('vpn.example.com'), exitCode: 0),
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
        "{$kubectl} create namespace larakube-vpn*" => Process::result(output: 'namespace/larakube-vpn created'),
        "{$kubectl} apply -f *" => Process::result(output: 'applied'),
        "{$kubectl} rollout status deploy/netbird-management -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deploy/netbird-signal -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deploy/netbird-relay -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deployment/netbird-client -n *" => Process::result(output: 'rollout success'),
        "{$kubectl} get secret vpn-secrets -n larakube-vpn*" => Process::result(output: '', exitCode: 1),
        "{$kubectl} create secret generic vpn-secrets*" => Process::result(output: 'secret/vpn-secrets created'),
        "{$kubectl} get secret netbird-relay-secret -n larakube-vpn*" => Process::result(output: vpnManagementConfigFixture('vpn.'.GlobalConfigData::load()->getLocalTld()), exitCode: 0),
    ]);
    Saloon::fake([
        SetupOwnerRequest::class => MockResponse::make(['personal_access_token' => 'nbp_test_token']),
        CreateSetupKeyRequest::class => MockResponse::make(['key' => 'nb_setup_key_test']),
    ]);

    $this->artisan('vpn:init local')->assertExitCode(0);

    Saloon::assertSent(fn ($request) => $request instanceof SetupOwnerRequest
        && $request->body()->get('create_pat') === true);
    Saloon::assertSent(fn ($request, $response) => $request instanceof CreateSetupKeyRequest
        && $response->getPendingRequest()->headers()->get('Authorization') === 'Token nbp_test_token');
});

test('vpn:init warns but does not fail when NetBird auth bootstrap fails', function (): void {
    $kubectl = vpnInitKubectl();

    Process::fake([
        "{$kubectl} create namespace larakube-vpn*" => Process::result(output: 'namespace/larakube-vpn created'),
        "{$kubectl} apply -f *" => Process::result(output: 'applied'),
        "{$kubectl} rollout status deploy/netbird-management -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deploy/netbird-signal -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deploy/netbird-relay -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deployment/netbird-client -n *" => Process::result(output: 'rollout success'),
        "{$kubectl} get secret vpn-secrets -n larakube-vpn*" => Process::result(output: '', exitCode: 1),
        "{$kubectl} get secret netbird-relay-secret -n larakube-vpn*" => Process::result(output: vpnManagementConfigFixture('vpn.'.GlobalConfigData::load()->getLocalTld()), exitCode: 0),
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
        "{$kubectl} create namespace larakube-vpn*" => Process::result(output: 'namespace/larakube-vpn created'),
        "{$kubectl} apply -f *" => Process::result(output: 'applied'),
        "{$kubectl} rollout status deploy/netbird-management -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout restart deployment/netbird-management -n larakube-vpn*" => Process::result(output: 'restarted'),
        "{$kubectl} rollout status deploy/netbird-signal -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deploy/netbird-relay -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deployment/netbird-client -n *" => Process::result(output: 'rollout success'),
        "{$kubectl} get secret vpn-secrets -n larakube-vpn*" => Process::result(output: 'vpn-secrets', exitCode: 0),
        "{$kubectl} get secret netbird-relay-secret -n larakube-vpn*" => Process::result(output: base64_encode($staleConfig), exitCode: 0),
        "{$kubectl} create secret generic netbird-relay-secret*" => Process::result(output: 'secret/netbird-relay-secret configured'),
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*create namespace larakube-shared*' => Process::result(output: 'created'),
    ]);

    $this->artisan('vpn:init local')
        ->assertExitCode(0)
        ->expectsOutputToContain('Restarting NetBird Management to pick up config changes...');

    // The real secrets survive unchanged into the re-rendered config.
    Process::assertRan(fn ($process) => str_contains($process->command, 'kubectl create secret generic netbird-relay-secret')
        && str_contains($process->command, '--from-literal=relay-secret='.escapeshellarg('preserved-relay-secret')));
    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/netbird-management'));
});

test('vpn:init does NOT restart management when the re-rendered config is byte-identical to what is already deployed', function (): void {
    $kubectl = vpnInitKubectl();
    $host = 'vpn.'.GlobalConfigData::load()->getLocalTld();

    Process::fake([
        "{$kubectl} create namespace larakube-vpn*" => Process::result(output: 'namespace/larakube-vpn created'),
        "{$kubectl} apply -f *" => Process::result(output: 'applied'),
        "{$kubectl} rollout status deploy/netbird-management -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deploy/netbird-signal -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deploy/netbird-relay -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deployment/netbird-client -n *" => Process::result(output: 'rollout success'),
        "{$kubectl} get secret vpn-secrets -n larakube-vpn*" => Process::result(output: 'vpn-secrets', exitCode: 0),
        "{$kubectl} get secret netbird-relay-secret -n larakube-vpn*" => Process::result(output: vpnManagementConfigFixture($host, 'preserved-relay-secret', 'preserved-encryption-key'), exitCode: 0),
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*create namespace larakube-shared*' => Process::result(output: 'created'),
    ]);

    $this->artisan('vpn:init local')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('Restarting NetBird Management to pick up config changes...');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'kubectl create secret generic netbird-relay-secret'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/netbird-management'));
});

test('vpn:init generates the relay secret + management.json on first run', function (): void {
    $kubectl = vpnInitKubectl();

    Process::fake([
        "{$kubectl} create namespace larakube-vpn*" => Process::result(output: 'namespace/larakube-vpn created'),
        "{$kubectl} apply -f *" => Process::result(output: 'applied'),
        "{$kubectl} rollout status deploy/netbird-management -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deploy/netbird-signal -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deploy/netbird-relay -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deployment/netbird-client -n *" => Process::result(output: 'rollout success'),
        "{$kubectl} get secret vpn-secrets -n larakube-vpn*" => Process::result(output: 'vpn-secrets', exitCode: 0),
        "{$kubectl} get secret netbird-relay-secret -n larakube-vpn*" => Process::result(output: '', exitCode: 1),
        "{$kubectl} create secret generic netbird-relay-secret*" => Process::result(output: 'secret/netbird-relay-secret created'),
    ]);

    $this->artisan('vpn:init local')->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'kubectl create secret generic netbird-relay-secret')
        && str_contains($process->command, '--from-literal=relay-secret=')
        && str_contains($process->command, '--from-file=management.json='));
});
