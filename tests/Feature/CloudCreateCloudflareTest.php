<?php

use App\Commands\Cloud\CloudCreateCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;

/**
 * A command that records the commands connectCloudflare() calls, so the
 * decision (ask, opt in by flag, or skip) is tested without a server.
 */
function cloudflareProbe(array $input): object
{
    $probe = new class extends CloudCreateCommand
    {
        /** @var list<array{0: string, 1: array<string, mixed>}> */
        public array $called = [];

        public ?Throwable $failWith = null;

        /** @var array<string, int> exit code per command; 0 when unset */
        public array $exitCodes = [];

        /** @var list<string> the Cloudflare token each call saw */
        public array $tokensSeen = [];

        public function call($command, array $arguments = []): int
        {
            $this->called[] = [(string) $command, $arguments];
            $this->tokensSeen[] = (string) getenv('LARAKUBE_CLOUDFLARE_TOKEN');

            if ($this->failWith !== null) {
                throw $this->failWith;
            }

            return $this->exitCodes[(string) $command] ?? 0;
        }

        /** @return array<string, mixed>|null */
        public function dnsOutcome(): ?array
        {
            return $this->result['dns'] ?? null;
        }

        public function finish(string $environment = 'production', string $context = 'ctx'): void
        {
            $this->connectCloudflare($environment, $context);
        }
    };

    $definition = $probe->getDefinition();
    // The application's own option, which a bare command does not carry.
    $definition->addOption(new InputOption('no-interaction', 'n', InputOption::VALUE_NONE));
    $arrayInput = new ArrayInput($input, $definition);
    $probe->setInput($arrayInput);
    $probe->setOutput(new Illuminate\Console\OutputStyle($arrayInput, new Symfony\Component\Console\Output\BufferedOutput));

    return $probe;
}

afterEach(function (): void {
    putenv('LARAKUBE_CLOUDFLARE_TOKEN');
    putenv('LARAKUBE_DNS_GROUPS');
});

test('a scripted run sets up Cloudflare DNS and SSL only when it opts in', function (): void {
    putenv('LARAKUBE_CLOUDFLARE_TOKEN=cf-token');

    $without = cloudflareProbe(['--no-interaction' => true]);
    $without->finish();

    $with = cloudflareProbe(['--no-interaction' => true, '--cloudflare' => true]);
    $with->finish('production', 'larakube-1.2.3.4');

    expect($without->called)->toBe([])
        ->and($with->called)->toBe([
            ['tool:init', ['--tool' => 'external-dns', 'environment' => 'production', '--context' => 'larakube-1.2.3.4', '--force' => true]],
            ['tls:init', ['environment' => 'production', '--context' => 'larakube-1.2.3.4', '--force' => true]],
        ]);
});

test('--cloudflare without a token does nothing and says so', function (): void {
    putenv('LARAKUBE_CLOUDFLARE_TOKEN');

    $probe = cloudflareProbe(['--no-interaction' => true, '--cloudflare' => true]);
    $probe->finish();

    expect($probe->called)->toBe([]);
});

test('a Cloudflare step that fails never fails the server', function (): void {
    putenv('LARAKUBE_CLOUDFLARE_TOKEN=cf-token');

    $probe = cloudflareProbe(['--no-interaction' => true, '--cloudflare' => true]);
    $probe->failWith = new RuntimeException('Missing required --group');
    $probe->finish();

    // Both were still attempted: a failed DNS step must not skip SSL.
    expect(array_column($probe->called, 0))->toBe(['tool:init', 'tls:init']);
});

test('a scripted run sets up Route53 DNS and TLS with --dns-provider=route53', function (): void {
    $probe = cloudflareProbe(['--no-interaction' => true, '--dns-provider' => 'route53']);
    $probe->finish('staging', 'managed-ctx');

    expect($probe->called)->toBe([
        ['tool:init', ['--tool' => 'external-dns', 'environment' => 'staging', '--context' => 'managed-ctx', '--provider' => 'route53', '--force' => true]],
        ['tls:init', ['environment' => 'staging', '--context' => 'managed-ctx', '--provider' => 'route53', '--force' => true]],
    ]);
});

test('a scripted run sets up Google Cloud DNS with --dns-provider=google', function (): void {
    $probe = cloudflareProbe(['--no-interaction' => true, '--dns-provider' => 'google']);
    $probe->finish('production', 'gke-ctx');

    expect($probe->called)->toBe([
        ['tool:init', ['--tool' => 'external-dns', 'environment' => 'production', '--context' => 'gke-ctx', '--provider' => 'google', '--force' => true]],
        ['tls:init', ['environment' => 'production', '--context' => 'gke-ctx', '--provider' => 'google', '--force' => true]],
    ]);
});

test('--without-dns skips external-dns but runs tls:init', function (): void {
    $probe = cloudflareProbe(['--no-interaction' => true, '--dns-provider' => 'route53', '--without-dns' => true]);
    $probe->finish('prod', 'ctx');

    expect($probe->called)->toBe([
        ['tls:init', ['environment' => 'prod', '--context' => 'ctx', '--provider' => 'route53', '--force' => true]],
    ]);
});

test('--without-tls skips tls:init but runs external-dns', function (): void {
    $probe = cloudflareProbe(['--no-interaction' => true, '--dns-provider' => 'google', '--without-tls' => true]);
    $probe->finish('prod', 'ctx');

    expect($probe->called)->toBe([
        ['tool:init', ['--tool' => 'external-dns', 'environment' => 'prod', '--context' => 'ctx', '--provider' => 'google', '--force' => true]],
    ]);
});

test('the result reports which DNS steps finished, so LaraKube Cloud can show it', function (): void {
    putenv('LARAKUBE_CLOUDFLARE_TOKEN=cf-token');

    $ok = cloudflareProbe(['--no-interaction' => true, '--dns-provider' => 'cloudflare']);
    $ok->finish();

    $tlsFailed = cloudflareProbe(['--no-interaction' => true, '--dns-provider' => 'cloudflare']);
    $tlsFailed->exitCodes = ['tls:init' => 1];
    $tlsFailed->finish();

    $thrown = cloudflareProbe(['--no-interaction' => true, '--dns-provider' => 'cloudflare']);
    $thrown->failWith = new RuntimeException('zone not found');
    $thrown->finish();

    expect($ok->dnsOutcome())->toBe(['provider' => 'cloudflare', 'records' => true, 'tls' => true, 'error' => null])
        ->and($tlsFailed->dnsOutcome())->toMatchArray(['records' => true, 'tls' => false])
        ->and($tlsFailed->dnsOutcome()['error'])->toContain('did not finish')
        ->and($thrown->dnsOutcome())->toMatchArray(['records' => false, 'tls' => false])
        ->and($thrown->dnsOutcome()['error'])->toContain('zone not found');
});

test('the result says DNS was skipped when no token was given', function (): void {
    putenv('LARAKUBE_CLOUDFLARE_TOKEN');

    $probe = cloudflareProbe(['--no-interaction' => true, '--dns-provider' => 'cloudflare']);
    $probe->finish();

    expect($probe->dnsOutcome())->toMatchArray(['records' => null, 'tls' => null])
        ->and($probe->called)->toBe([]);
});

test('a run that never asked for DNS reports nothing about it', function (): void {
    $probe = cloudflareProbe(['--no-interaction' => true]);
    $probe->finish();

    expect($probe->dnsOutcome())->toBeNull();
});

test('several Cloudflare accounts each get their own ExternalDNS group, and SSL uses the chosen one', function (): void {
    putenv('LARAKUBE_DNS_GROUPS='.json_encode([['group' => 'dns-acme', 'token' => 'token-acme'], ['group' => 'dns-beta', 'token' => 'token-beta']]));

    $probe = cloudflareProbe(['--no-interaction' => true, '--dns-provider' => 'cloudflare', '--dns-tls-group' => 'dns-beta']);
    $probe->exitCodes = ['tls:init' => 0];
    $probe->finish('production', 'larakube-1.2.3.4');

    expect(array_map(fn (array $call): string => $call[0].' '.($call[1]['--group'] ?? ''), $probe->called))
        ->toBe(['tool:init dns-acme', 'tool:init dns-beta', 'tls:init dns-beta'])
        ->and($probe->tokensSeen)->toBe(['token-acme', 'token-beta', 'token-beta'])
        ->and($probe->dnsOutcome())->toMatchArray(['tls' => true, 'tls_group' => 'dns-beta', 'error' => null])
        ->and(array_column($probe->dnsOutcome()['groups'], 'records'))->toBe([true, true])
        ->and(getenv('LARAKUBE_CLOUDFLARE_TOKEN'))->toBeFalse();
});

test('a failing group is reported without stopping the others', function (): void {
    putenv('LARAKUBE_DNS_GROUPS='.json_encode([['group' => 'BAD NAME', 'token' => 'x'], ['group' => 'dns-ok', 'token' => 'token-ok']]));

    $probe = cloudflareProbe(['--no-interaction' => true, '--dns-provider' => 'cloudflare']);
    $probe->finish();

    expect(array_column($probe->called, 0))->toBe(['tool:init'])
        ->and($probe->dnsOutcome()['groups'][0]['records'])->toBeFalse()
        ->and($probe->dnsOutcome()['groups'][1])->toMatchArray(['group' => 'dns-ok', 'records' => true])
        ->and($probe->dnsOutcome()['tls'])->toBeNull()
        ->and($probe->dnsOutcome()['error'])->not->toBeNull();
});

test('an SSL group that is not one of the DNS groups is refused', function (): void {
    putenv('LARAKUBE_DNS_GROUPS='.json_encode([['group' => 'dns-acme', 'token' => 'token-acme']]));

    $probe = cloudflareProbe(['--no-interaction' => true, '--dns-provider' => 'cloudflare', '--dns-tls-group' => 'dns-other']);
    $probe->finish();

    expect(array_column($probe->called, 0))->toBe(['tool:init'])
        ->and($probe->dnsOutcome()['tls'])->toBeFalse();
});
