<?php

use App\Exceptions\MissingFlagException;
use App\Http\Integrations\Cloudflare\Requests\CreateDnsRecordRequest;
use App\Http\Integrations\Cloudflare\Requests\DeleteDnsRecordRequest;
use App\Http\Integrations\Cloudflare\Requests\ListZonesRequest;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/**
 * tls:init moves Traefik's Let's Encrypt resolver to the Cloudflare DNS
 * challenge. Everything that could leave a cluster unable to renew is checked
 * before Traefik is touched.
 */
function tlsInitIngresses(array $hosts, array $annotations = []): string
{
    return (string) json_encode(['items' => array_map(fn (string $host) => [
        'metadata' => [
            'namespace' => 'apps',
            'name' => str_replace('.', '-', $host),
            'annotations' => ['traefik.ingress.kubernetes.io/router.tls.certresolver' => 'letsencrypt'] + $annotations,
        ],
        'spec' => ['rules' => [['host' => $host]]],
    ], $hosts)]);
}

/**
 * @param  array<string, string>  $storedTokens  dns:init groups => token
 */
function tlsInitFakes(array $storedTokens, array $hosts, array &$captured, array $overrides = []): array
{
    $captured = ['token' => null, 'manifest' => null];

    // Overrides first: Process::fake matches in order, and a key given here
    // replaces the default of the same name.
    return $overrides + [
        '*get deployment -n traefik traefik' => Process::result(),
        '*get pvc traefik-acme*' => Process::result(output: ''),
        '*get secret -n larakube-shared -o name*' => Process::result(
            output: implode("\n", array_map(fn ($g) => "secret/cloudflare-token-{$g}", array_keys($storedTokens))),
        ),
        ...collect($storedTokens)->mapWithKeys(fn ($token, $group) => [
            "*get secret cloudflare-token-{$group} -n larakube-shared*" => Process::result(output: base64_encode($token)),
        ])->all(),
        '*get ingress -A -o json*' => Process::result(output: tlsInitIngresses($hosts)),
        '*create secret generic traefik-acme-cloudflare*' => function (PendingProcess $process) use (&$captured) {
            $captured['token'] = $process->input;

            return Process::result(output: 'secret/traefik-acme-cloudflare configured');
        },
        '*get deployment traefik -n traefik -o jsonpath*' => Process::result(
            output: '["--certificatesresolvers.letsencrypt.acme.email=ops@example.com","--providers.kubernetesingress.ingressendpoint.ip=203.0.113.10"]',
        ),
        '*apply -f *traefik-cloud.yaml*' => function (PendingProcess $process) use (&$captured) {
            preg_match("/apply -f '([^']+)'/", $process->command, $m);
            $captured['manifest'] = file_get_contents($m[1]);

            return Process::result(output: 'applied');
        },
        '*' => Process::result(output: ''),
    ];
}

function tlsInitCloudflare(bool $canWrite = true): void
{
    Saloon::fake([
        ListZonesRequest::class => MockResponse::make([
            'success' => true,
            'result' => [['id' => 'zone-1', 'name' => 'example.com'], ['id' => 'zone-2', 'name' => 'example.org']],
            'result_info' => ['total_pages' => 1],
        ]),
        CreateDnsRecordRequest::class => $canWrite
            ? MockResponse::make(['success' => true, 'result' => ['id' => 'record-1']])
            : MockResponse::make(['success' => false, 'errors' => [['message' => 'Authentication error']]], 403),
        DeleteDnsRecordRequest::class => MockResponse::make(['success' => true, 'result' => ['id' => 'record-1']]),
    ]);
}

afterEach(function (): void {
    MockClient::destroyGlobal();
    putenv('LARAKUBE_CLOUDFLARE_TOKEN');
});

test('tls:init refuses local clusters', function (): void {
    $this->artisan('tls:init local')
        ->expectsOutputToContain('LaraKube Local CA')
        ->assertExitCode(1);
});

test('tls:init reuses the dns:init token and renders the DNS challenge with the cluster\'s own ACME email', function (): void {
    $captured = [];
    Process::fake(tlsInitFakes(['example-com' => 'cf-token-123'], ['app.example.com', 'example.org'], $captured, [
        '*get secret traefik-acme-cloudflare -n traefik -o name*' => Process::result(output: 'secret/traefik-acme-cloudflare'),
    ]));
    tlsInitCloudflare();

    $this->artisan('tls:init production --context=ctx --force --no-interaction')
        ->expectsOutputToContain('now uses the Cloudflare DNS challenge')
        ->assertExitCode(0);

    expect($captured['token'])->toBe('cf-token-123')
        ->and($captured['manifest'])
        ->toContain('dnschallenge.provider=cloudflare')
        ->toContain('acme.email=ops@example.com')
        ->toContain('name: traefik-acme-cloudflare')
        ->not->toContain('httpchallenge');

    // The token is piped on stdin; it never appears in a command line.
    Process::assertNotRan(fn (PendingProcess $process) => str_contains($process->command, 'cf-token-123'));

    // Both zones the hosts live in were write-checked, and the check record removed.
    $sent = collect(MockClient::global()->getRecordedResponses())->map(fn ($response) => $response->getRequest()::class)->countBy();
    expect($sent[CreateDnsRecordRequest::class] ?? 0)->toBe(2)
        ->and($sent[DeleteDnsRecordRequest::class] ?? 0)->toBe(2);
});

test('tls:init works without environment positional when targeted with --context', function (): void {
    $captured = [];
    Process::fake(tlsInitFakes(['example-com' => 'cf-token-123'], ['app.example.com', 'example.org'], $captured, [
        '*get secret traefik-acme-cloudflare -n traefik -o name*' => Process::result(output: 'secret/traefik-acme-cloudflare'),
    ]));
    tlsInitCloudflare();

    $this->artisan('tls:init --context=larakube-34.27.253.31 --force --no-interaction')
        ->expectsOutputToContain('now uses the Cloudflare DNS challenge')
        ->assertExitCode(0);

    expect($captured['token'])->toBe('cf-token-123');
});

test('tls:init works interactively without arguments by prompting for context', function (): void {
    $captured = [];
    Process::fake(array_merge(
        [
            '*config get-contexts -o name*' => Process::result(output: "larakube-34.27.253.31\n"),
            '*config current-context*' => Process::result(output: "larakube-34.27.253.31\n"),
            '*get secret traefik-acme-cloudflare -n traefik -o name*' => Process::result(output: 'secret/traefik-acme-cloudflare'),
        ],
        tlsInitFakes(['example-com' => 'cf-token-123'], ['app.example.com', 'example.org'], $captured),
    ));
    tlsInitCloudflare();

    $this->artisan('tls:init', ['--force' => true])
        ->expectsQuestion('Which Kubernetes context would you like to target for TLS?', 'larakube-34.27.253.31')
        ->expectsOutputToContain('now uses the Cloudflare DNS challenge')
        ->assertExitCode(0);

    expect($captured['token'])->toBe('cf-token-123');
});

test('a host outside the token\'s zones stops tls:init before anything is written', function (): void {
    $captured = [];
    Process::fake(tlsInitFakes(['example-com' => 'cf-token-123'], ['app.example.com', 'shop.elsewhere.net'], $captured));
    tlsInitCloudflare();

    $this->artisan('tls:init production --context=ctx --force --no-interaction')
        ->expectsOutputToContain('outside every zone this token can see')
        ->expectsOutputToContain('shop.elsewhere.net')
        ->assertExitCode(1);

    expect($captured['token'])->toBeNull()
        ->and($captured['manifest'])->toBeNull();
    Saloon::assertNotSent(CreateDnsRecordRequest::class);
});

test('a token that can read but not write DNS stops tls:init before Traefik is touched', function (): void {
    $captured = [];
    Process::fake(tlsInitFakes(['example-com' => 'cf-token-123'], ['app.example.com'], $captured));
    tlsInitCloudflare(canWrite: false);

    $this->artisan('tls:init production --context=ctx --force --no-interaction')
        ->expectsOutputToContain('not write its DNS records')
        ->assertExitCode(1);

    expect($captured['token'])->toBeNull()
        ->and($captured['manifest'])->toBeNull();
});

test('without dns:init, a non-interactive run takes the token from LARAKUBE_CLOUDFLARE_TOKEN', function (): void {
    putenv('LARAKUBE_CLOUDFLARE_TOKEN=env-token-456');
    $captured = [];
    Process::fake(tlsInitFakes([], ['app.example.com'], $captured));
    tlsInitCloudflare();

    $this->artisan('tls:init production --context=ctx --force --no-interaction')->assertExitCode(0);

    expect($captured['token'])->toBe('env-token-456');
});

test('without dns:init or LARAKUBE_CLOUDFLARE_TOKEN, a non-interactive run fails and names the variable', function (): void {
    $captured = [];
    Process::fake(tlsInitFakes([], ['app.example.com'], $captured));
    tlsInitCloudflare();

    $this->artisan('tls:init production --context=ctx --force --no-interaction')
        ->expectsOutputToContain('Set LARAKUBE_CLOUDFLARE_TOKEN')
        ->assertExitCode(1);

    Saloon::assertNotSent(ListZonesRequest::class);
});

test('several dns:init tokens need --group= when running non-interactively', function (): void {
    $captured = [];
    Process::fake(tlsInitFakes(['example-com' => 'token-a', 'example-org' => 'token-b'], ['app.example.com'], $captured));
    tlsInitCloudflare();

    $this->artisan('tls:init production --context=ctx --force --no-interaction')->run();
})->throws(MissingFlagException::class, 'Missing required --group');

test('--group= picks that dns:init group\'s token', function (): void {
    $captured = [];
    Process::fake(tlsInitFakes(['example-com' => 'token-a', 'example-org' => 'token-b'], ['app.example.com'], $captured));
    tlsInitCloudflare();

    $this->artisan('tls:init production --context=ctx --group=example-org --force --no-interaction')->assertExitCode(0);

    expect($captured['token'])->toBe('token-b');
});

test('tls:init refuses managed clusters for now', function (): void {
    $captured = [];
    Process::fake(tlsInitFakes(['example-com' => 'cf-token-123'], ['app.example.com'], $captured, [
        '*get pvc traefik-acme*' => Process::result(output: 'persistentvolumeclaim/traefik-acme'),
    ]));

    $this->artisan('tls:init production --context=ctx --force --no-interaction')
        ->expectsOutputToContain('single-node (VPS) clusters')
        ->assertExitCode(1);

    expect($captured['manifest'])->toBeNull();
});
