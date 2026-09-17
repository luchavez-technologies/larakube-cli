<?php

use App\Data\CloudData;
use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\PackageManager;
use App\Http\Integrations\Cloudflare\Requests\GetZoneSettingRequest;
use App\Http\Integrations\Cloudflare\Requests\ListZonesRequest;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * cloud:proxy orange-clouds an environment only when nothing breaks once it
 * is: certificates must renew through DNS, ExternalDNS must own the records,
 * and Cloudflare must verify the origin. cloud:unproxy just reverses it.
 */
function cloudProxyInProject(callable $run, bool $proxied = false): void
{
    $directory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $project = $directory->path('site');
    is_dir($project) || mkdir($project);
    file_put_contents("{$project}/astro.config.mjs", 'export default {};');
    file_put_contents("{$project}/package.json", json_encode(['scripts' => ['dev' => 'astro dev', 'build' => 'astro build']]));

    $config = ConfigData::forStaticSite(AppFramework::ASTRO, 'site', $project, PackageManager::NPM);
    $config->setEnvironments(['local', 'production']);
    $config->getEnvironment('production')->hosts = ['web' => 'site.example.com'];
    $config->setCloud('production', new CloudData(ip: '203.0.113.10', user: 'deploy'));
    $config->setProxied('production', $proxied);
    $config->saveToFile($project);

    Http::fake(['*' => Http::response([], 200)]);
    $previous = getcwd();

    try {
        chdir($project);
        $run($project);
    } finally {
        chdir($previous);
        $directory->delete();
    }
}

function cloudProxyFakes(bool $dnsChallenge = true, string $managedZone = 'example.com'): array
{
    return [
        '*get secret traefik-acme-cloudflare -n traefik -o name*' => Process::result(output: $dnsChallenge ? 'secret/traefik-acme-cloudflare' : ''),
        '*get secret traefik-acme-cloudflare -n traefik -o jsonpath*' => Process::result(output: base64_encode('cf-token-123')),
        '*-l app.kubernetes.io/name=external-dns*' => Process::result(output: (string) json_encode(['items' => [[
            'metadata' => [
                'annotations' => ['larakube.io/dns-domain' => $managedZone, 'larakube.io/dns-owner-id' => 'owner'],
                'labels' => ['larakube.io/dns-zone' => str_replace('.', '-', $managedZone)],
            ],
            'status' => ['readyReplicas' => 1],
        ]]])),
        '*' => Process::result(output: ''),
    ];
}

function cloudProxyCloudflare(?string $sslMode): void
{
    Saloon::fake([
        ListZonesRequest::class => MockResponse::make([
            'success' => true,
            'result' => [['id' => 'zone-1', 'name' => 'example.com']],
            'result_info' => ['total_pages' => 1],
        ]),
        GetZoneSettingRequest::class => $sslMode !== null
            ? MockResponse::make(['success' => true, 'result' => ['id' => 'ssl', 'value' => $sslMode]])
            : MockResponse::make(['success' => false, 'errors' => [['message' => 'Unauthorized']]], 403),
    ]);
}

function cloudProxyIngressAnnotation(string $project): ?string
{
    $manifest = (string) @file_get_contents("{$project}/.infrastructure/k8s/overlays/production/caddy.yaml");

    return preg_match('/external-dns\.alpha\.kubernetes\.io\/cloudflare-proxied: "([^"]+)"/', $manifest, $m) === 1 ? $m[1] : null;
}

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('cloud:proxy proxies the environment and regenerates its ingress when every check passes', function (): void {
    Process::fake(cloudProxyFakes());
    cloudProxyCloudflare('strict');

    cloudProxyInProject(function (string $project): void {
        $this->artisan('cloud:proxy production')
            ->expectsOutputToContain('now set to proxied through Cloudflare')
            ->expectsOutputToContain('larakube cloud:deploy production')
            ->assertExitCode(0);

        expect(ConfigData::loadFromFile($project)->isProxied('production'))->toBeTrue()
            ->and(cloudProxyIngressAnnotation($project))->toBe('true');
    });

    Process::assertNotRan(fn (PendingProcess $process) => str_contains($process->command, 'cf-token-123'));
});

test('cloud:proxy refuses while the cluster still renews through the HTTP challenge', function (): void {
    Process::fake(cloudProxyFakes(dnsChallenge: false));
    cloudProxyCloudflare('strict');

    cloudProxyInProject(function (string $project): void {
        $this->artisan('cloud:proxy production')
            ->expectsOutputToContain('larakube tls:init production')
            ->assertExitCode(1);

        expect(ConfigData::loadFromFile($project)->isProxied('production'))->toBeFalse();
    });
});

test('cloud:proxy refuses hosts no ExternalDNS on the cluster manages', function (): void {
    Process::fake(cloudProxyFakes(managedZone: 'other.org'));
    cloudProxyCloudflare('strict');

    cloudProxyInProject(function (string $project): void {
        $this->artisan('cloud:proxy production')
            ->expectsOutputToContain('site.example.com')
            ->expectsOutputToContain('larakube dns:init production')
            ->assertExitCode(1);

        expect(ConfigData::loadFromFile($project)->isProxied('production'))->toBeFalse();
    });
});

test('cloud:proxy refuses a zone whose SSL mode would send traffic unencrypted', function (): void {
    Process::fake(cloudProxyFakes());
    cloudProxyCloudflare('flexible');

    cloudProxyInProject(function (string $project): void {
        $this->artisan('cloud:proxy production')
            ->expectsOutputToContain('Full (strict)')
            ->assertExitCode(1);

        expect(ConfigData::loadFromFile($project)->isProxied('production'))->toBeFalse();
    });
});

test('cloud:proxy warns but continues when the token can\'t read the SSL mode', function (): void {
    Process::fake(cloudProxyFakes());
    cloudProxyCloudflare(null);

    cloudProxyInProject(function (string $project): void {
        $this->artisan('cloud:proxy production')
            ->expectsOutputToContain('Couldn\'t read example.com\'s SSL mode')
            ->assertExitCode(0);

        expect(ConfigData::loadFromFile($project)->isProxied('production'))->toBeTrue();
    });
});

test('cloud:proxy refuses local and unknown environments', function (string $env): void {
    cloudProxyInProject(function () use ($env): void {
        $this->artisan("cloud:proxy {$env}")
            ->expectsOutputToContain('isn\'t a cloud environment')
            ->assertExitCode(1);
    });
})->with(['local', 'staging']);

test('cloud:unproxy takes the environment back to DNS-only', function (): void {
    Process::fake(['*' => Process::result(output: '')]);

    cloudProxyInProject(function (string $project): void {
        $this->artisan('cloud:unproxy production')
            ->expectsOutputToContain('now set to DNS-only')
            ->assertExitCode(0);

        expect(ConfigData::loadFromFile($project)->isProxied('production'))->toBeFalse()
            ->and(cloudProxyIngressAnnotation($project))->toBeNull();
    }, proxied: true);
});

test('every app ingress carries the proxy annotation for a proxied environment, never locally', function (string $view, AppFramework $framework): void {
    $config = new ConfigData(id: 'demo', name: 'demo', path: '/tmp/demo', framework: $framework);
    $config->setEnvironments(['local', 'production']);
    $config->setProxied('production', true);

    $render = fn (string $environment) => view($view, [
        'config' => $config, 'environment' => $environment, 'namespace' => "demo-{$environment}",
        'resourceName' => 'demo', 'hosts' => ['demo.example.com'], 'selfHosted' => false,
    ])->render();

    expect($render('production'))->toContain('external-dns.alpha.kubernetes.io/cloudflare-proxied: "true"')
        ->and($render('local'))->not->toContain('cloudflare-proxied');
})->with([
    'static site' => ['k8s.static.caddy', AppFramework::ASTRO],
    'Next.js' => ['k8s.nextjs.ingress', AppFramework::NEXTJS],
    'server app' => ['k8s.server.ingress', AppFramework::NESTJS],
]);
