<?php

use App\Commands\Vpn\VpnJoinCommand;
use App\Data\CloudData;
use App\Data\ConfigData;
use App\State;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @param  array<string, mixed>  $options
 * @return array{0: VpnJoinCommand, 1: BufferedOutput}
 */
function vpnJoinRunner(string $environment = 'local', array $options = []): array
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

    $input = new ArrayInput(array_merge(['environment' => $environment], $options));
    $input->bind($command->getDefinition());
    $output = new BufferedOutput;

    $command->setInput($input);
    $command->setOutput(new OutputStyle($input, $output));

    return [$command, $output];
}

test('vpn:join errors when the VPN is not installed for the environment', function (): void {
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

    Process::fake([
        "{$kubectl} get deployment netbird-management -n larakube-vpn --no-headers" => Process::result(output: '', exitCode: 1),
    ]);

    [$command, $output] = vpnJoinRunner();
    expect($command->handle())->toBe(1)
        ->and(State::$lastError)->toContain("NetBird VPN isn't installed for 'local'.")
        ->and($output->fetch())->toContain('larakube vpn:init local');
});

test('vpn:join errors when no setup key has been bootstrapped yet', function (): void {
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

    Process::fake([
        "{$kubectl} get deployment netbird-management -n larakube-vpn --no-headers" => 'netbird-management   1/1   1   1   5d',
        "{$kubectl} get secret vpn-secrets -n larakube-vpn -o jsonpath='{.data.setup-key}'" => Process::result(output: '', exitCode: 1),
    ]);

    [$command, $output] = vpnJoinRunner();
    expect($command->handle())->toBe(1)
        ->and(State::$lastError)->toContain('No NetBird setup key found');
});

test('vpn:join targets the CHOSEN environment\'s own saved context, never the ambient current context', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    $original = getcwd();
    chdir($dir);

    $config = ConfigData::from([
        'name' => 'demo',
        'database' => 'sqlite',
        'environments' => ['local' => [], 'production' => []],
    ]);
    $config->setCloud('production', new CloudData(ip: '203.0.113.10', user: 'deploy'));
    $config->setHost('production', 'vpn', 'vpn.example.com');
    $config->saveToFile($dir);

    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl --context=larakube-203.0.113.10';

    try {
        Process::fake([
            "{$kubectl} get deployment netbird-management -n larakube-vpn --no-headers" => 'netbird-management   1/1   1   1   5d',
            "{$kubectl} get secret vpn-secrets -n larakube-vpn -o jsonpath='{.data.setup-key}'" => Process::result(output: '', exitCode: 1),
        ]);

        [$command] = vpnJoinRunner('production');
        expect($command->handle())->toBe(1)
            ->and(State::$lastError)->toContain('No NetBird setup key found');
    } finally {
        chdir($original);
        $temporaryDirectory->delete();
    }
});

test('vpn:join --sso errors when NetBird is not wired to SSO yet, without ever touching the setup-key flow', function (): void {
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

    Process::fake([
        "{$kubectl} get deployment netbird-management -n larakube-vpn --no-headers" => 'netbird-management   1/1   1   1   5d',
        "{$kubectl} get secret netbird-oidc -n larakube-vpn" => Process::result(output: '', exitCode: 1),
    ]);

    [$command, $output] = vpnJoinRunner('local', ['--sso' => true]);
    expect($command->handle())->toBe(1)
        ->and(State::$lastError)->toContain("NetBird isn't wired to SSO yet")
        ->and(State::$lastError)->toContain('larakube sso:wire vpn local');

    // Must never fall through to the setup-key path — no setup-key lookup,
    // no `netbird up --setup-key` attempted.
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'setup-key'));
});
