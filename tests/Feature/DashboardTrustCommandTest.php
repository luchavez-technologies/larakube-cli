<?php

use App\Commands\Dashboard\DashboardTrustCommand;
use App\Data\CloudData;
use App\Data\ConfigData;
use Illuminate\Console\OutputStyle;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function dashboardTrustProjectDir(array $cloud, string $env = 'production'): string
{
    $dir = sys_get_temp_dir().'/larakube-dashtrust-'.uniqid();
    mkdir($dir, 0755, true);

    // file_exists($keyPath) is a real check in the command — give SSH-path
    // tests a real (if fake-content) key file to point at instead of a
    // string that would fail before SSH is ever attempted.
    if (isset($cloud['ip']) && ! isset($cloud['key'])) {
        $cloud['key'] = $dir.'/dummy_key';
    }
    if (isset($cloud['key'])) {
        file_put_contents($cloud['key'], "dummy\n");
    }

    $config = ConfigData::from([
        'name' => 'dashtrust-test',
        'database' => 'sqlite',
        'environments' => [
            'local' => [],
            $env => ['hosts' => ['sso' => 'sso.luchtech.dev']],
        ],
    ]);
    if ($cloud !== []) {
        $config->setCloud($env, new CloudData(...$cloud));
    }
    $config->saveToFile($dir);

    return $dir;
}

function withDashboardTrustProject(array $cloud, callable $fn, string $env = 'production'): void
{
    $dir = dashboardTrustProjectDir($cloud, $env);
    $original = getcwd();
    chdir($dir);

    try {
        $fn();
    } finally {
        chdir($original);
        exec('rm -rf '.escapeshellarg($dir));
    }
}

test('dashboard:trust rejects local', function () {
    $this->artisan('dashboard:trust', ['environment' => 'local'])
        ->assertExitCode(1)
        ->expectsOutputToContain('OrbStack, not k3s');
});

test('dashboard:trust errors when no server is recorded for the environment', function () {
    withDashboardTrustProject([], function () {
        $this->artisan('dashboard:trust', ['environment' => 'production'])
            ->assertExitCode(1)
            ->expectsOutputToContain('No server is recorded');
    });
});

test('dashboard:trust refuses a managed cluster — there is no node to SSH into', function () {
    withDashboardTrustProject(['context' => 'do-nyc1-app', 'provider' => 'doks'], function () {
        $this->artisan('dashboard:trust', ['environment' => 'production'])
            ->assertExitCode(1)
            ->expectsOutputToContain('managed Kubernetes cluster');
    });
});

test('dashboard:trust errors when Zitadel is not installed', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: ''),
    ]);

    withDashboardTrustProject(['ip' => '1.2.3.4', 'user' => 'larakube', 'port' => 22], function () {
        $this->artisan('dashboard:trust', ['environment' => 'production'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Zitadel is not installed');
    });
});

test('dashboard:trust errors when Headlamp has not been wired to Zitadel yet', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-app-dashboard*' => Process::result(output: '', exitCode: 1),
    ]);

    withDashboardTrustProject(['ip' => '1.2.3.4', 'user' => 'larakube', 'port' => 22], function () {
        $this->artisan('dashboard:trust', ['environment' => 'production'])
            ->assertExitCode(1)
            ->expectsOutputToContain('sso:wire production --tool=dashboard');
    });
});

test('dashboard:trust is a no-op when the API server already trusts Zitadel', function () {
    $desired = <<<'YAML'
    kube-apiserver-arg:
      - "oidc-issuer-url=https://sso.luchtech.dev"
      - "oidc-client-id=cid-1"
      - "oidc-username-claim=email"
      - "oidc-groups-claim=groups"
      - "oidc-username-prefix=-"
      - "oidc-groups-prefix=-"
    YAML;

    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-app-dashboard*' => Process::result(output: base64_encode('cid-1')),
        "*'echo success'" => Process::result(output: "success\n"),
        '*larakube@1.2.3.4*cat /etc/rancher/k3s/config.yaml*' => Process::result(output: $desired),
    ]);

    withDashboardTrustProject(['ip' => '1.2.3.4', 'user' => 'larakube', 'port' => 22], function () {
        $this->artisan('dashboard:trust', ['environment' => 'production'])
            ->assertExitCode(0)
            ->expectsOutputToContain('already trusts Zitadel');
    });

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'systemctl restart k3s'));
});

test('dashboard:trust writes the config and restarts k3s when the OIDC trust is missing', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-app-dashboard*' => Process::result(output: base64_encode('cid-1')),
        "*'echo success'" => Process::result(output: "success\n"),
        '*cat /etc/rancher/k3s/config.yaml*' => Process::result(output: ''),
        '*get --raw=/livez*' => Process::result(output: 'ok', exitCode: 0),
        fn (PendingProcess $process) => str_contains($process->command, 'systemctl restart k3s')
            ? Process::result(output: "ok\n", exitCode: 0)
            : null,
    ]);

    withDashboardTrustProject(['ip' => '1.2.3.4', 'user' => 'larakube', 'port' => 22], function () {
        // confirm()'s own default is true, and Pest.php pins every prompt
        // non-interactive — no scripting needed to let it proceed. (Laravel
        // Prompts is a different mechanism from Symfony's askQuestion(),
        // so expectsConfirmation() can't observe it either way.)
        $this->artisan('dashboard:trust', ['environment' => 'production'])
            ->assertExitCode(0)
            ->expectsOutputToContain('now trusts Zitadel');
    });

    // The desired config is base64-encoded before it's embedded in the
    // remote script (safe transport over SSH), so the literal "oidc-*" text
    // never appears in the outer command — assert against the encoded form.
    $expectedConfig = <<<'YAML'
    kube-apiserver-arg:
      - "oidc-issuer-url=https://sso.luchtech.dev"
      - "oidc-client-id=cid-1"
      - "oidc-username-claim=email"
      - "oidc-groups-claim=groups"
      - "oidc-username-prefix=-"
      - "oidc-groups-prefix=-"
    YAML;

    Process::assertRan(fn ($process) => str_contains($process->command, 'larakube@1.2.3.4')
        && str_contains($process->command, 'systemctl restart k3s')
        && str_contains($process->command, base64_encode($expectedConfig)));
});

test('dashboard:trust cancels cleanly when the operator declines the restart', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-app-dashboard*' => Process::result(output: base64_encode('cid-1')),
        "*'echo success'" => Process::result(output: "success\n"),
        '*cat /etc/rancher/k3s/config.yaml*' => Process::result(output: ''),
    ]);

    $dir = dashboardTrustProjectDir(['ip' => '1.2.3.4', 'user' => 'larakube', 'port' => 22]);
    $original = getcwd();
    chdir($dir);

    try {
        // confirm()'s non-interactive default is true — actually exercising
        // "declined" needs a real scripted 'n' keypress, which (per
        // SsoRevokeCommandTest) only works running the command directly,
        // not through artisan()'s Kernel::configurePrompts() fallbacks.
        Prompt::fake(['n', Key::ENTER]);

        $command = app(DashboardTrustCommand::class);
        $input = new ArrayInput(['environment' => 'production']);
        $input->bind($command->getDefinition());
        $input->setInteractive(true);
        $output = new BufferedOutput;
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $output));
        \Termwind\renderUsing($output);

        $exitCode = $command->handle();

        expect($exitCode)->toBe(0);
        expect($output->fetch())->toContain('Cancelled');
    } finally {
        chdir($original);
        exec('rm -rf '.escapeshellarg($dir));
    }

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'systemctl restart k3s'));
});
