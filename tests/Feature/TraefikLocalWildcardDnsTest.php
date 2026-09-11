<?php

use App\Commands\Traefik\SetupCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The wildcard-DNS step lives on InteractsWithTraefik, so it is exercised
 * through the command that owns it rather than through a bare trait host —
 * it prints, and printing needs a real console output.
 *
 * @return array{0: object, 1: BufferedOutput}
 */
function traefikLocalDnsRunner(): array
{
    $command = new class extends SetupCommand
    {
        public function applyDns(string $tld): bool
        {
            return $this->applyLocalWildcardDns($tld);
        }
    };

    $input = new ArrayInput([]);
    $output = new BufferedOutput;

    $command->setInput($input);
    $command->setOutput(new OutputStyle($input, $output));

    return [$command, $output];
}

/** @return array<string, mixed> */
function traefikLocalDnsFakes(string $corefile = 'import /etc/coredns/custom/*.server', string $clusterIp = '10.43.0.9'): array
{
    return [
        '*get configmap coredns*' => Process::result(output: $corefile),
        '*get service traefik*' => Process::result(output: $clusterIp),
        '*create configmap coredns-custom*' => Process::result(output: 'configmap/coredns-custom created'),
        '*rollout restart deployment coredns*' => Process::result(output: 'restarted'),
        '*rollout status deployment coredns*' => Process::result(output: 'rolled out'),
    ];
}

test('the wildcard DNS override points the local TLD at Traefik and rolls CoreDNS', function (): void {
    Process::fake(traefikLocalDnsFakes());

    [$command] = traefikLocalDnsRunner();

    expect($command->applyDns('test'))->toBeTrue();

    Process::assertRan(fn ($process): bool => str_contains($process->command, 'create configmap coredns-custom -n kube-system')
        // The key must be <tld>.server — that suffix is what CoreDNS's
        // `import /etc/coredns/custom/*.server` actually picks up.
        && str_contains($process->command, 'test.server=test:53 {')
        && str_contains($process->command, 'answer "{{ .Name }} 60 IN A 10.43.0.9"'));

    // Without the roll, CoreDNS keeps serving the old config until its own
    // reload interval elapses, so setup would report success prematurely.
    Process::assertRan(fn ($process): bool => str_contains($process->command, 'rollout restart deployment coredns -n kube-system'));
});

test('a cluster whose CoreDNS imports no custom config is skipped, not failed', function (): void {
    Process::fake(traefikLocalDnsFakes(corefile: '.:53 { errors forward . /etc/resolv.conf }'));

    [$command, $output] = traefikLocalDnsRunner();

    expect($command->applyDns('test'))->toBeFalse();

    Process::assertNotRan(fn ($process): bool => str_contains($process->command, 'create configmap coredns-custom'));
    // laraKubeWarn() renders through Termwind's own stream, so the follow-up
    // laraKubeLine() is what this buffer can actually see.
    expect($output->fetch())->toContain('will fail here');
});

test('an unreadable Traefik ClusterIP skips the override instead of writing a broken one', function (): void {
    Process::fake(traefikLocalDnsFakes(clusterIp: ''));

    [$command] = traefikLocalDnsRunner();

    expect($command->applyDns('test'))->toBeFalse();

    Process::assertNotRan(fn ($process): bool => str_contains($process->command, 'create configmap coredns-custom'));
    Process::assertNotRan(fn ($process): bool => str_contains($process->command, 'rollout restart deployment coredns'));
});

test('a headless Traefik Service is treated as no ClusterIP at all', function (): void {
    // `jsonpath={.spec.clusterIP}` returns the literal string "None" for a
    // headless Service — writing that into an A record would be a valid-looking
    // override that resolves every local host to nothing.
    Process::fake(traefikLocalDnsFakes(clusterIp: 'None'));

    [$command] = traefikLocalDnsRunner();

    expect($command->applyDns('test'))->toBeFalse();

    Process::assertNotRan(fn ($process): bool => str_contains($process->command, 'create configmap coredns-custom'));
});
