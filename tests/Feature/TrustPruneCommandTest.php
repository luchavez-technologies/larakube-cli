<?php

use App\Traits\ManagesLocalCa;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/**
 * trust:prune removes local certificates no Ingress on the local cluster
 * serves (old projects, removed apps, an old TLD). LaraKube issues one again
 * when its app is installed or `up` runs, so removing them is safe.
 */
function trustPruneCerts(): object
{
    return new class
    {
        use ManagesLocalCa;

        public function project(string $name, string $tld): void
        {
            $this->ensureAppCertExists($name, $tld);
        }

        public function host(string $host): void
        {
            $this->ensureHostCertExists($host);
        }
    };
}

function trustPruneDir(): string
{
    return $_SERVER['HOME'].'/.larakube/certificates';
}

/** The local cluster serves notes.test and a project at shop.test. */
function trustPruneFakes(string $context = 'orbstack'): array
{
    return [
        '*config current-context*' => Process::result(output: "{$context}\n"),
        '*get ingress -A*' => Process::result(output: (string) json_encode(['items' => [
            ['spec' => ['rules' => [['host' => 'notes.test']]]],
            ['spec' => ['rules' => [['host' => 'shop.test']], 'tls' => [['hosts' => ['shop.test']]]]],
        ]])),
        '*get ingressroutes.traefik.io*' => Process::result(output: (string) json_encode(['items' => [
            ['spec' => ['routes' => [['match' => 'Host(`traefik.test`) && PathPrefix(`/`)']]]],
        ]])),
        '*' => Process::result(output: ''),
    ];
}

function trustPruneJson(array $arguments): array
{
    Artisan::call('trust:prune', ['--json' => true, ...$arguments]);
    $lines = preg_split('/\R/', trim(Artisan::output())) ?: [];

    return json_decode((string) end($lines), true);
}

beforeEach(function (): void {
    $certs = trustPruneCerts();
    $certs->project('shop', 'test');
    $certs->project('vanity', 'kube');
    $certs->host('notes.test');
    $certs->host('analytics.test');
    $certs->host('traefik.test');
});

test('a dry run lists the certificates nothing serves, and removes none', function (): void {
    Process::fake(trustPruneFakes());

    $result = trustPruneJson(['--dry-run' => true]);

    expect(collect($result['unused'])->pluck('hosts')->flatten()->sort()->values()->all())->toBe(['analytics.test', 'vanity.kube'])
        ->and($result['removed'])->toBe([])
        ->and(file_exists(trustPruneDir().'/vanity-dev.crt'))->toBeTrue();
});

test('pruning removes the unused certificates and gives Traefik the smaller set', function (): void {
    Process::fake(trustPruneFakes());

    $result = trustPruneJson(['--force' => true]);

    expect(collect($result['removed'])->pluck('name')->sort()->values()->all())->toBe(['host-analytics-test', 'vanity'])
        ->and(file_exists(trustPruneDir().'/vanity-dev.crt'))->toBeFalse()
        ->and(file_exists(trustPruneDir().'/vanity-dev.key'))->toBeFalse()
        ->and(file_exists(trustPruneDir().'/host-analytics-test-dev.crt'))->toBeFalse()
        ->and(file_exists(trustPruneDir().'/shop-dev.crt'))->toBeTrue()
        ->and(file_exists(trustPruneDir().'/host-notes-test-dev.crt'))->toBeTrue()
        ->and(file_exists(trustPruneDir().'/host-traefik-test-dev.crt'))->toBeTrue();

    Process::assertRan(fn ($process) => str_contains((string) $process->command.(string) $process->input, 'traefik-certificates'));
});

test('it refuses to prune against a cluster that is not local', function (): void {
    Process::fake(trustPruneFakes('do-nyc1-prod'));

    expect(trustPruneJson(['--force' => true]))->toMatchArray(['success' => false])
        ->and(file_exists(trustPruneDir().'/vanity-dev.crt'))->toBeTrue();
});

test('it refuses when it cannot read the ingresses, rather than treating everything as unused', function (): void {
    Process::fake([
        '*config current-context*' => Process::result(output: "orbstack\n"),
        '*get ingress -A*' => Process::result(errorOutput: 'connection refused', exitCode: 1),
        '*' => Process::result(output: ''),
    ]);

    expect(trustPruneJson(['--force' => true])['error'])->toContain('Could not read the local cluster')
        ->and(file_exists(trustPruneDir().'/shop-dev.crt'))->toBeTrue();
});

test('without --force and without a terminal it removes nothing', function (): void {
    Process::fake(trustPruneFakes());

    expect(trustPruneJson([])['error'])->toBe('Not removing without --force.')
        ->and(file_exists(trustPruneDir().'/vanity-dev.crt'))->toBeTrue();
});

test('trust:check no longer calls a valid host certificate the wrong TLD', function (): void {
    // An old project certificate from before TLD sidecars: checked against
    // this machine's TLD (.test here), which an old .kube cert misses.
    trustPruneCerts()->project('legacy', 'kube');
    unlink(trustPruneDir().'/legacy-dev.tld');

    Artisan::call('trust:check');
    $output = Artisan::output();

    expect($output)->not->toContain('notes.test           wrong TLD')
        ->and($output)->toMatch('/notes\.test\s+valid until/')
        ->and($output)->toMatch('/legacy\s+wrong TLD/');
});
