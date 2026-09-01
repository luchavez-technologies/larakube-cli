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

        public function kubectlFor(?string $context): string
        {
            return $this->gitKubectl($context);
        }

        public function installed(string $kubectl, string $ns): bool
        {
            return $this->isGitInstalled($kubectl, $ns);
        }

        public function access(string $env, ?ConfigData $config, ?string $context = null): ?array
        {
            return $this->gitAccess($env, $config, $context);
        }

        public function runPullSecret(string $context, string $namespace): void
        {
            $this->ensureForgejoPullSecret($context, $namespace);
        }

        // Mock getProjectConfigObject
        protected function getProjectConfigObject(string $path): ConfigData
        {
            return ConfigData::from(['name' => 'demo']);
        }

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

test('gitKubectl scopes to a context only when one is given', function (): void {
    $reader = gitReader();
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

    expect($reader->kubectlFor('do-sfo3'))->toBe("{$kubectl} --context=do-sfo3")
        ->and($reader->kubectlFor(''))->toBe($kubectl)
        ->and($reader->kubectlFor(null))->toBe($kubectl);
});

test('isGitInstalled reflects whether the forgejo Deployment exists', function (): void {
    Process::fake(['kubectl get deployment forgejo -n larakube-shared --no-headers' => 'forgejo   1/1   1   1   5d']);
    expect(gitReader()->installed('kubectl', 'larakube-shared'))->toBeTrue();

    Process::fake(['kubectl get deployment forgejo -n larakube-shared --no-headers' => Process::result(output: '', exitCode: 1)]);
    expect(gitReader()->installed('kubectl', 'larakube-shared'))->toBeFalse();
});

test('gitAccess is null when forgejo is not installed, populated when it is', function (): void {
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

    Process::fake(["{$kubectl} get deployment forgejo -n larakube-shared --no-headers" => Process::result(output: '', exitCode: 1)]);
    expect(gitReader()->access('local', null))->toBeNull();

    Process::fake([
        "{$kubectl} get deployment forgejo -n larakube-shared --no-headers" => 'forgejo   1/1   1   1   5d',
    ]);
    $access = gitReader()->access('local', null);

    expect($access['host'])->toStartWith('git.')
        ->and($access['label'])->toBe('Forgejo');
});

test('ensureForgejoPullSecret copies credentials from shared secret to target namespace', function (): void {
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

    Process::fake([
        "{$kubectl} get secret git-secrets -n larakube-shared -o jsonpath='{.data.username}'" => base64_encode('larakube'),
        "{$kubectl} get secret git-secrets -n larakube-shared -o jsonpath='{.data.registry-token}'" => base64_encode('tok123'),
        "{$kubectl} delete secret forgejo-login -n 'demo-production' --ignore-not-found" => Process::result(output: 'deleted'),
        "{$kubectl} create secret docker-registry forgejo-login -n 'demo-production' --docker-server='git.dev.test' --docker-username='larakube' --docker-password='tok123' --docker-email=admin@larakube.local" => Process::result(output: 'created'),
    ]);

    gitReader()->runPullSecret('', 'demo-production');
    Process::assertRan("{$kubectl} get secret git-secrets -n larakube-shared -o jsonpath='{.data.username}'");
    Process::assertRan("{$kubectl} get secret git-secrets -n larakube-shared -o jsonpath='{.data.registry-token}'");
    Process::assertRan("{$kubectl} delete secret forgejo-login -n 'demo-production' --ignore-not-found");
    Process::assertRan("{$kubectl} create secret docker-registry forgejo-login -n 'demo-production' --docker-server='git.dev.test' --docker-username='larakube' --docker-password='tok123' --docker-email=admin@larakube.local");
});
