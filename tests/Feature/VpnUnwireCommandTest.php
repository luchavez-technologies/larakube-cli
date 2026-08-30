<?php

/**
 * vpn:unwire, and the shared Middleware it must not rip out from under a
 * sibling instance.
 *
 * Every ingress blade hardcodes larakube-shared-{tool}-vpn-only with no
 * instance suffix, so ONE Middleware backs every instance of a tool. Deleting
 * it while another instance still references it leaves that instance pointing
 * at a Middleware Traefik cannot resolve, and its route stops serving --
 * the opposite of what unwiring is for. 15 of 21 VPN-capable shipped tools are
 * multi-instance, so this is reachable, not theoretical.
 */

use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

/** Two notes ingresses, both VPN-gated — the multi-instance case. */
function vpnUnwireIngressJson(string ...$hosts): string
{
    $items = [];
    foreach ($hosts as $host) {
        $items[] = [
            'metadata' => ['annotations' => [
                'traefik.ingress.kubernetes.io/router.middlewares' => 'larakube-shared-notes-vpn-only@kubernetescrd',
            ]],
            'spec' => ['rules' => [['host' => $host]]],
        ];
    }

    return (string) json_encode(['items' => $items]);
}

function vpnUnwireSubject(string $ingressJson): object
{
    Process::fake([
        '*get ingress -A -o json*' => Process::result(output: $ingressJson),
        '*delete middleware*' => Process::result(output: 'deleted'),
        '*get ingress notes*' => Process::result(output: ''),
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    return new class extends App\Commands\Vpn\VpnUnwireCommand
    {
        public function call($command, array $arguments = []): int
        {
            return 0;
        }

        public function testUnwire(ClusterTool $tool, array $target, string $kubectl, string $env, string $domain = ''): int
        {
            return $this->unwire($tool, $target, $kubectl, $env, $domain);
        }
    };
}

function vpnUnwireBoot(object $command): void
{
    $command->setLaravel(app());
    $command->setOutput(new Illuminate\Console\OutputStyle(
        new Symfony\Component\Console\Input\ArrayInput([]),
        new Symfony\Component\Console\Output\BufferedOutput,
    ));
}

test('vpn:unwire is registered and accepts --domain like vpn:wire does', function (): void {
    // Asymmetry was the bug: you could restrict one instance and then had no
    // way to lift it.
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('vpn:unwire');

    $definition = (new App\Commands\Vpn\VpnUnwireCommand)->getDefinition();

    expect($definition->hasOption('domain'))->toBeTrue();
});

test('the shared Middleware survives while another instance still references it', function (): void {
    $subject = vpnUnwireSubject(vpnUnwireIngressJson('notes.example.com', 'team.notes.example.com'));
    vpnUnwireBoot($subject);

    $target = ClusterTool::NOTES->vpnMiddlewareTarget();
    $subject->testUnwire(ClusterTool::NOTES, $target, 'kubectl', 'production', 'notes.example.com');

    Process::assertNotRan(fn ($p) => str_contains($p->command, 'delete middleware'));
});

test('the shared Middleware is removed once nothing references it', function (): void {
    $subject = vpnUnwireSubject(vpnUnwireIngressJson());
    vpnUnwireBoot($subject);

    $target = ClusterTool::NOTES->vpnMiddlewareTarget();
    $subject->testUnwire(ClusterTool::NOTES, $target, 'kubectl', 'production');

    Process::assertRan(fn ($p) => str_contains($p->command, 'delete middleware/'.$target['name']));
});

test('every wire pair has a predicate backed by its own marker contract', function (): void {
    // Each pair used to infer capability from whatever accessor happened to
    // return null — vpnMiddlewareTarget(), smtpEnv(), dbSecretRef() — so the
    // pickers drifted apart. These are the contracts of record.
    foreach (ClusterTool::cases() as $tool) {
        if (! $tool->isShipped()) {
            continue;
        }

        $vendor = $tool->vendor();

        expect($tool->hasVpnWire())->toBe($vendor instanceof App\Contracts\HasVpnWiring)
            ->and($tool->hasSecretsWire())->toBe($vendor instanceof App\Contracts\HasRotatableDatabasePassword)
            // SSO carries no smtpEnv() schema — Zitadel takes SMTP through its
            // own API — but is still mail-wireable, so it is folded in here
            // rather than special-cased at each call site.
            ->and($tool->hasMailWire())->toBe(
                $vendor instanceof App\Contracts\HasSmtpWiring || $tool === ClusterTool::SSO,
            );
    }
});

test('secrets:wire capability is DB rotation, not the similarly-named OpenBao sync', function (): void {
    // HasOpenbaoSync (10 tools) pushes a tool's own secrets INTO OpenBao.
    // HasRotatableDatabasePassword (17) is what secrets:wire acts on. Wiring
    // the predicate to the wrong one broke every --tool= that is rotatable but
    // not sync-capable, e.g. sign.
    expect(ClusterTool::SIGN->hasSecretsWire())->toBeTrue()
        ->and(ClusterTool::SIGN->vendor() instanceof App\Contracts\HasOpenbaoSync)->toBeFalse();
});
