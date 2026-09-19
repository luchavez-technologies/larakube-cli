<?php

use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Traits\InteractsWithGitForge;
use Illuminate\Support\Facades\Process;

function gitReader(): object
{
    return new class
    {
        use InteractsWithGitForge;

        public function host(string $env, ?ConfigData $config): ?string
        {
            return $this->resolveGitHostReadOnly($env, $config);
        }

        public function installed(string $kubectl, string $ns, ?string $instance = null): bool
        {
            return $this->isGitInstalled($kubectl, $ns, $instance);
        }

        public function access(string $env, ?ConfigData $config, ?string $context = null): ?array
        {
            return $this->gitAccess($env, $config, $context);
        }

        public function runPullSecret(string $context, string $namespace, string $environment = 'production'): void
        {
            $this->ensureForgejoPullSecret($context, $namespace, $environment);
        }

        // Mock getProjectConfigObject
        protected function getProjectConfigObject(string $path): ConfigData
        {
            $config = ConfigData::from(['name' => 'demo']);
            $config->environments['production'] = EnvironmentData::from([
                'registry' => ['provider' => 'forgejo', 'image' => 'acme/demo', 'host' => 'git.example.com'],
            ]);

            return $config;
        }

        protected function laraKubeWarn(string $message): void {}

        // Mock environmentContextName
        protected function environmentContextName(string $ns): string
        {
            return 'production';
        }
    };
}

test('local Git host uses the git subdomain on the dev TLD', function (): void {
    expect(gitReader()->host('local', null))->toStartWith('git.');
});

test('cloud Git host returns the host persisted for that env', function (): void {
    $config = ConfigData::from(['name' => 'demo']);
    $config->environments['production'] = EnvironmentData::from(['hosts' => ['forgejo' => 'git.example.com']]);

    expect(gitReader()->host('production', $config))->toBe('git.example.com');
});

test('cloud Git host is null when none is configured for the env', function (): void {
    $config = ConfigData::from(['name' => 'demo']);
    $config->environments['production'] = EnvironmentData::from([]);

    expect(gitReader()->host('production', $config))->toBeNull();
});

test('isGitInstalled probes the exact instance Deployment, or the git tool label without one', function (): void {
    Process::fake(['kubectl get deployment git-forgejo-git-example-com -n larakube-shared --no-headers' => 'git-forgejo-git-example-com   1/1   1   1   5d']);
    expect(gitReader()->installed('kubectl', 'larakube-shared', 'git-example-com'))->toBeTrue();

    Process::fake(['kubectl get deployment -l larakube-tool=git -n larakube-shared --no-headers' => Process::result(output: '', exitCode: 0)]);
    expect(gitReader()->installed('kubectl', 'larakube-shared'))->toBeFalse();
});

test('gitAccess probes the Deployment for the env host, and is null when it is absent', function (): void {
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';
    $config = ConfigData::from(['name' => 'demo']);
    $config->environments['production'] = EnvironmentData::from(['hosts' => ['forgejo' => 'git.example.com']]);
    $probe = "{$kubectl} get deployment git-forgejo-git-example-com -n larakube-shared --no-headers";

    Process::fake([$probe => Process::result(output: '', exitCode: 1)]);
    expect(gitReader()->access('production', $config))->toBeNull();

    Process::fake([$probe => 'git-forgejo-git-example-com   1/1   1   1   5d']);
    $access = gitReader()->access('production', $config);

    expect($access['host'])->toBe('git.example.com')
        ->and($access['label'])->toBe('Forgejo');
});

test('ensureForgejoPullSecret copies the registry token git:init minted for the registry host', function (): void {
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';
    $create = "{$kubectl} create secret docker-registry forgejo-login -n 'demo-production' --docker-server='git.example.com' --docker-username='larakube' --docker-password='tok123' --docker-email=admin@larakube.local";

    Process::fake([
        "{$kubectl} get secret git-secrets-git-example-com -n larakube-shared -o jsonpath='{.data.username}'" => base64_encode('larakube'),
        "{$kubectl} get secret git-secrets-git-example-com -n larakube-shared -o jsonpath='{.data.registry-token}'" => base64_encode('tok123'),
        "{$kubectl} delete secret forgejo-login -n 'demo-production' --ignore-not-found" => Process::result(output: 'deleted'),
        $create => Process::result(output: 'created'),
    ]);

    gitReader()->runPullSecret('', 'demo-production');

    Process::assertRan($create);
});

test('ensureForgejoPullSecret skips an environment whose registry has no host, touching nothing', function (): void {
    Process::fake();

    gitReader()->runPullSecret('', 'demo-staging', 'staging');

    Process::assertNothingRan();
});
