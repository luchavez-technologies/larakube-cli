<?php

use App\Commands\Flow\FlowInitCommand;
use App\Enums\ClusterTool;
use App\Http\Integrations\Cloudflare\Requests\GetZoneSettingRequest;
use App\Http\Integrations\Cloudflare\Requests\ListZonesRequest;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `{tool}:init --proxied` gets cloud:proxy's checks before anything deploys,
 * and tools that can't sit behind Cloudflare refuse it. Exercised through
 * flow:init, which has both --proxied and --vpn-only like most tools.
 */
function proxyGuardCommand(array $options, string $class = FlowInitCommand::class): object
{
    $command = app($class);
    $input = new ArrayInput($options, $command->getDefinition());
    $command->setInput($input);
    $command->setOutput(new OutputStyle($input, new BufferedOutput));

    return $command;
}

function proxyGuardCluster(bool $dnsChallenge = true, string $sslMode = 'strict'): void
{
    Process::fake([
        '*get secret traefik-acme-cloudflare -n traefik -o name*' => Process::result(output: $dnsChallenge ? 'secret/traefik-acme-cloudflare' : ''),
        '*get secret traefik-acme-cloudflare -n traefik -o jsonpath*' => Process::result(output: base64_encode('cf-token-123')),
        '*-l app.kubernetes.io/name=external-dns*' => Process::result(output: (string) json_encode(['items' => [[
            'metadata' => [
                'annotations' => ['larakube.io/dns-domain' => 'example.com', 'larakube.io/dns-owner-id' => 'owner'],
                'labels' => ['larakube.io/dns-zone' => 'example-com'],
            ],
            'status' => ['readyReplicas' => 1],
        ]]])),
        '*' => Process::result(output: ''),
    ]);
    Saloon::fake([
        ListZonesRequest::class => MockResponse::make(['success' => true, 'result' => [['id' => 'zone-1', 'name' => 'example.com']], 'result_info' => ['total_pages' => 1]]),
        GetZoneSettingRequest::class => MockResponse::make(['success' => true, 'result' => ['id' => 'ssl', 'value' => $sslMode]]),
    ]);
}

function guardProxy(object $command, string $host, ClusterTool $tool = ClusterTool::FLOW): void
{
    (new ReflectionMethod($command, 'guardRequestedProxy'))->invoke($command, $tool, 'production', 'kubectl', $host);
}

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('a proxiable host on a ready cluster passes', function (): void {
    proxyGuardCluster();
    $command = proxyGuardCommand(['--proxied' => true]);

    guardProxy($command, 'flow.example.com');

    expect($command->resolveProxied(false))->toBeTrue();
});

test('tools that can\'t sit behind Cloudflare refuse --proxied', function (ClusterTool $tool): void {
    proxyGuardCluster();

    guardProxy(proxyGuardCommand(['--proxied' => true]), 'x.example.com', $tool);
})->with([ClusterTool::GIT, ClusterTool::MAIL, ClusterTool::VPN, ClusterTool::MEET])
    ->throws(RuntimeException::class, 'Not proxying x.example.com');

test('--vpn-only with --proxied is refused', function (): void {
    proxyGuardCluster();

    guardProxy(proxyGuardCommand(['--proxied' => true, '--vpn-only' => true]), 'flow.example.com');
})->throws(RuntimeException::class, 'A VPN-only host gains nothing');

test('an explicit --proxied stops on a cluster that renews through the HTTP challenge', function (): void {
    proxyGuardCluster(dnsChallenge: false);

    guardProxy(proxyGuardCommand(['--proxied' => true]), 'flow.example.com');
})->throws(RuntimeException::class, 'fix the above');

test('a host two levels below its zone is refused (the free edge certificate covers *.zone only)', function (): void {
    proxyGuardCluster();

    guardProxy(proxyGuardCommand(['--proxied' => true]), 'flow.team.example.com');
})->throws(RuntimeException::class, 'fix the above');

test('a Flexible SSL zone is refused, since it sends traffic to the server unencrypted', function (): void {
    proxyGuardCluster(sslMode: 'flexible');

    guardProxy(proxyGuardCommand(['--proxied' => true]), 'flow.example.com');
})->throws(RuntimeException::class, 'fix the above');

test('local installs are never checked or proxied', function (): void {
    Process::fake(['*' => Process::result(output: '')]);
    $command = proxyGuardCommand(['--proxied' => true]);

    (new ReflectionMethod($command, 'guardRequestedProxy'))->invoke($command, ClusterTool::GIT, 'local', 'kubectl', 'git.test');

    expect($command->resolveProxied(true))->toBeFalse();
    Process::assertNothingRan();
});

test('a tool whose proxy is on by default falls back to DNS-only instead of failing', function (): void {
    proxyGuardCluster(dnsChallenge: false);
    // No --proxied on the command line: Link's default of 1 applies.
    $command = proxyGuardCommand([], App\Commands\Link\LinkInitCommand::class);

    guardProxy($command, 'link.example.com', ClusterTool::LINK);

    expect($command->resolveProxied(false))->toBeFalse();
});
