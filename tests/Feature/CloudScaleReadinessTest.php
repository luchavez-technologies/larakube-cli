<?php

use App\Data\GlobalConfigData;
use App\Data\StackData;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * cloud:scale only ever confirmed OpenTofu accepted the resize request —
 * never that the VM (and k3s on it) actually came back. Many providers
 * reboot to apply a live CPU/RAM resize, same as cloud:restart already
 * waits out explicitly.
 */
function cloudScaleTestStack(TemporaryDirectory $home, array $overrides = []): string
{
    $key = $home->path('.larakube/test-key');
    @mkdir(dirname($key), 0755, true);
    file_put_contents($key, 'key');

    $config = GlobalConfigData::load();
    $config->putStack(new StackData(...($overrides + [
        'name' => 'scale-test', 'provider' => 'do', 'kind' => 'vps', 'region' => 'sgp1',
        'ip' => '203.0.113.50', 'context' => 'larakube-203.0.113.50', 'sshKey' => $key,
    ])));
    $config->save();

    $tofuDir = $home->path('.larakube/tofu/scale-test');
    @mkdir($tofuDir, 0700, true);
    file_put_contents($tofuDir.'/main.tf', <<<'HCL'
resource "digitalocean_droplet" "larakube" {
  name   = "scale-test"
  region = "sgp1"
  size   = "s-1vcpu-1gb"
}
HCL
    );

    // TemporaryDirectory::path() misdetects an extension-less name as a
    // directory (it only treats a name containing a "." as a file), so this
    // concatenates manually instead of calling path('tofu-bin').
    $fakeTofu = $home->path().'/tofu-bin';
    file_put_contents($fakeTofu, "#!/bin/sh\nexit 0\n");
    chmod($fakeTofu, 0755);

    return $fakeTofu;
}

function cloudScaleCommandLine(Illuminate\Process\PendingProcess $process): string
{
    return is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
}

function withIsolatedHome(Closure $test): void
{
    $home = TemporaryDirectory::make()->deleteWhenDestroyed();
    $oldHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = $home->path();

    try {
        $test($home);
    } finally {
        if ($oldHome !== null) {
            $_SERVER['HOME'] = $oldHome;
        }
    }
}

test('cloud:scale waits for SSH and Kubernetes after resizing, not just a successful tofu apply', function (): void {
    withIsolatedHome(function (TemporaryDirectory $home): void {
        $fakeTofu = cloudScaleTestStack($home);

        Process::fake([
            'command -v tofu' => Process::result($fakeTofu),
            '*apply*' => Process::result(output: 'Apply complete!'),
            '*echo success*' => Process::result(output: 'success'),
            '*--raw=/readyz*' => Process::result(output: 'ok'),
        ]);

        $this->artisan('cloud:scale scale-test --size=s-2vcpu-4gb --do-token=fake-do-token --force --no-interaction')
            ->assertExitCode(0)
            ->expectsOutputToContain('successfully scaled')
            ->expectsOutputToContain('is back and serving again');
    });
});

test('cloud:scale reports failure, not false success, when the server never comes back over SSH', function (): void {
    withIsolatedHome(function (TemporaryDirectory $home): void {
        $fakeTofu = cloudScaleTestStack($home);

        Process::fake([
            'command -v tofu' => Process::result($fakeTofu),
            '*apply*' => Process::result(output: 'Apply complete!'),
            '*echo success*' => Process::result(output: '', exitCode: 255),
        ]);

        $this->artisan('cloud:scale scale-test --size=s-2vcpu-4gb --do-token=fake-do-token --force --no-interaction')
            ->assertExitCode(1)
            ->expectsOutputToContain('did not come back over SSH after scaling');
    });
});

test('cloud:scale reports a clear warning, not false success, when SSH is back but Kubernetes is not', function (): void {
    withIsolatedHome(function (TemporaryDirectory $home): void {
        $fakeTofu = cloudScaleTestStack($home);

        Process::fake([
            'command -v tofu' => Process::result($fakeTofu),
            '*apply*' => Process::result(output: 'Apply complete!'),
            '*echo success*' => Process::result(output: 'success'),
            '*--raw=/readyz*' => Process::result(output: '', exitCode: 1),
        ]);

        $this->artisan('cloud:scale scale-test --size=s-2vcpu-4gb --do-token=fake-do-token --force --no-interaction')
            ->assertExitCode(1)
            ->expectsOutputToContain('Kubernetes is not answering yet');
    });
});

test('cloud:scale follows a server that comes back on a new public IP', function (): void {
    withIsolatedHome(function (TemporaryDirectory $home): void {
        $fakeTofu = cloudScaleTestStack($home);
        @mkdir($home->path('.ssh'), 0700, true);
        file_put_contents($home->path('.ssh/config'), "Host scale-test\n    HostName 203.0.113.50\n    User larakube\n    Port 22\n");

        Process::fake([
            'command -v tofu' => Process::result($fakeTofu),
            '*apply*' => Process::result(output: 'Apply complete!'),
            '*output*-raw*ip*' => Process::result(output: '198.51.100.7'),
            '*198.51.100.7*echo success*' => Process::result(output: 'success'),
            '*203.0.113.50*echo success*' => Process::result(output: '', exitCode: 255),
            '*198.51.100.7*tls-san*' => Process::result(output: 'Updating k3s TLS SAN'),
            '*config view*' => Process::result(output: 'larakube-203.0.113.50'),
            '*config set-cluster*' => Process::result(),
            '*get deployment -n traefik traefik*' => Process::result(exitCode: 1),
            '*--raw=/readyz*' => Process::result(output: 'ok'),
        ]);

        $this->artisan('cloud:scale scale-test --size=s-2vcpu-4gb --do-token=fake-do-token --force --no-interaction')
            ->expectsOutputToContain('came back on a new public IP (203.0.113.50 → 198.51.100.7)')
            ->assertExitCode(0);

        expect(GlobalConfigData::load()->findStack('scale-test')->ip)->toBe('198.51.100.7')
            ->and((string) file_get_contents($home->path('.ssh/config')))->toContain('HostName 198.51.100.7');
        Process::assertRan(fn ($p): bool => str_contains(cloudScaleCommandLine($p), 'tls-san') && str_contains(cloudScaleCommandLine($p), '198.51.100.7'));
        Process::assertRan(fn ($p): bool => str_contains(cloudScaleCommandLine($p), 'set-cluster')
            && str_contains(cloudScaleCommandLine($p), 'larakube-203.0.113.50')
            && str_contains(cloudScaleCommandLine($p), '--server=https://198.51.100.7:6443'));
    });
});

test('cloud:scale leaves everything alone when the server keeps its IP', function (): void {
    withIsolatedHome(function (TemporaryDirectory $home): void {
        $fakeTofu = cloudScaleTestStack($home);

        Process::fake([
            'command -v tofu' => Process::result($fakeTofu),
            '*apply*' => Process::result(output: 'Apply complete!'),
            '*output*-raw*ip*' => Process::result(output: '203.0.113.50'),
            '*echo success*' => Process::result(output: 'success'),
            '*--raw=/readyz*' => Process::result(output: 'ok'),
        ]);

        $this->artisan('cloud:scale scale-test --size=s-2vcpu-4gb --do-token=fake-do-token --force --no-interaction')
            ->assertExitCode(0);

        Process::assertNotRan(fn ($p): bool => str_contains(cloudScaleCommandLine($p), 'set-cluster') || str_contains(cloudScaleCommandLine($p), 'tls-san'));
    });
});
