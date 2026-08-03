<?php

use App\Data\CloudData;
use App\Data\ConfigData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

function vpnInitKubectl(): string
{
    return 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';
}

test('vpn:init deploys netbird vpn to larakube-vpn', function () {
    $kubectl = vpnInitKubectl();

    Process::fake([
        "{$kubectl} create namespace larakube-vpn*" => Process::result(output: 'namespace/larakube-vpn created'),
        "{$kubectl} apply -f *" => Process::result(output: 'applied'),
        "{$kubectl} rollout status deploy/netbird-management -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deploy/netbird-signal -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deploy/netbird-relay -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deploy/netbird-client -n larakube-vpn*" => Process::result(output: 'rollout success'),
        // Already bootstrapped — vpn:init should skip auth/config setup entirely, no Http calls made.
        "{$kubectl} get secret netbird-admin -n larakube-vpn*" => Process::result(output: 'netbird-admin', exitCode: 0),
        "{$kubectl} get secret netbird-relay-secret -n larakube-vpn*" => Process::result(output: 'netbird-relay-secret', exitCode: 0),
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
        ->expectsOutputToContain('Waiting for NetBird Client...')
        ->expectsOutputToContain('NetBird VPN stack is live.');

    Http::assertNothingSent();
});

test('vpn:init targets the CHOSEN environment\'s own saved context, never the ambient current context', function () {
    $dir = sys_get_temp_dir().'/vpn-init-'.uniqid();
    mkdir($dir, 0755, true);
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
            "{$kubectl} rollout status deploy/netbird-client -n larakube-vpn*" => Process::result(output: 'rollout success'),
            "{$kubectl} get secret netbird-admin -n larakube-vpn*" => Process::result(output: 'netbird-admin', exitCode: 0),
            "{$kubectl} get secret netbird-relay-secret -n larakube-vpn*" => Process::result(output: 'netbird-relay-secret', exitCode: 0),
            '*larakube-tools-registry*' => Process::result(output: ''),
            '*create namespace larakube-shared*' => Process::result(output: 'created'),
        ]);
        Process::preventStrayProcesses();

        $this->artisan('vpn:init production --domain=example.com')
            ->assertExitCode(0)
            ->expectsOutputToContain('NetBird VPN stack is live.');

        Http::assertNothingSent();
    } finally {
        chdir($original);
        exec('rm -rf '.escapeshellarg($dir));
    }
});

test('vpn:remove removes netbird vpn namespace when --remove is passed', function () {
    Process::fake([
        vpnInitKubectl().' delete namespace larakube-vpn*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('vpn:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing NetBird VPN namespace...')
        ->expectsOutputToContain('removed from larakube-vpn');
});

test('vpn:init bootstraps NetBird auth non-interactively on first run', function () {
    $kubectl = vpnInitKubectl();

    Process::fake([
        "{$kubectl} create namespace larakube-vpn*" => Process::result(output: 'namespace/larakube-vpn created'),
        "{$kubectl} apply -f *" => Process::result(output: 'applied'),
        "{$kubectl} rollout status deploy/netbird-management -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deploy/netbird-signal -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deploy/netbird-relay -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deploy/netbird-client -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} get secret netbird-admin -n larakube-vpn*" => Process::result(output: '', exitCode: 1),
        "{$kubectl} create secret generic netbird-admin*" => Process::result(output: 'secret/netbird-admin created'),
        "{$kubectl} get secret netbird-relay-secret -n larakube-vpn*" => Process::result(output: 'netbird-relay-secret', exitCode: 0),
    ]);
    Http::fake([
        'https://vpn.kube/api/setup' => Http::response(['personal_access_token' => 'nbp_test_token']),
        'https://vpn.kube/api/setup-keys' => Http::response(['key' => 'nb_setup_key_test']),
    ]);

    $this->artisan('vpn:init local')->assertExitCode(0);

    Http::assertSent(fn ($request) => $request->url() === 'https://vpn.kube/api/setup'
        && $request['create_pat'] === true);
    Http::assertSent(fn ($request) => $request->url() === 'https://vpn.kube/api/setup-keys'
        && $request->hasHeader('Authorization', 'Token nbp_test_token'));
});

test('vpn:init warns but does not fail when NetBird auth bootstrap fails', function () {
    $kubectl = vpnInitKubectl();

    Process::fake([
        "{$kubectl} create namespace larakube-vpn*" => Process::result(output: 'namespace/larakube-vpn created'),
        "{$kubectl} apply -f *" => Process::result(output: 'applied'),
        "{$kubectl} rollout status deploy/netbird-management -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deploy/netbird-signal -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deploy/netbird-relay -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deploy/netbird-client -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} get secret netbird-admin -n larakube-vpn*" => Process::result(output: '', exitCode: 1),
        "{$kubectl} get secret netbird-relay-secret -n larakube-vpn*" => Process::result(output: 'netbird-relay-secret', exitCode: 0),
    ]);
    Http::fake([
        'https://vpn.kube/api/setup' => Http::response(status: 500),
    ]);

    $this->artisan('vpn:init local')
        ->assertExitCode(0)
        ->expectsOutputToContain('Could not bootstrap NetBird auth automatically');
});

test('vpn:remove also targets the CHOSEN environment\'s own saved context', function () {
    $dir = sys_get_temp_dir().'/vpn-init-remove-'.uniqid();
    mkdir($dir, 0755, true);
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
        exec('rm -rf '.escapeshellarg($dir));
    }
});

test('vpn:init generates the relay secret + management.json on first run', function () {
    $kubectl = vpnInitKubectl();

    Process::fake([
        "{$kubectl} create namespace larakube-vpn*" => Process::result(output: 'namespace/larakube-vpn created'),
        "{$kubectl} apply -f *" => Process::result(output: 'applied'),
        "{$kubectl} rollout status deploy/netbird-management -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deploy/netbird-signal -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deploy/netbird-relay -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} rollout status deploy/netbird-client -n larakube-vpn*" => Process::result(output: 'rollout success'),
        "{$kubectl} get secret netbird-admin -n larakube-vpn*" => Process::result(output: 'netbird-admin', exitCode: 0),
        "{$kubectl} get secret netbird-relay-secret -n larakube-vpn*" => Process::result(output: '', exitCode: 1),
        "{$kubectl} create secret generic netbird-relay-secret*" => Process::result(output: 'secret/netbird-relay-secret created'),
    ]);

    $this->artisan('vpn:init local')->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'kubectl create secret generic netbird-relay-secret')
        && str_contains($process->command, '--from-literal=relay-secret=')
        && str_contains($process->command, '--from-file=management.json='));
});
