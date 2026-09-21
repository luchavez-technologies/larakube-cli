<?php

use App\Http\Integrations\Cloudflare\Requests\GetZoneSettingRequest;
use App\Http\Integrations\Cloudflare\Requests\ListZonesRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\Support\FakeToolRegistry;

/**
 * tool:proxy / tool:unproxy switch one instance's proxy by annotating its
 * Ingress and remember the choice in the registry. $annotations collects the
 * annotate commands the fake cluster receives.
 */
function toolProxyCluster(?array &$annotations, bool $vpnOnly = false, bool $dnsChallenge = true): void
{
    $annotations = [];
    Process::fake(function ($process) use (&$annotations, $vpnOnly, $dnsChallenge) {
        $cmd = (string) $process->command;

        return match (true) {
            str_contains($cmd, ' annotate ingress ') => (function () use (&$annotations, $cmd) {
                $annotations[] = $cmd;

                return Process::result(output: 'annotated');
            })(),
            str_contains($cmd, 'get ingress -n larakube-shared -o json') => Process::result(output: (string) json_encode(['items' => [[
                'metadata' => ['name' => 'n8n-flow-example-com', 'namespace' => 'larakube-shared'],
                'spec' => ['rules' => [['host' => 'flow.example.com']]],
            ]]])),
            str_contains($cmd, 'get ingress/n8n-flow-example-com') => Process::result(output: (string) json_encode([
                'metadata' => ['name' => 'n8n-flow-example-com', 'annotations' => $vpnOnly
                    ? ['traefik.ingress.kubernetes.io/router.middlewares' => 'larakube-shared-flow-vpn-only-flow-example-com@kubernetescrd']
                    : []],
            ])),
            str_contains($cmd, 'get secret traefik-acme-cloudflare -n traefik -o name') => Process::result(output: $dnsChallenge ? 'secret/traefik-acme-cloudflare' : ''),
            str_contains($cmd, 'get secret traefik-acme-cloudflare -n traefik -o jsonpath') => Process::result(output: base64_encode('cf-token')),
            str_contains($cmd, 'app.kubernetes.io/name=external-dns') => Process::result(output: (string) json_encode(['items' => [[
                'metadata' => ['annotations' => ['larakube.io/dns-domain' => 'example.com', 'larakube.io/dns-owner-id' => 'o'], 'labels' => ['larakube.io/dns-zone' => 'example-com']],
                'status' => ['readyReplicas' => 1],
            ]]])),
            default => Process::result(output: ''),
        };
    });
    Saloon::fake([
        ListZonesRequest::class => MockResponse::make(['success' => true, 'result' => [['id' => 'z1', 'name' => 'example.com']], 'result_info' => ['total_pages' => 1]]),
        GetZoneSettingRequest::class => MockResponse::make(['success' => true, 'result' => ['id' => 'ssl', 'value' => 'strict']]),
    ]);
}

function toolProxyRegistry(?bool $proxied = null): FakeToolRegistry
{
    return FakeToolRegistry::install([array_filter([
        'tool' => 'flow', 'instance' => 'flow-example-com', 'host' => 'flow.example.com', 'engine' => 'n8n', 'proxied' => $proxied,
    ], fn ($value) => $value !== null)]);
}

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('tool:proxy annotates the instance\'s Ingress and remembers it', function (): void {
    toolProxyCluster($annotations);
    $registry = toolProxyRegistry();

    $this->artisan('tool:proxy production --domain=flow.example.com --context=ctx')->assertExitCode(0);

    expect($annotations)->toHaveCount(1)
        ->and($annotations[0])->toContain('annotate ingress n8n-flow-example-com -n larakube-shared external-dns.alpha.kubernetes.io/cloudflare-proxied=true --overwrite')
        ->and($registry->entryForHost(App\Enums\ClusterTool::FLOW, 'flow.example.com')['proxied'])->toBeTrue();
});

test('tool:unproxy removes the annotation and remembers DNS-only', function (): void {
    toolProxyCluster($annotations);
    $registry = toolProxyRegistry(proxied: true);

    $this->artisan('tool:unproxy production --domain=flow.example.com --context=ctx')->assertExitCode(0);

    expect($annotations[0])->toContain('external-dns.alpha.kubernetes.io/cloudflare-proxied- --overwrite')
        ->and($registry->entryForHost(App\Enums\ClusterTool::FLOW, 'flow.example.com')['proxied'])->toBeFalse();
});

test('tool:proxy runs the same checks as --proxied and changes nothing when they fail', function (): void {
    toolProxyCluster($annotations, dnsChallenge: false);
    $registry = toolProxyRegistry();

    $this->artisan('tool:proxy production --domain=flow.example.com --context=ctx')->assertExitCode(1);

    expect($annotations)->toBe([])
        ->and($registry->writes)->toBeEmpty();
});

test('a VPN-only instance is never proxied', function (): void {
    toolProxyCluster($annotations, vpnOnly: true);
    toolProxyRegistry();

    $this->artisan('tool:proxy production --domain=flow.example.com --context=ctx')->assertExitCode(1);

    expect($annotations)->toBe([]);
});

test('an unknown host names the registered ones', function (): void {
    toolProxyCluster($annotations);
    toolProxyRegistry();

    $this->artisan('tool:proxy production --domain=typo.example.com --context=ctx')->assertExitCode(1);

    expect($annotations)->toBe([]);
});

test('re-running {tool}:init without --proxied keeps the remembered choice', function (): void {
    toolProxyCluster($annotations);
    toolProxyRegistry(proxied: true);

    $command = app(App\Commands\Flow\FlowInitCommand::class);
    $input = new Symfony\Component\Console\Input\ArrayInput([], $command->getDefinition());
    $command->setInput($input);
    $command->setOutput(new Illuminate\Console\OutputStyle($input, new Symfony\Component\Console\Output\BufferedOutput));

    (new ReflectionMethod($command, 'guardRequestedProxy'))->invoke($command, App\Enums\ClusterTool::FLOW, 'production', 'kubectl', 'flow.example.com');

    expect($command->resolveProxied(false))->toBeTrue();
});
