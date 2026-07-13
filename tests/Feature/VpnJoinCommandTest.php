<?php

use App\Commands\Vpn\VpnJoinCommand;
use App\State;
use Illuminate\Support\Facades\Process;

/**
 * @return array{0: VpnJoinCommand, 1: Symfony\Component\Console\Output\BufferedOutput}
 */
function vpnJoinRunner(): array
{
    // The test container may itself run on a WSL2 kernel (its /proc/version
    // says so even though it's a plain Linux container) — pin "not WSL" so
    // these tests exercise vpn:join's own logic, not DetectsWsl's, which has
    // its own dedicated test coverage.
    $command = new class extends VpnJoinCommand
    {
        protected function wslKernelSignaturePresent(): bool
        {
            return false;
        }
    };

    $input = new Symfony\Component\Console\Input\ArrayInput(['environment' => 'local']);
    $input->bind($command->getDefinition());
    $output = new Symfony\Component\Console\Output\BufferedOutput;

    $command->setInput($input);
    $command->setOutput(new Illuminate\Console\OutputStyle($input, $output));

    return [$command, $output];
}

test('vpn:join errors when the VPN is not installed for the environment', function () {
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

    Process::fake([
        "{$kubectl} get deployment netbird-management -n larakube-vpn --no-headers" => Process::result(output: '', exitCode: 1),
    ]);

    [$command, $output] = vpnJoinRunner();
    expect($command->handle())->toBe(1);
    expect(State::$lastError)->toContain("NetBird VPN isn't installed for 'local'.");
    expect($output->fetch())->toContain('larakube vpn:init local');
});

test('vpn:join errors when no setup key has been bootstrapped yet', function () {
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

    Process::fake([
        "{$kubectl} get deployment netbird-management -n larakube-vpn --no-headers" => 'netbird-management   1/1   1   1   5d',
        "{$kubectl} get secret netbird-admin -n larakube-vpn -o jsonpath='{.data.setup-key}'" => Process::result(output: '', exitCode: 1),
    ]);

    [$command, $output] = vpnJoinRunner();
    expect($command->handle())->toBe(1);
    expect(State::$lastError)->toContain('No NetBird setup key found');
});
