<?php

use App\Enums\ClusterTool;
use App\Traits\InteractsWithIngressProxy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

test('tool:init --tool=kutt deploys Kutt using the Commons postgres and redis', function (): void {
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
        '*get secret kutt-secrets*' => Process::result(output: '', exitCode: 1),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('tool:init --tool=kutt local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Kutt manifests...')
        ->expectsOutputToContain('Kutt shortener stack is live.');
});

test('link manifest pins Kutt to the Commons postgres client and enables redis', function (): void {
    $manifest = view('k8s.kutt.shared', [
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

test('link manifest carries canonical resource naming and identity labels', function (): void {
    $manifest = view('k8s.kutt.shared', [
        'host' => 'link.example.test',
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 3,
        'vpnOnly' => false,
        'isLocal' => true,
    ])->render();

    expect($manifest)
        ->toContain('name: kutt-link-example-test')
        ->toContain('luchtech.dev/tool: kutt')
        ->toContain('luchtech.dev/component: kutt')
        ->toContain('luchtech.dev/instance: link-example-test')
        ->toContain('name: kutt-secrets-link-example-test')
        ->toContain('name: kutt-smtp-link-example-test')
        ->toContain('name: kutt-oidc-link-example-test')
        ->toContain('value: "kutt_link_example_test"');
});

test('link manifest reads MAIL_SECURE from the SMTP Secret, so a re-apply never conflicts with mail:wire', function (): void {
    // Wired keys come from the wirer's Secret: mail:wire/sso:wire store their
    // static keys there and import it with --from=secret (ADR 0018), so the
    // live Deployment reads them via valueFrom. A literal here can't be merged
    // with that on re-apply ("valueFrom ... may not be specified when `value`
    // is not empty", Outline on luchtech-vps).
    $manifest = view('k8s.kutt.shared', [
        'host' => 'link.example.test',
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 3,
        'vpnOnly' => false,
        'isLocal' => true,
    ])->render();

    expect($manifest)->toMatch('/- name: MAIL_SECURE\s*\n\s*valueFrom:\s*\n\s*secretKeyRef:\s*\n\s*name: \S+\s*\n\s*key: MAIL_SECURE\b/')
        ->and(ClusterTool::KUTT->smtpEnv()['static']['MAIL_SECURE'] ?? null)->toBe('true');
});

test('link ingress proxies through Cloudflare on cloud deploys when proxied, so Kutt receives the cf-ipcountry header', function (): void {
    $cloud = view('k8s.kutt.shared', [
        'host' => 'link.luchtech.dev',
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 3,
        'vpnOnly' => false,
        'isLocal' => false,
        'proxied' => true,
    ])->render();

    $cloudDnsOnly = view('k8s.kutt.shared', [
        'host' => 'link.luchtech.dev',
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 3,
        'vpnOnly' => false,
        'isLocal' => false,
        'proxied' => false,
    ])->render();

    $local = view('k8s.kutt.shared', [
        'host' => 'link.test',
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 3,
        'vpnOnly' => false,
        'isLocal' => true,
        'proxied' => true,
    ])->render();

    expect($cloud)->toContain('external-dns.alpha.kubernetes.io/cloudflare-proxied: "true"')
        ->and($cloudDnsOnly)->not->toContain('cloudflare-proxied')
        ->and($local)->not->toContain('cloudflare-proxied');
});

test('resolveProxied honors the --proxied flag value and always yields false on local', function (mixed $raw, bool $expected): void {
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

    expect($cmd->resolveProxied(isLocal: false))->toBe($expected)
        ->and($cmd->resolveProxied(isLocal: true))->toBeFalse();
})->with([
    'default flag off' => [null, false],
    'explicit on' => [true, true],
    'string one' => ['1', true],
    'explicit off' => [false, false],
    'string zero' => ['0', false],
    'truthy words' => ['yes', true],
]);

test('tool:init --tool=kutt --vpn-only refuses — LINK is public infrastructure with no VPN mode', function (): void {
    $this->artisan('tool:init --tool=kutt local --vpn-only --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain("'kutt' doesn't have a --vpn-only ingress mode.");
});

test('tool:init --tool=kutt --vpn-only aborts without touching kubectl', function (): void {
    Process::fake([
        '*get secret kutt-secrets*' => Process::result(output: '', exitCode: 1),
        '*apply -f *' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('tool:init --tool=kutt local --vpn-only --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain("'kutt' doesn't have a --vpn-only ingress mode.");
});
