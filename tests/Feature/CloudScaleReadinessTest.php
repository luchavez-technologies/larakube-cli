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
