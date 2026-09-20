<?php

use App\Data\CloudData;
use App\Data\ConfigData;
use App\Data\ResourceRef;
use App\Services\Kubectl;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeKubectl;

test('every call is pinned to ~/.kube/config and the handle\'s context', function (): void {
    Process::fake(['*' => Process::result(output: '')]);

    Kubectl::forContext('larakube-203.0.113.10')->exists(new ResourceRef('Deployment', 'web', 'apps'));

    Process::assertRan(fn (PendingProcess $p) => str_starts_with($p->command, 'KUBECONFIG='.escapeshellarg(home_path('.kube/config'))." kubectl --context 'larakube-203.0.113.10' get deployment/web"));
});

test('an environment resolves to its managed context, or larakube-<ip> for a VPS, and local to the current context', function (): void {
    $config = ConfigData::from(['name' => 'demo']);
    $config->setEnvironments(['local', 'production', 'staging', 'preview']);
    $config->setCloud('production', new CloudData(ip: '203.0.113.10', user: 'deploy'));
    $config->setCloud('staging', new CloudData(context: 'do-sfo3-staging'));

    expect(Kubectl::forEnvironment($config, 'production')->context)->toBe('larakube-203.0.113.10')
        ->and(Kubectl::forEnvironment($config, 'staging')->context)->toBe('do-sfo3-staging')
        ->and(Kubectl::forEnvironment($config, 'local')->context)->toBeNull();
});

test('a cloud environment with no saved cluster refuses instead of falling back to the current context', function (): void {
    $config = ConfigData::from(['name' => 'demo']);
    $config->setEnvironments(['local', 'preview']);

    Kubectl::forEnvironment($config, 'preview');
})->throws(LogicException::class, "'preview' has no saved cluster");

test('secret values travel on stdin, never in argv', function (): void {
    $kube = FakeKubectl::install();

    Kubectl::forContext('ctx')->putSecret('apps', 'web-secrets', ['password' => 's3cr3t value', 'token' => "multi\nline"]);

    Process::assertNotRan(fn (PendingProcess $p) => str_contains($p->command, 's3cr3t'));
    expect($kube->secretValue('apps', 'web-secrets', 'password'))->toBe('s3cr3t value')
        ->and($kube->secretValue('apps', 'web-secrets', 'token'))->toBe("multi\nline");
});

test('secretValue reads back a key, including one with a dot in it', function (): void {
    FakeKubectl::install()->with([
        'kind' => 'Secret',
        'metadata' => ['name' => 'app', 'namespace' => 'apps'],
        'data' => ['registry.json' => base64_encode('[]'), 'password' => base64_encode('pw')],
    ]);

    $kubectl = Kubectl::forContext('ctx');

    expect($kubectl->secretValue('apps', 'app', 'registry.json'))->toBe('[]')
        ->and($kubectl->secretValue('apps', 'app', 'password'))->toBe('pw')
        ->and($kubectl->secretValue('apps', 'app', 'missing'))->toBeNull()
        ->and($kubectl->secretValue('apps', 'nope', 'password'))->toBeNull();
});

test('delete groups resources by namespace and tolerates missing ones', function (): void {
    $kube = FakeKubectl::install()
        ->with(['kind' => 'Deployment', 'metadata' => ['name' => 'web', 'namespace' => 'apps']])
        ->with(['kind' => 'Secret', 'metadata' => ['name' => 'web', 'namespace' => 'apps']])
        ->with(['kind' => 'Secret', 'metadata' => ['name' => 'sso-app-web', 'namespace' => 'sso']]);

    $result = Kubectl::forContext('ctx')->delete(
        new ResourceRef('Deployment', 'web', 'apps'),
        new ResourceRef('Secret', 'web', 'apps'),
        new ResourceRef('Secret', 'sso-app-web', 'sso'),
    );

    expect($result->ok)->toBeTrue()
        ->and($kube->has(new ResourceRef('Deployment', 'web', 'apps')))->toBeFalse()
        ->and($kube->has(new ResourceRef('Secret', 'sso-app-web', 'sso')))->toBeFalse()
        ->and(array_filter($kube->calls(), fn ($args) => $args[0] === 'delete'))->toHaveCount(2)
        ->and(array_filter($kube->calls(), fn ($args) => $args[0] === 'delete'))->each->toContain('--ignore-not-found');
});

test('apply, get, exists and list see the same objects', function (): void {
    FakeKubectl::install();
    $kubectl = Kubectl::forContext('ctx');

    $kubectl->apply(<<<'YAML'
        apiVersion: apps/v1
        kind: Deployment
        metadata:
          name: web
          namespace: apps
          labels:
            larakube-tool: sign
        ---
        apiVersion: apps/v1
        kind: Deployment
        metadata:
          name: worker
          namespace: apps
        YAML);

    $web = new ResourceRef('Deployment', 'web', 'apps');

    expect($kubectl->exists($web))->toBeTrue()
        ->and($kubectl->get($web)['metadata']['name'])->toBe('web')
        ->and($kubectl->exists(new ResourceRef('Deployment', 'nope', 'apps')))->toBeFalse()
        ->and($kubectl->get(new ResourceRef('Deployment', 'nope', 'apps')))->toBeNull()
        ->and(array_column(array_column($kubectl->list('deployment', 'apps', ['larakube-tool' => 'sign']), 'metadata'), 'name'))->toBe(['web'])
        ->and($kubectl->list('deployment', 'apps'))->toHaveCount(2);
});

test('exec passes stdin through and targets the named pod', function (): void {
    $kube = FakeKubectl::install();

    Kubectl::forContext('ctx')->exec('larakube-plex', 'deploy/postgres', ['psql', '-U', 'postgres'], stdin: 'select 1;', container: 'postgres');

    expect($kube->execs())->toBe([[
        'namespace' => 'larakube-plex',
        'target' => 'deploy/postgres',
        'command' => ['psql', '-U', 'postgres'],
        'stdin' => 'select 1;',
    ]]);
});

test('arguments are shell-quoted, so a hostile value can\'t break out', function (): void {
    FakeKubectl::install();

    Kubectl::forContext('ctx')->exists(new ResourceRef('Secret', "x'; rm -rf / #", 'apps'));

    Process::assertRan(fn (PendingProcess $p) => str_contains($p->command, "'secret/x'\\''; rm -rf / #'"));
});

test('only Kubectl builds the ~/.kube/config kubectl prefix', function (): void {
    $copies = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if (! str_ends_with((string) $file, '.php') || str_ends_with((string) $file, 'Services/Kubectl.php')) {
            continue;
        }

        if (preg_match("/escapeshellarg\\(home_path\\('\\.kube\\/config'\\)\\)\\s*\\.\\s*' kubectl'/", (string) file_get_contents((string) $file)) === 1) {
            $copies[] = str_replace(app_path().'/', '', (string) $file);
        }
    }

    expect($copies)->toBeEmpty();
});

test('no command string starts with a bare kubectl: Kubectl names the cluster', function (): void {
    // Error messages that mention kubectl by name, not commands.
    $messages = ['kubectl >= ', 'kubectl apply failed under', 'kubectl (https'];
    $bare = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if (! str_ends_with((string) $file, '.php') || str_ends_with((string) $file, 'Services/Kubectl.php')) {
            continue;
        }

        foreach (token_get_all((string) file_get_contents((string) $file)) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }

            $text = ltrim($token[1], '\'"');
            if (str_starts_with($text, 'kubectl ') && array_filter($messages, fn (string $m) => str_starts_with($text, $m)) === []) {
                $bare[] = str_replace(app_path().'/', '', (string) $file).':'.$token[2];
            }
        }
    }

    expect($bare)->toBeEmpty();
});

test('an explicit kubeconfig, or several merged in order, are each shell-quoted', function (): void {
    expect(Kubectl::forKubeconfig('/tmp/scoped config')->prefix())
        ->toBe("KUBECONFIG='/tmp/scoped config' kubectl")
        ->and(Kubectl::forKubeconfig(['/home/me/.kube/config', '/tmp/new'])->prefix())
        ->toBe("KUBECONFIG='/home/me/.kube/config':'/tmp/new' kubectl")
        ->and(Kubectl::forKubeconfig('/tmp/k3s.yaml', 'k3s-larakube')->prefix())
        ->toBe("KUBECONFIG='/tmp/k3s.yaml' kubectl --context 'k3s-larakube'")
        ->and(Kubectl::current()->prefix())->toBe('kubectl');
});

test('forKubeconfig refuses an empty path list', function (): void {
    Kubectl::forKubeconfig(['', '']);
})->throws(LogicException::class);

test('only Kubectl sets KUBECONFIG for a kubectl command', function (): void {
    $handBuilt = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if (! str_ends_with((string) $file, '.php') || str_ends_with((string) $file, 'Services/Kubectl.php')) {
            continue;
        }

        $source = (string) file_get_contents((string) $file);
        $lines = explode("\n", $source);

        foreach (token_get_all($source) as $token) {
            // The prefix is usually split across strings ('KUBECONFIG='.$path.' kubectl'), so check its line.
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                && str_starts_with(ltrim($token[1], '\'"'), 'KUBECONFIG=') && str_contains($lines[$token[2] - 1], 'kubectl')) {
                $handBuilt[] = str_replace(app_path().'/', '', (string) $file).':'.$token[2];
            }
        }
    }

    expect($handBuilt)->toBeEmpty();
});

test('string-built kubectl commands only ever decrease (KubectlService Stage 4)', function (): void {
    // Lower this as tools move onto typed Kubectl calls; never raise it.
    $ceiling = 643;

    $count = 0;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    foreach ($files as $file) {
        if (! str_ends_with((string) $file, '.php') || str_ends_with((string) $file, 'Services/Kubectl.php')) {
            continue;
        }

        $count += preg_match_all('/\{\$(kubectl|kube|kc)\}|\$(kubectl|kube|kc)\s*\.\s*\' |->prefix\(\)\s*\./', (string) file_get_contents((string) $file));
    }

    expect($count)->toBeLessThanOrEqual($ceiling, "{$count} string-built kubectl commands; new code should use typed Kubectl calls.");
});

test('patchSecret sets keys on stdin and keeps the Secret\'s other keys', function (): void {
    $kube = FakeKubectl::install()->with([
        'kind' => 'Secret',
        'metadata' => ['name' => 'app', 'namespace' => 'apps'],
        'data' => ['keep' => base64_encode('kept'), 'pat' => base64_encode('old')],
    ]);

    $result = Kubectl::forContext('ctx')->patchSecret('apps', 'app', ['pat' => 'n3w-t0ken']);

    expect($result->ok)->toBeTrue()
        ->and($kube->secretValue('apps', 'app', 'pat'))->toBe('n3w-t0ken')
        ->and($kube->secretValue('apps', 'app', 'keep'))->toBe('kept');
    Process::assertNotRan(fn (PendingProcess $p) => str_contains($p->command, 'n3w-t0ken') || str_contains($p->command, base64_encode('n3w-t0ken')));
});

test('patchSecret fails when the Secret does not exist', function (): void {
    FakeKubectl::install();

    expect(Kubectl::forContext('ctx')->patchSecret('apps', 'missing', ['k' => 'v'])->ok)->toBeFalse();
});

test('Secret values never go in argv: no hand-built patch secret or --from-literal', function (): void {
    $offenders = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if (str_ends_with((string) $file, '.php') && ! str_ends_with((string) $file, 'Services/Kubectl.php')
            && preg_match('/patch secret |--from-literal=/', (string) file_get_contents((string) $file)) === 1) {
            $offenders[] = str_replace(app_path().'/', '', (string) $file);
        }
    }

    expect($offenders)->toBeEmpty();
});
