<?php

use App\Enums\AppFramework;
use App\Enums\WorkspaceRuntime;
use App\Services\Workspace\WorkspaceSpec;
use Illuminate\Support\Facades\Process;

/**
 * Fakes a cluster. $existing seeds namespaces labelled as workspaces; $seen collects
 * the manifests and Secrets the command applied and every command line it ran.
 *
 * @param  list<string>  $existing
 * @param  array{manifest: ?string, secret: ?array, commands: list<string>}|null  $seen
 */
function fakeWorkspaceCluster(?array &$seen, array $existing = [], array $secret = [], bool $imagePresent = true): void
{
    $seen = ['manifest' => null, 'secret' => null, 'commands' => [], 'dockerfile' => null];

    Process::fake(function ($process) use (&$seen, $existing, $secret, $imagePresent) {
        $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $seen['commands'][] = $cmd;

        if (str_contains($cmd, ' apply -f -')) {
            $input = (string) $process->input;
            $object = json_decode($input, true);
            if (($object['kind'] ?? null) === 'Secret') {
                $seen['secret'] = $object;
            } else {
                $seen['manifest'] = $input;
            }

            return Process::result(output: 'configured');
        }

        if (str_contains($cmd, 'ssh-keygen')) {
            $file = $process->command[array_search('-f', $process->command, true) + 1];
            file_put_contents($file, 'PRIVATE-KEY');
            file_put_contents($file.'.pub', 'ssh-ed25519 AAAA workspace');

            return Process::result();
        }

        if (str_contains($cmd, 'get namespace')) {
            $wanted = preg_match('/larakube-workspace=([a-z0-9-]+)/', $cmd, $match) === 1 ? [$match[1]] : null;
            $names = $wanted === null ? $existing : array_values(array_intersect($existing, $wanted));

            return Process::result(output: json_encode(['items' => array_map(fn (string $name): array => [
                'metadata' => ['labels' => ['larakube-workspace' => $name], 'annotations' => [
                    'larakube.dev/workspace-repo' => '"https://github.com/acme/app"',
                    'larakube.dev/workspace-branch' => '"main"',
                    'larakube.dev/workspace-size' => '"standard"',
                ]],
            ], $names)]));
        }

        if (str_contains($cmd, 'get deployment workspace')) {
            return Process::result(output: json_encode(['spec' => ['replicas' => 1], 'status' => ['readyReplicas' => 1]]));
        }

        if (preg_match('#get secret workspace .*jsonpath=.\{\.data\.([a-z.\\\\-]+)\}#', $cmd, $m) === 1) {
            $key = str_replace('\\', '', $m[1]);

            return Process::result(output: isset($secret[$key]) ? base64_encode($secret[$key]) : '');
        }

        if (str_contains($cmd, ' images -q')) {
            return Process::result(output: $imagePresent ? 'sha256:abc' : '');
        }

        if (preg_match('/ build .* -f \'?([^\' ]+Dockerfile)/', $cmd, $m) === 1) {
            $seen['dockerfile'] = is_file($m[1]) ? file_get_contents($m[1]) : false;

            return Process::result();
        }

        if (str_contains($cmd, 'k3s ctr images ls')) {
            return Process::result(output: $imagePresent ? WorkspaceSpec::image(WorkspaceRuntime::PHP, '8.4') : '');
        }

        return Process::result(output: 'ok');
    });
}

function workspaceCreate(array $extra = []): Illuminate\Testing\PendingCommand
{
    return test()->artisan('workspace:create', $extra + [
        '--context' => 'orbstack',
        '--name' => 'api',
        '--repo' => 'https://github.com/acme/app',
        '--no-interaction' => true,
    ]);
}

test('the manifest keeps the workspace away from the cluster API and bounded in size', function (): void {
    $manifest = (new WorkspaceSpec)->manifest(['name' => 'api', 'repo' => 'https://github.com/acme/app', 'branch' => 'feature/x', 'size' => 'small', 'gitName' => 'Dev', 'gitEmail' => 'dev@example.com', 'framework' => AppFramework::LARAVEL, 'runtime' => WorkspaceRuntime::PHP, 'runtimeVersion' => '8.4']);

    expect($manifest)->toContain('name: ws-api')
        ->toContain('automountServiceAccountToken: false')
        ->toContain('kind: NetworkPolicy')
        ->toContain('kind: ResourceQuota')
        ->toContain('limits.memory: 2Gi')
        ->toContain('value: "feature/x"')
        ->toContain('image: '.WorkspaceSpec::image(WorkspaceRuntime::PHP, '8.4'))
        // Egress is limited to name lookups and git/package traffic on 22, 80 and 443.
        ->not->toContain('port: 6443');
});

test('a repository on a self-hosted git server opens only the SSH port its URL names', function (): void {
    $spec = new WorkspaceSpec;
    $base = ['name' => 'api', 'branch' => 'main', 'size' => 'small', 'gitName' => 'Dev', 'gitEmail' => 'dev@example.com', 'framework' => AppFramework::LARAVEL, 'runtime' => WorkspaceRuntime::PHP, 'runtimeVersion' => '8.4'];

    expect(WorkspaceSpec::validRepo('ssh://git@git.example.com:2222/acme/app.git'))->toBeTrue()
        ->and(WorkspaceSpec::gitPort('ssh://git@git.example.com:2222/acme/app.git'))->toBe(2222)
        ->and(WorkspaceSpec::gitPort('https://github.com/acme/app'))->toBeNull()
        ->and($spec->manifest($base + ['repo' => 'ssh://git@git.example.com:2222/acme/app.git']))->toContain('port: 2222')
        ->and($spec->manifest($base + ['repo' => 'https://github.com/acme/app']))->not->toContain('2222');
});

test('workspace:create applies the manifest and keeps a secret with a password and a deploy key', function (): void {
    fakeWorkspaceCluster($seen);

    workspaceCreate()->expectsOutputToContain("Workspace 'api' is running")->assertExitCode(0);

    expect($seen['manifest'])->toContain('name: ws-api')
        ->and($seen['secret']['metadata']['name'])->toBe('workspace')
        ->and(base64_decode($seen['secret']['data']['deploy-key.pub']))->toBe('ssh-ed25519 AAAA workspace')
        ->and(base64_decode($seen['secret']['data']['password']))->toHaveLength(24);
});

test('workspace:create builds the image from a real Dockerfile when the cluster does not have it', function (): void {
    fakeWorkspaceCluster($seen, imagePresent: false);

    workspaceCreate()->assertExitCode(0);

    expect($seen['dockerfile'])->toBeString()->toContain('code-server');
});

test('workspace:create run again keeps the password and the deploy key that are already in the cluster', function (): void {
    fakeWorkspaceCluster($seen, secret: ['password' => 'kept-password', 'deploy-key' => 'KEPT-PRIVATE', 'deploy-key.pub' => 'ssh-ed25519 KEPT']);

    workspaceCreate()->assertExitCode(0);

    expect(base64_decode($seen['secret']['data']['password']))->toBe('kept-password')
        ->and(base64_decode($seen['secret']['data']['deploy-key']))->toBe('KEPT-PRIVATE');
    Process::assertNotRan(fn ($process): bool => is_array($process->command) && in_array('ssh-keygen', $process->command, true));
});

test('workspace:create refuses a bad name, repository, branch or size without touching the cluster', function (array $flags, string $message): void {
    fakeWorkspaceCluster($seen);

    workspaceCreate($flags)->expectsOutputToContain($message)->assertExitCode(1);

    expect($seen['manifest'])->toBeNull();
})->with([
    'name' => [['--name' => 'Bad_Name'], 'lowercase letters'],
    'repo' => [['--repo' => 'ftp://example.com/x'], 'not a repository URL'],
    'branch' => [['--branch' => 'a..b'], 'not a usable branch'],
    'size' => [['--size' => 'huge'], "Unknown size 'huge'"],
]);

test('workspace:create needs to be told which server when it cannot ask', function (): void {
    fakeWorkspaceCluster($seen);

    expect(fn () => $this->artisan('workspace:create', ['--name' => 'api', '--repo' => 'https://github.com/acme/app', '--no-interaction' => true])->run())
        ->toThrow(App\Exceptions\MissingFlagException::class)
        ->and($seen['manifest'])->toBeNull();
});

test('workspace:list reports each workspace with its status as JSON and withholds the password', function (): void {
    fakeWorkspaceCluster($seen, ['api'], ['password' => 'secret-pw', 'deploy-key.pub' => 'ssh-ed25519 KEPT']);

    $this->artisan('workspace:list', ['--context' => 'orbstack', '--json' => true, '--no-interaction' => true])
        ->expectsOutputToContain('"status":"running"')
        ->doesntExpectOutputToContain('secret-pw')
        ->assertExitCode(0);
});

test('workspace:suspend scales the workspace to zero and workspace:resume brings it back', function (): void {
    fakeWorkspaceCluster($seen, ['api']);

    $this->artisan('workspace:suspend', ['--context' => 'orbstack', '--name' => 'api', '--no-interaction' => true])->assertExitCode(0);
    $this->artisan('workspace:resume', ['--context' => 'orbstack', '--name' => 'api', '--no-interaction' => true])->assertExitCode(0);

    expect(implode("\n", $seen['commands']))->toContain('scale deployment/workspace -n ws-api --replicas=0')
        ->toContain('scale deployment/workspace -n ws-api --replicas=1');
});

test('workspace:remove deletes only a namespace that is a workspace', function (): void {
    fakeWorkspaceCluster($seen, ['api']);

    $this->artisan('workspace:remove', ['--context' => 'orbstack', '--name' => 'api', '--force' => true, '--no-interaction' => true])->assertExitCode(0);
    $this->artisan('workspace:remove', ['--context' => 'orbstack', '--name' => 'other', '--force' => true, '--no-interaction' => true])->assertExitCode(1);

    $deletes = array_values(array_filter($seen['commands'], fn (string $c): bool => str_contains($c, ' delete namespace')));

    expect($deletes)->toHaveCount(1)->and($deletes[0])->toContain('ws-api');
});

test('workspace:options lists the sizes for a GUI to draw', function (): void {
    $this->artisan('workspace:options', ['--json' => true])
        ->expectsOutputToContain('"defaultSize":"standard"')
        ->assertExitCode(0);
});

test('every framework has a runtime, a dev command and dev ports that never use the editor\'s port', function (AppFramework $framework): void {
    expect($framework->workspaceRuntime())->toBeInstanceOf(WorkspaceRuntime::class)
        ->and($framework->devCommand())->not->toBeEmpty()
        ->and($framework->devPorts())->not->toBeEmpty()
        ->and(array_column($framework->devPorts(), 'port'))->not->toContain(8080);
})->with(AppFramework::cases());

test('every runtime offers versions, a default among them, and a base image', function (WorkspaceRuntime $runtime): void {
    expect($runtime->versions())->not->toBeEmpty()
        ->and($runtime->versions())->toContain($runtime->defaultVersion())
        ->and($runtime->baseImage($runtime->defaultVersion()))->toContain($runtime->defaultVersion());
})->with(WorkspaceRuntime::cases());

test('the PHP workspace builds on the Server Side Up image the deployed app uses', function (): void {
    $dockerfile = (new WorkspaceSpec)->dockerfile(WorkspaceRuntime::PHP, '8.3');

    expect($dockerfile)->toContain('FROM docker.io/serversideup/php:8.3-cli')
        ->toContain('install-php-extensions')
        ->toContain('nodesource')
        ->toContain('code-server');
});

test('a runtime that ships its own Node or has no PHP gets neither', function (): void {
    $dockerfile = (new WorkspaceSpec)->dockerfile(WorkspaceRuntime::PYTHON, '3.13');

    expect($dockerfile)->toContain('FROM docker.io/library/python:3.13-bookworm')
        ->not->toContain('nodesource')
        ->not->toContain('install-php-extensions');
});

test('the manifest exposes the framework\'s dev ports on the pod and the Service', function (): void {
    $manifest = (new WorkspaceSpec)->manifest(['name' => 'api', 'repo' => 'https://github.com/acme/app', 'branch' => 'main', 'size' => 'small', 'gitName' => 'Dev', 'gitEmail' => 'dev@example.com', 'framework' => AppFramework::NEXTJS, 'runtime' => WorkspaceRuntime::NODE, 'runtimeVersion' => '24']);

    expect($manifest)->toContain('containerPort: 3000')
        ->toContain('name: dev-3000')
        ->toContain('image: '.WorkspaceSpec::image(WorkspaceRuntime::NODE, '24'));
});

test('workspace:create builds the image for the framework\'s runtime and refuses what is not offered', function (): void {
    fakeWorkspaceCluster($seen, imagePresent: false);

    workspaceCreate(['--framework' => 'django'])->assertExitCode(0);

    expect($seen['dockerfile'])->toContain('FROM docker.io/library/python:3.13-bookworm')
        ->and($seen['manifest'])->toContain('larakube.dev/workspace-runtime: "python"');

    workspaceCreate(['--framework' => 'nope'])->expectsOutputToContain("Unknown framework 'nope'")->assertExitCode(1);
    workspaceCreate(['--runtime-version' => '5.6'])->expectsOutputToContain('is not offered')->assertExitCode(1);
});

test('workspace:options lists runtimes and frameworks with their dev commands', function (): void {
    Illuminate\Support\Facades\Artisan::call('workspace:options', ['--json' => true]);

    $options = json_decode(trim(Illuminate\Support\Facades\Artisan::output()), true);
    $laravel = collect($options['frameworks'])->firstWhere('value', 'laravel');

    expect($laravel['runtime'])->toBe('php')
        ->and($laravel['devCommand'])->toBe('composer run dev')
        ->and(collect($options['runtimes'])->firstWhere('value', 'python')['versions'])->toContain('3.13');
});

test('workspace:images lists every runtime and version with its base, and the pinned code-server', function (): void {
    Illuminate\Support\Facades\Artisan::call('workspace:images', ['--json' => true]);

    $result = json_decode(trim(Illuminate\Support\Facades\Artisan::output()), true);
    $php84 = collect($result['images'])->first(fn (array $i): bool => $i['runtime'] === 'php' && $i['version'] === '8.4');

    expect($result['codeServer'])->toBe(WorkspaceSpec::CODE_SERVER_VERSION)
        ->and($php84['base'])->toBe('docker.io/serversideup/php:8.4-cli')
        ->and(count($result['images']))->toBe(array_sum(array_map(fn (WorkspaceRuntime $r): int => count($r->versions()), WorkspaceRuntime::cases())));
});

test('workspace:dockerfile prints the recipe the images are built from, and refuses what is not offered', function (): void {
    Illuminate\Support\Facades\Artisan::call('workspace:dockerfile', ['--runtime' => 'node', '--runtime-version' => '24']);

    expect(Illuminate\Support\Facades\Artisan::output())->toContain('FROM docker.io/library/node:24-bookworm');

    $this->artisan('workspace:dockerfile', ['--runtime' => 'node', '--runtime-version' => '3'])->expectsOutputToContain('is not offered')->assertExitCode(1);
    $this->artisan('workspace:dockerfile', ['--runtime' => 'cobol'])->expectsOutputToContain('Choose a --runtime')->assertExitCode(1);
});
