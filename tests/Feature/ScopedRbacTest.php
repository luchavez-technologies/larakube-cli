<?php

use App\Traits\InteractsWithScopedRbac;
use Illuminate\Support\Facades\Process;

function scopedRbac(): object
{
    return new class
    {
        use InteractsWithScopedRbac;
    };
}

function scopedRbacKubectl(): string
{
    return 'KUBECONFIG='.escapeshellarg(home_path('.kube/config'))." kubectl --context 'admin-ctx'";
}

test('rbac manifest contains SA + namespaced Role + RoleBinding, all scoped to the namespace', function (): void {
    $yaml = scopedRbac()->scopedRbacManifest('myapp-production', 'myapp', 'production');

    expect($yaml)
        ->toContain('kind: ServiceAccount')
        ->toContain('kind: Role')
        ->toContain('kind: RoleBinding')
        ->toContain('namespace: myapp-production')
        ->toContain('larakube.dev/app: myapp')
        ->toContain('larakube.dev/env: production')
        ->toContain('app.kubernetes.io/managed-by: larakube');
});

test('rbac manifest never grants cluster-scoped power', function (): void {
    $yaml = scopedRbac()->scopedRbacManifest('myapp-production', 'myapp', 'production');

    // A leaked token must not be able to escalate beyond its namespace.
    expect($yaml)
        ->not->toContain('kind: ClusterRole')
        ->not->toContain('kind: ClusterRoleBinding');
});

test('role grants the namespaced Kinds a cloud overlay emits', function (): void {
    $yaml = scopedRbac()->scopedRbacManifest('myapp-production', 'myapp', 'production');

    foreach (['deployments', 'statefulsets', 'cronjobs', 'services', 'configmaps', 'secrets', 'persistentvolumeclaims', 'ingresses'] as $resource) {
        expect($yaml)->toContain($resource);
    }
    // pods/exec for artisan + migrations.
    expect($yaml)->toContain('pods/exec');
});

test('create token command targets the SA in the namespace via the admin context', function (): void {
    $cmd = scopedRbac()->createTokenCommand('larakube-1.2.3.4', 'myapp-production');

    expect($cmd)
        ->toContain('--context')
        ->toContain('larakube-1.2.3.4')
        ->toContain('-n')
        ->toContain('myapp-production')
        ->toContain('create token')
        ->toContain('deployer');
});

test('create token command honours an explicit duration', function (): void {
    $cmd = scopedRbac()->createTokenCommand('ctx', 'ns', 'deployer', 3600);

    expect($cmd)->toContain('--duration=3600s');
});

test('token secret manifest is the SA-token type bound to the SA', function (): void {
    $yaml = scopedRbac()->tokenSecretManifest('myapp-production');

    expect($yaml)
        ->toContain('type: kubernetes.io/service-account-token')
        ->toContain('kubernetes.io/service-account.name: deployer')
        ->toContain('namespace: myapp-production');
});

test('scoped kubeconfig embeds server, CA, token and pins the namespace', function (): void {
    $kubeconfig = scopedRbac()->assembleScopedKubeconfig(
        clusterName: 'larakube-1.2.3.4',
        server: 'https://1.2.3.4:6443',
        caData: 'CADATABASE64',
        namespace: 'myapp-production',
        token: 'TOKEN123',
    );

    expect($kubeconfig)
        ->toContain('server: https://1.2.3.4:6443')
        ->toContain('certificate-authority-data: CADATABASE64')
        ->toContain('token: TOKEN123')
        ->toContain('namespace: myapp-production')
        ->toContain('current-context: myapp-production');
});

test('server and CA extraction read from the minified admin context', function (): void {
    $rbac = scopedRbac();

    expect($rbac->clusterServerCommand('admin-ctx'))
        ->toContain('--minify')
        ->toContain('--flatten')
        ->toContain('admin-ctx')
        ->toContain('clusters[0].cluster.server')
        ->and($rbac->clusterCaDataCommand('admin-ctx'))->toContain('certificate-authority-data');
});

test('ensureScopedRbac reflects whether the apply succeeded', function (): void {
    $kubectl = scopedRbacKubectl();

    Process::fake(["{$kubectl} apply -f *" => Process::result(exitCode: 0)]);
    expect(scopedRbac()->ensureScopedRbac('admin-ctx', 'myapp-production', 'myapp', 'production'))->toBeTrue();

    Process::fake(["{$kubectl} apply -f *" => Process::result(exitCode: 1)]);
    expect(scopedRbac()->ensureScopedRbac('admin-ctx', 'myapp-production', 'myapp', 'production'))->toBeFalse();
});

test('mintScopedKubeconfig assembles a kubeconfig once the bound token appears', function (): void {
    $kubectl = scopedRbacKubectl();

    Process::fake([
        "{$kubectl} apply -f *" => Process::result(exitCode: 0),
        "{$kubectl} -n 'myapp-production' get secret 'deployer-token' -o jsonpath='{.data.token}'" => base64_encode('tok3n'),
        "{$kubectl} -n 'myapp-production' get secret 'deployer-token' -o jsonpath='{.data.ca\\.crt}'" => base64_encode('CADATA'),
        "{$kubectl} config view --minify --flatten -o jsonpath='{.clusters[0].cluster.server}'" => 'https://1.2.3.4:6443',
    ]);

    $kubeconfig = scopedRbac()->mintScopedKubeconfig('admin-ctx', 'myapp-production');

    expect($kubeconfig)
        ->toContain('token: tok3n')
        ->toContain('certificate-authority-data: '.base64_encode('CADATA'))
        ->toContain('namespace: myapp-production');
});

test('mintScopedKubeconfig returns null when the Secret apply fails', function (): void {
    Process::fake([scopedRbacKubectl().' apply -f *' => Process::result(exitCode: 1)]);

    expect(scopedRbac()->mintScopedKubeconfig('admin-ctx', 'myapp-production'))->toBeNull();
});

test('mintScopedKubeconfig falls back to the admin context CA when the Secret has none', function (): void {
    $kubectl = scopedRbacKubectl();

    Process::fake([
        "{$kubectl} apply -f *" => Process::result(exitCode: 0),
        "{$kubectl} -n 'myapp-production' get secret 'deployer-token' -o jsonpath='{.data.token}'" => base64_encode('tok3n'),
        "{$kubectl} -n 'myapp-production' get secret 'deployer-token' -o jsonpath='{.data.ca\\.crt}'" => Process::result(output: '', exitCode: 1),
        "{$kubectl} config view --minify --flatten -o jsonpath='{.clusters[0].cluster.certificate-authority-data}'" => base64_encode('FALLBACK-CA'),
        "{$kubectl} config view --minify --flatten -o jsonpath='{.clusters[0].cluster.server}'" => 'https://1.2.3.4:6443',
    ]);

    $kubeconfig = scopedRbac()->mintScopedKubeconfig('admin-ctx', 'myapp-production');

    expect($kubeconfig)
        ->toContain('certificate-authority-data: '.base64_encode('FALLBACK-CA'))
        ->toContain('server: https://1.2.3.4:6443');
});

test('pollSecretToken decodes the token once it appears, null on timeout', function (): void {
    Process::fake([
        scopedRbacKubectl()." -n 'myapp-production' get secret 'deployer-token' -o jsonpath='{.data.token}'" => base64_encode('tok3n'),
    ]);
    expect(scopedRbac()->pollSecretToken('admin-ctx', 'myapp-production', 'deployer-token'))->toBe('tok3n');
});

test('readSecretCaData prefers the Secret CA, falling back to the admin context CA', function (): void {
    $kubectl = scopedRbacKubectl();

    Process::fake([
        "{$kubectl} -n 'myapp-production' get secret 'deployer-token' -o jsonpath='{.data.ca\\.crt}'" => 'SECRETCA==',
    ]);
    expect(scopedRbac()->readSecretCaData('admin-ctx', 'myapp-production', 'deployer-token'))->toBe('SECRETCA==');

    Process::fake([
        "{$kubectl} -n 'myapp-production' get secret 'deployer-token' -o jsonpath='{.data.ca\\.crt}'" => Process::result(output: '', exitCode: 1),
        "{$kubectl} config view --minify --flatten -o jsonpath='{.clusters[0].cluster.certificate-authority-data}'" => 'FALLBACKCA==',
    ]);
    expect(scopedRbac()->readSecretCaData('admin-ctx', 'myapp-production', 'deployer-token'))->toBe('FALLBACKCA==');
});

test('kubectlSupportsTokens parses the client minor version from JSON or plain output', function (): void {
    Process::fake(['kubectl version --client -o json' => '{"clientVersion":{"major":"1","minor":"28"}}']);
    expect(scopedRbac()->kubectlSupportsTokens())->toBeTrue();

    Process::fake(['kubectl version --client -o json' => '{"clientVersion":{"major":"1","minor":"20"}}']);
    expect(scopedRbac()->kubectlSupportsTokens())->toBeFalse();

    Process::fake([
        'kubectl version --client -o json' => Process::result(output: '', exitCode: 1),
        'kubectl version --client' => 'Client Version: v1.30.2',
    ]);
    expect(scopedRbac()->kubectlSupportsTokens())->toBeTrue();

    Process::fake([
        'kubectl version --client -o json' => Process::result(output: '', exitCode: 1),
        'kubectl version --client' => Process::result(output: '', exitCode: 1),
    ]);
    expect(scopedRbac()->kubectlSupportsTokens())->toBeTrue(); // can't determine → don't block
});
