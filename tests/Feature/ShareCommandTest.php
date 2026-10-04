<?php

use App\Data\ConfigData;
use App\Enums\FrontendStack;
use App\Enums\LaravelFeature;
use App\Enums\StorageDriver;
use App\Http\Integrations\Cloudflare\Requests\CreateDnsRecordRequest;
use App\Http\Integrations\Cloudflare\Requests\CreateTunnelRequest;
use App\Http\Integrations\Cloudflare\Requests\DeleteDnsRecordRequest;
use App\Http\Integrations\Cloudflare\Requests\DeleteTunnelRequest;
use App\Http\Integrations\Cloudflare\Requests\GetTunnelTokenRequest;
use App\Http\Integrations\Cloudflare\Requests\ListDnsRecordsRequest;
use App\Http\Integrations\Cloudflare\Requests\ListTunnelsRequest;
use App\Http\Integrations\Cloudflare\Requests\ListZonesRequest;
use App\Http\Integrations\Cloudflare\Requests\PatchDnsRecordRequest;
use App\Http\Integrations\Cloudflare\Requests\PutTunnelConfigurationRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Spatie\TemporaryDirectory\TemporaryDirectory;

const DOMAIN_SHARE_SECRET = 'api-token-do-not-leak';

afterEach(function (): void {
    MockClient::destroyGlobal();
    putenv('CLOUDFLARE_API_TOKEN');
});

/** A stand-in for `up` that records how it was called, so a share can be tested without starting an app. */
function registerUpStub(): void
{
    $GLOBALS['share_up_calls'] = [];

    Artisan::registerCommand(new class extends Illuminate\Console\Command
    {
        protected $signature = 'up {environment=local} {--console} {--no-console} {--no-test} {--build}';

        public function handle(): int
        {
            $GLOBALS['share_up_calls'][] = $this->argument('environment');

            return 0;
        }
    });
}

/** Runs $test inside a project that has a frontend, Reverb and object storage, with a throwaway home folder. */
function inDomainShareProject(callable $test): void
{
    registerUpStub();

    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $project = $dir->path().'/shop';
    File::ensureDirectoryExists($project);
    file_put_contents($project.'/.larakube.json', json_encode((new ConfigData(
        name: 'shop',
        frontend: FrontendStack::REACT,
        features: [LaravelFeature::REVERB],
        objectStorage: StorageDriver::SEAWEEDFS,
    ))->toArray()));

    $home = $dir->path().'/home';
    File::ensureDirectoryExists($home);
    $previousHome = getenv('HOME');
    putenv('HOME='.$home);
    $previous = getcwd();
    chdir($project);

    try {
        $test();
    } finally {
        chdir($previous);
        putenv($previousHome === false ? 'HOME' : 'HOME='.$previousHome);
    }
}

function domainShareJson(): ?array
{
    $lines = preg_split('/\R/', trim(Artisan::output())) ?: [];

    return json_decode((string) end($lines), true);
}

/** @return array<string, MockResponse> */
function cloudflareFor(bool $tunnelExists = false, bool $recordsExist = false): array
{
    return [
        ListZonesRequest::class => MockResponse::make(['success' => true, 'result' => [['id' => 'zone-1', 'name' => 'example.com', 'account' => ['id' => 'acct-1']], ['id' => 'zone-2', 'name' => 'other.dev', 'account' => ['id' => 'acct-1']]], 'result_info' => ['total_pages' => 1]]),
        ListTunnelsRequest::class => MockResponse::make(['success' => true, 'result' => $tunnelExists ? [['id' => 'tun-1']] : []]),
        CreateTunnelRequest::class => MockResponse::make(['success' => true, 'result' => ['id' => 'tun-1']]),
        GetTunnelTokenRequest::class => MockResponse::make(['success' => true, 'result' => 'connector-token-value']),
        PutTunnelConfigurationRequest::class => MockResponse::make(['success' => true, 'result' => []]),
        ListDnsRecordsRequest::class => MockResponse::make(['success' => true, 'result' => $recordsExist ? [['id' => 'rec-1']] : []]),
        CreateDnsRecordRequest::class => MockResponse::make(['success' => true, 'result' => ['id' => 'rec-new']]),
        PatchDnsRecordRequest::class => MockResponse::make(['success' => true, 'result' => ['id' => 'rec-1']]),
        DeleteDnsRecordRequest::class => MockResponse::make(['success' => true, 'result' => []]),
        DeleteTunnelRequest::class => MockResponse::make(['success' => true, 'result' => []]),
    ];
}

/** A cluster that records every command and says the share connector is running. */
function domainShareCluster(?array &$commands, bool $connectorRunning = true): void
{
    $commands = [];

    Process::fake(function ($process) use (&$commands, $connectorRunning) {
        $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $commands[] = $cmd;

        if (str_contains($cmd, 'get deployment larakube-share')) {
            // `-o name` says it exists; the readiness query says a pod is up.
            if (str_contains($cmd, 'readyReplicas')) {
                return Process::result(output: $connectorRunning ? '1' : '');
            }

            return Process::result(output: $connectorRunning ? 'deployment.apps/larakube-share' : '');
        }

        return Process::result(output: '');
    });
}

test('share gives every service a stable name, routes and DNS record, and writes them into the app', function (): void {
    putenv('CLOUDFLARE_API_TOKEN='.DOMAIN_SHARE_SECRET);
    Saloon::fake(cloudflareFor());
    domainShareCluster($commands);

    inDomainShareProject(function () use (&$commands): void {
        $exit = Artisan::call('share', ['--domain' => 'example.com', '--box' => 'box1', '--json' => true, '--no-interaction' => true]);
        $result = domainShareJson();

        expect($exit)->toBe(0)
            ->and($result)->toMatchArray(['success' => true, 'mode' => 'domain'])
            ->and($result['urls'])->toBe([
                'web' => 'https://shop-box1.example.com',
                'hmr' => 'https://vite-shop-box1.example.com',
                'reverb' => 'https://ws-shop-box1.example.com',
                'storage' => 'https://s3-shop-box1.example.com',
                'storage-console' => 'https://s3c-shop-box1.example.com',
            ]);

        Saloon::assertSent(function ($request) {
            if (! $request instanceof PutTunnelConfigurationRequest) {
                return false;
            }

            $ingress = $request->body()->get('config')['ingress'];

            return count($ingress) === 6
                && $ingress[0] === ['hostname' => 'shop-box1.example.com', 'service' => 'http://web:80']
                && $ingress[1]['originRequest'] === ['httpHostHeader' => 'localhost']
                && $ingress[5] === ['service' => 'http_status:404'];
        });

        Saloon::assertSent(fn ($request) => $request instanceof CreateDnsRecordRequest
            && $request->body()->get('content') === 'tun-1.cfargotunnel.com'
            && $request->body()->get('proxied') === true);

        $all = implode("\n", $commands);
        expect($all)->toContain('apply -f')
            ->and($all)->not->toContain(DOMAIN_SHARE_SECRET)
            ->and($all)->not->toContain('connector-token-value');

        // The names are the project's hosts, kept in the local file and out of the committed blueprint, and up ran.
        $local = json_decode((string) file_get_contents(getcwd().'/.larakube.local.json'), true);
        expect($local['environments']['local']['publicHosts'])->toBe([
            'web' => 'shop-box1.example.com',
            'vite' => 'vite-shop-box1.example.com',
            'reverb' => 'ws-shop-box1.example.com',
            's3' => 's3-shop-box1.example.com',
            's3-console' => 's3c-shop-box1.example.com',
        ])
            ->and((string) file_get_contents(getcwd().'/.larakube.json'))->not->toContain('example.com')
            ->and(ConfigData::loadFromFile(getcwd())->getAppUrl('local'))->toBe('https://shop-box1.example.com')
            ->and($GLOBALS['share_up_calls'])->toBe(['local']);
    });
});

test('share run again changes nothing in Cloudflare: the tunnel and the records are reused', function (): void {
    putenv('CLOUDFLARE_API_TOKEN='.DOMAIN_SHARE_SECRET);
    Saloon::fake(cloudflareFor(tunnelExists: true, recordsExist: true));
    domainShareCluster($commands);

    inDomainShareProject(function (): void {
        $exit = Artisan::call('share', ['--domain' => 'example.com', '--box' => 'box1', '--json' => true, '--no-interaction' => true]);

        expect($exit)->toBe(0);

        Saloon::assertNotSent(CreateTunnelRequest::class);
        Saloon::assertNotSent(CreateDnsRecordRequest::class);
        Saloon::assertSent(PatchDnsRecordRequest::class);
    });
});

test('share without a token or without a domain says what to give, and touches nothing', function (): void {
    Saloon::fake(cloudflareFor());
    domainShareCluster($commands);

    inDomainShareProject(function (): void {
        $exit = Artisan::call('share', ['--domain' => 'example.com', '--json' => true, '--no-interaction' => true]);

        expect($exit)->toBe(1)
            ->and(domainShareJson()['error'])->toContain('CLOUDFLARE_API_TOKEN');

        putenv('CLOUDFLARE_API_TOKEN='.DOMAIN_SHARE_SECRET);
        $exit = Artisan::call('share', ['--json' => true, '--no-interaction' => true]);

        expect($exit)->toBe(1)
            ->and(domainShareJson()['error'])->toContain('--domain');

        $exit = Artisan::call('share', ['--domain' => 'nope.example', '--json' => true, '--no-interaction' => true]);

        expect($exit)->toBe(1)
            ->and(domainShareJson()['error'])->toContain('example.com, other.dev');

        Saloon::assertNotSent(PutTunnelConfigurationRequest::class);
    });
});

test('share:domains lists the domains a token can see', function (): void {
    putenv('CLOUDFLARE_API_TOKEN='.DOMAIN_SHARE_SECRET);
    Saloon::fake(cloudflareFor());

    $exit = Artisan::call('share:domains', ['--json' => true, '--no-interaction' => true]);

    expect($exit)->toBe(0)
        ->and(domainShareJson())->toBe(['success' => true, 'domains' => ['example.com', 'other.dev']]);
});

test('share:remove deletes the records and the tunnel and forgets the share', function (): void {
    putenv('CLOUDFLARE_API_TOKEN='.DOMAIN_SHARE_SECRET);
    Saloon::fake(cloudflareFor(tunnelExists: true, recordsExist: true));
    domainShareCluster($commands);

    inDomainShareProject(function () use (&$commands): void {
        Artisan::call('share', ['--domain' => 'example.com', '--box' => 'box1', '--json' => true, '--no-interaction' => true]);

        $exit = Artisan::call('share:remove', ['--force' => true, '--json' => true, '--no-interaction' => true]);

        expect($exit)->toBe(0)
            ->and(domainShareJson())->toBe(['success' => true, 'removed' => true])
            ->and(implode("\n", $commands))->toContain('delete deployment,secret -l larakube.dev/role=share')
            ->and(ConfigData::loadFromFile(getcwd())->getAppUrl('local'))->toBe('https://shop.kube')
            ->and($GLOBALS['share_up_calls'])->toBe(['local', 'local']);

        Saloon::assertSent(DeleteDnsRecordRequest::class);
        Saloon::assertSent(DeleteTunnelRequest::class);

        $again = Artisan::call('share:remove', ['--force' => true, '--json' => true, '--no-interaction' => true]);

        expect($again)->toBe(1)
            ->and(domainShareJson()['error'])->toContain('no public names');
    });
});

test('a name too long for a DNS label is refused before anything is created', function (): void {
    putenv('CLOUDFLARE_API_TOKEN='.DOMAIN_SHARE_SECRET);
    Saloon::fake(cloudflareFor());
    domainShareCluster($commands);

    inDomainShareProject(function (): void {
        $exit = Artisan::call('share', ['--domain' => 'example.com', '--box' => str_repeat('a', 60), '--json' => true, '--no-interaction' => true]);

        expect($exit)->toBe(1)
            ->and(domainShareJson()['error'])->toContain('63 characters');

        Saloon::assertNotSent(CreateTunnelRequest::class);
    });
});

test('the connector gets its token from a Secret, never from its arguments', function (): void {
    $named = view('k8s.cloudflared.deployment', ['name' => 'larakube-share', 'namespace' => 'shop', 'token' => 'abc"def'])->render();

    $documents = array_map(fn (string $doc) => Symfony\Component\Yaml\Yaml::parse($doc), array_filter(array_map('trim', explode("\n---\n", $named))));
    $secret = $documents[0];
    $args = $documents[1]['spec']['template']['spec']['containers'][0]['args'];

    expect($secret['kind'])->toBe('Secret')
        ->and($secret['stringData']['TUNNEL_TOKEN'])->toBe('abc"def')
        ->and($args)->toBe(['tunnel', '--no-autoupdate', 'run'])
        ->and($documents[1]['spec']['template']['spec']['containers'][0]['env'][0]['valueFrom']['secretKeyRef'])->toBe(['name' => 'larakube-share-token', 'key' => 'TUNNEL_TOKEN']);
});

test('share:show reports the stable names a project has and whether the tunnel is running, and says none when it has none', function (): void {
    putenv('CLOUDFLARE_API_TOKEN='.DOMAIN_SHARE_SECRET);
    Saloon::fake(cloudflareFor());
    domainShareCluster($commands);

    inDomainShareProject(function (): void {
        $exit = Artisan::call('share:show', ['environment' => 'local', '--json' => true, '--no-interaction' => true]);

        expect($exit)->toBe(0)
            ->and(domainShareJson())->toBe(['success' => true, 'mode' => 'none', 'zone' => null, 'urls' => [], 'running' => false]);

        Artisan::call('share', ['--domain' => 'example.com', '--box' => 'box1', '--json' => true, '--no-interaction' => true]);
        Artisan::call('share:show', ['environment' => 'local', '--json' => true, '--no-interaction' => true]);
        $shown = domainShareJson();

        expect($shown)->toMatchArray(['success' => true, 'mode' => 'domain', 'zone' => 'example.com', 'running' => true])
            ->and($shown['urls']['web'])->toBe('https://shop-box1.example.com')
            ->and(Artisan::call('share:show', ['environment' => 'production', '--json' => true, '--no-interaction' => true]))->toBe(1)
            ->and(domainShareJson()['error'])->toContain('only for the local environment');
    });
});

test('share:show does not call a connector running when its deployment exists at zero replicas', function (): void {
    putenv('CLOUDFLARE_API_TOKEN='.DOMAIN_SHARE_SECRET);
    Saloon::fake(cloudflareFor());
    domainShareCluster($commands);

    inDomainShareProject(function () use (&$commands): void {
        Artisan::call('share', ['--domain' => 'example.com', '--box' => 'box1', '--json' => true, '--no-interaction' => true]);

        domainShareCluster($commands, connectorRunning: false);
        Artisan::call('share:show', ['environment' => 'local', '--json' => true, '--no-interaction' => true]);

        expect(domainShareJson()['running'])->toBeFalse();
    });
});

test('up keeps a share connector running through its scale-down and restart, and brings a stopped one back', function (): void {
    $source = (string) file_get_contents(base_path('app/Commands/UpCommand.php'));

    expect(substr_count($source, "-l 'larakube-preview!=true,larakube.dev/role!=share'"))->toBe(3)
        ->and($source)->toContain('scale deployment/larakube-share --replicas=1');
});

test('sharing under another domain removes the DNS records of the names it replaces', function (): void {
    putenv('CLOUDFLARE_API_TOKEN='.DOMAIN_SHARE_SECRET);
    Saloon::fake(cloudflareFor(tunnelExists: true, recordsExist: true));
    domainShareCluster($commands);

    inDomainShareProject(function (): void {
        Artisan::call('share', ['--domain' => 'example.com', '--box' => 'box1', '--json' => true, '--no-interaction' => true]);
        Saloon::assertNotSent(DeleteDnsRecordRequest::class);

        Artisan::call('share', ['--domain' => 'other.dev', '--box' => 'box1', '--json' => true, '--no-interaction' => true]);

        Saloon::assertSent(DeleteDnsRecordRequest::class);
        expect(ConfigData::loadFromFile(getcwd())->getAppUrl('local'))->toBe('https://shop-box1.other.dev');
    });
});

test('sharing again under the same domain keeps its DNS records', function (): void {
    putenv('CLOUDFLARE_API_TOKEN='.DOMAIN_SHARE_SECRET);
    Saloon::fake(cloudflareFor(tunnelExists: true, recordsExist: true));
    domainShareCluster($commands);

    inDomainShareProject(function (): void {
        Artisan::call('share', ['--domain' => 'example.com', '--box' => 'box1', '--json' => true, '--no-interaction' => true]);
        Artisan::call('share', ['--domain' => 'example.com', '--box' => 'box1', '--json' => true, '--no-interaction' => true]);

        Saloon::assertNotSent(DeleteDnsRecordRequest::class);
    });
});
