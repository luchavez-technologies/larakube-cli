<?php

use App\Traits\InteractsWithIngressProxy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

test('link:init deploys Kutt using the Commons postgres and redis', function () {
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
            ],
        ]),
        '*get configmap plex-registry*' => Process::result(output: '', exitCode: 1),
        '*create configmap plex-registry*' => Process::result(output: 'configmap created'),
        '*get secret link-secrets*' => Process::result(output: '', exitCode: 1),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('link:init local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Kutt manifests...')
        ->expectsOutputToContain('Kutt shortener stack is live.');
});

test('link manifest pins Kutt to the Commons postgres client and enables redis', function () {
    $manifest = view('k8s.link.shared', [
        'host' => 'link.example.test',
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 3,
        'vpnOnly' => false,
        'isLocal' => true,
    ])->render();

    expect($manifest)
        ->toContain('name: DB_CLIENT')
        ->toContain('value: "pg"')
        ->toContain('name: REDIS_ENABLED')
        ->toContain('value: "true"');
});

test('link manifest declares MAIL_SECURE as a literal, not valueFrom, so a future kubectl apply never conflicts with mail:wire', function () {
    // Regression guard: mail:wire sets MAIL_SECURE via a plain literal
    // `kubectl set env NAME=value`, never through the link-smtp Secret.
    // Declaring it here as valueFrom made a later link:init re-run fail —
    // kubectl apply's merge re-adds valueFrom on top of the live literal
    // value mail:wire already set, and the two are mutually exclusive
    // (the exact bug confirmed live on Documenso, 2026-08-05).
    $manifest = view('k8s.link.shared', [
        'host' => 'link.example.test',
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 3,
        'vpnOnly' => false,
        'isLocal' => true,
    ])->render();

    preg_match('/- name: MAIL_SECURE\s*\n\s*(value|valueFrom):\s*"?([^"\n]*)"?/', $manifest, $m);

    expect($m[1] ?? null)->toBe('value')
        ->and(trim($m[2] ?? '', '"'))->toBe('true');
});

test('link ingress proxies through Cloudflare on cloud deploys when proxied, so Kutt receives the cf-ipcountry header', function () {
    $cloud = view('k8s.link.shared', [
        'host' => 'link.luchtech.dev',
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 3,
        'vpnOnly' => false,
        'isLocal' => false,
        'proxied' => true,
    ])->render();

    $cloudDnsOnly = view('k8s.link.shared', [
        'host' => 'link.luchtech.dev',
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 3,
        'vpnOnly' => false,
        'isLocal' => false,
        'proxied' => false,
    ])->render();

    $local = view('k8s.link.shared', [
        'host' => 'link.test',
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 3,
        'vpnOnly' => false,
        'isLocal' => true,
        'proxied' => true,
    ])->render();

    expect($cloud)->toContain('external-dns.alpha.kubernetes.io/cloudflare-proxied: "true"');
    expect($cloudDnsOnly)->not->toContain('cloudflare-proxied');
    expect($local)->not->toContain('cloudflare-proxied');
});

test('resolveProxied honors the --proxied flag value and always yields false on local', function (mixed $raw, bool $expected) {
    $cmd = new class($raw) extends Command
    {
        use InteractsWithIngressProxy;

        protected $signature = 'trait-test';

        public function __construct(private readonly mixed $rawValue)
        {
            parent::__construct();
        }

        public function option($key = null): mixed
        {
            return $this->rawValue;
        }
    };

    expect($cmd->resolveProxied(isLocal: false))->toBe($expected);
    expect($cmd->resolveProxied(isLocal: true))->toBeFalse();
})->with([
    'default flag off' => [null, false],
    'explicit on' => [true, true],
    'string one' => ['1', true],
    'explicit off' => [false, false],
    'string zero' => ['0', false],
    'truthy words' => ['yes', true],
]);

test('link:init --vpn-only refuses — LINK is public infrastructure with no VPN mode', function () {
    $this->artisan('link:init local --vpn-only --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain("'link' doesn't have a --vpn-only ingress mode.");
});

test('link:init --vpn-only aborts without touching kubectl', function () {
    Process::fake([
        '*get secret link-secrets*' => Process::result(output: '', exitCode: 1),
        '*apply -f *' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('link:init local --vpn-only --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain("'link' doesn't have a --vpn-only ingress mode.");
});
