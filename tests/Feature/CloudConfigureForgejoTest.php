<?php

use App\Data\CloudData;
use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Traits\ConfiguresCloudEnvironment;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

function cloudConfigureForgejoRunner(string $gitRemote = ''): object
{
    return new class($gitRemote)
    {
        use ConfiguresCloudEnvironment, GeneratesProjectInfrastructure, InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

        public function __construct(private string $gitRemote = '') {}

        public function detect(): string
        {
            return $this->detectCiPlatform();
        }

        public function parse(string $remote): ?array
        {
            return $this->parseGitRemote($remote);
        }

        public function hasLogin(string $tea, string $host): bool
        {
            return $this->teaHasLogin($tea, $host);
        }

        public function setSecret(string $tea, string $name, string $value, string $slug, string $login): void
        {
            $this->setTeaSecret($tea, $name, $value, $slug, $login);
        }

        public function credentials(ConfigData $config, string $environment, string $host): ?array
        {
            return $this->forgejoRegistryCredentials($config, $environment, $host);
        }

        public function info($string, $verbosity = null): void {}

        protected function gitRemoteUrl(): string
        {
            return $this->gitRemote;
        }
    };
}

test('a Forgejo remote is detected as forgejo, and GitHub and GitLab keep their own platforms', function (): void {
    expect(cloudConfigureForgejoRunner('git@git.example.com:acme/portal.git')->detect())->toBe('forgejo')
        ->and(cloudConfigureForgejoRunner('git@github.com:acme/portal.git')->detect())->toBe('github')
        ->and(cloudConfigureForgejoRunner('git@gitlab.com:acme/portal.git')->detect())->toBe('gitlab');
});

test('git remotes parse to a host and owner/repo for scp-like SSH, ssh:// with a port, and HTTPS', function (): void {
    $runner = cloudConfigureForgejoRunner();

    expect($runner->parse('git@git.example.com:acme/portal.git'))->toBe(['host' => 'git.example.com', 'slug' => 'acme/portal'])
        ->and($runner->parse('ssh://git@git.example.com:2222/acme/portal.git'))->toBe(['host' => 'git.example.com', 'slug' => 'acme/portal'])
        ->and($runner->parse('https://git.example.com/acme/portal.git'))->toBe(['host' => 'git.example.com', 'slug' => 'acme/portal'])
        ->and($runner->parse('https://github.com/Acme/Portal'))->toBe(['host' => 'github.com', 'slug' => 'Acme/Portal'])
        ->and($runner->parse('not a remote'))->toBeNull();
});

test('a tea login is found by name or by URL host', function (): void {
    Process::fake(['tea logins list --output json' => json_encode([
        ['name' => 'work', 'url' => 'https://git.example.com'],
    ])]);

    expect(cloudConfigureForgejoRunner()->hasLogin('tea ', 'git.example.com'))->toBeTrue()
        ->and(cloudConfigureForgejoRunner()->hasLogin('tea ', 'git.other.test'))->toBeFalse();
});

test('Forgejo secrets are piped to tea on stdin, never placed in its arguments', function (): void {
    $piped = null;

    Process::fake(['*tea actions secrets create*' => function (PendingProcess $process) use (&$piped) {
        preg_match("/^cat '([^']+)'/", $process->command, $m);
        $piped = file_get_contents($m[1]);

        return Process::result(output: 'created');
    }]);

    cloudConfigureForgejoRunner()->setSecret('tea ', 'PRODUCTION_KUBECONFIG', 'super-secret', 'acme/portal', 'git.example.com');

    Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, "| tea actions secrets create 'PRODUCTION_KUBECONFIG' --stdin --repo 'acme/portal' --login 'git.example.com'")
        && ! str_contains($process->command, 'super-secret'));
    expect($piped)->toBe('super-secret');
});

test('CI registry credentials come from the git-secrets Secret of the Forgejo serving the registry host', function (): void {
    $config = ConfigData::from(['name' => 'portal']);
    $config->environments['production'] = EnvironmentData::from([]);
    $config->environments['production']->cloud = new CloudData(context: 'prod-cluster');

    Process::fake([
        "*get secret git-secrets-git-example-com -n larakube-shared -o jsonpath='{.data.username}'" => base64_encode('larakube'),
        "*get secret git-secrets-git-example-com -n larakube-shared -o jsonpath='{.data.registry-token}'" => base64_encode('tok-123'),
    ]);

    expect(cloudConfigureForgejoRunner()->credentials($config, 'production', 'git.example.com'))
        ->toBe(['username' => 'larakube', 'token' => 'tok-123']);
});

test('a registry host with no LaraKube-managed Forgejo behind it yields no credentials', function (): void {
    $config = ConfigData::from(['name' => 'portal']);
    $config->environments['production'] = EnvironmentData::from([]);
    $config->environments['production']->cloud = new CloudData(context: 'prod-cluster');

    Process::fake(['*get secret git-secrets-*' => '']);

    expect(cloudConfigureForgejoRunner()->credentials($config, 'production', 'git.example.com'))->toBeNull();
});
