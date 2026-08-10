<?php

/**
 * Tests for InteractsWithOpenTofu's Process-backed leaves. The install flows
 * (offerTofuInstall/ensureUnzip's package-manager branches) mix real sudo,
 * platform detection, and Prompts confirms, and runTofu's streaming apply/
 * destroy/init calls are left to a real droplet smoke test.
 */

use App\Data\GlobalConfigData;
use App\State;
use App\Traits\InteractsWithOpenTofu;
use Illuminate\Support\Facades\Process;

function tofuHelper(): object
{
    return new class
    {
        use InteractsWithOpenTofu;

        public function resolve(): ?array
        {
            return $this->resolveTofuBinary();
        }

        public function output(array $bin, string $stack, string $key): ?string
        {
            return $this->tofuOutput($bin, $stack, $key);
        }

        public function env(string $stack, bool $isOpenTofu, array $extra = []): array
        {
            return $this->tofuEnv($stack, $isOpenTofu, $extra);
        }

        public function encryption(string $stack, bool $isOpenTofu): string
        {
            return $this->tofuEncryptionEnv($stack, $isOpenTofu);
        }

        public function remoteState(): ?array
        {
            return $this->remoteStateConfig();
        }

        public function writeFiles(string $stack, array $files): string
        {
            return $this->writeTofuFiles($stack, $files);
        }

        public function removeWorkdir(string $stack): void
        {
            $this->removeTofuWorkdir($stack);
        }
    };
}

test('resolveTofuBinary prefers a real tofu binary on PATH over terraform', function () {
    $fakeTofu = sys_get_temp_dir().'/fake-tofu-'.uniqid();
    file_put_contents($fakeTofu, "#!/bin/sh\necho fake-tofu\n");
    chmod($fakeTofu, 0755);

    try {
        Process::fake([
            'command -v tofu' => $fakeTofu."\n",
            'command -v terraform' => Process::result(output: '', exitCode: 1),
        ]);

        expect(tofuHelper()->resolve())->toBe(['path' => $fakeTofu, 'isOpenTofu' => true]);
    } finally {
        unlink($fakeTofu);
    }
});

test('resolveTofuBinary falls back to terraform when tofu is not found', function () {
    $fakeTerraform = sys_get_temp_dir().'/fake-terraform-'.uniqid();
    file_put_contents($fakeTerraform, "#!/bin/sh\necho fake-terraform\n");
    chmod($fakeTerraform, 0755);

    try {
        Process::fake([
            'command -v tofu' => Process::result(output: '', exitCode: 1),
            'command -v terraform' => $fakeTerraform."\n",
        ]);

        $res = tofuHelper()->resolve();
        expect($res)->not->toBeNull()
            ->and($res['path'])->toBeIn([$fakeTerraform, '/opt/homebrew/bin/tofu', '/usr/local/bin/tofu', '/usr/bin/tofu']);
    } finally {
        unlink($fakeTerraform);
    }
});

test('resolveTofuBinary is null when neither tofu nor terraform resolves to a real executable', function () {
    Process::fake([
        'command -v tofu' => Process::result(output: '', exitCode: 1),
        'command -v terraform' => Process::result(output: '', exitCode: 1),
    ]);

    if (collect(['/usr/local/bin/tofu', '/opt/homebrew/bin/tofu', '/home/linuxbrew/.linuxbrew/bin/tofu', '/usr/local/bin/terraform', '/opt/homebrew/bin/terraform', '/home/linuxbrew/.linuxbrew/bin/terraform'])->contains(fn ($p) => @is_executable($p))) {
        $this->markTestSkipped('tofu/terraform is actually installed at a fallback path on this machine.');
    }

    expect(tofuHelper()->resolve())->toBeNull();
});

test('tofuOutput returns the trimmed raw output value, or null on failure/empty', function () {
    // isOpenTofu: false so tofuEncryptionEnv() short-circuits before touching the
    // real global config (ensureTofuPassphrase()/save() would be a real side effect).
    $bin = ['path' => '/usr/local/bin/terraform', 'isOpenTofu' => false];
    $dir = home_path('.larakube/tofu/mystack');

    Process::fake(["*{$dir}* output -raw *" => "203.0.113.10\n"]);
    expect(tofuHelper()->output($bin, 'mystack', 'ip'))->toBe('203.0.113.10');

    Process::fake(["*{$dir}* output -raw *" => Process::result(output: '', exitCode: 1)]);
    expect(tofuHelper()->output($bin, 'mystack', 'ip'))->toBeNull();
});

test('tofu env vars travel via Process::env(), not the command string', function () {
    $bin = ['path' => '/usr/local/bin/terraform', 'isOpenTofu' => false];
    $dir = home_path('.larakube/tofu/mystack');
    State::$transientDoToken = 'dop_v1_transient-token-abc';

    Process::fake(["*{$dir}* output -raw *" => "203.0.113.10\n"]);
    tofuHelper()->output($bin, 'mystack', 'ip');

    Process::assertRan(function ($process) {
        return ($process->environment['TF_IN_AUTOMATION'] ?? null) === '1'
            && ($process->environment['TF_VAR_do_token'] ?? null) === 'dop_v1_transient-token-abc'
            && ! str_contains($process->command, 'TF_IN_AUTOMATION');
    });
});

test('tofuEnv skips the DO token when none is configured and merges extras', function () {
    $env = tofuHelper()->env('mystack', false, ['EXTRA_VAR' => 'yes']);

    expect($env)->not->toHaveKey('TF_VAR_do_token')
        ->and($env)->not->toHaveKey('TF_ENCRYPTION') // isOpenTofu: false
        ->and($env['EXTRA_VAR'])->toBe('yes')
        ->and($env['TF_IN_AUTOMATION'])->toBe('1');
});

test('LARAKUBE_TOFU_PASSPHRASE overrides the global-config passphrase and is never persisted', function () {
    putenv('LARAKUBE_TOFU_PASSPHRASE=orchestrator-supplied-passphrase');

    try {
        $hcl = tofuHelper()->encryption('mystack', true);

        expect($hcl)->toContain('passphrase = "orchestrator-supplied-passphrase"')
            // Nothing minted into the (test-HOME) global config.
            ->and(GlobalConfigData::load()->getTofuPassphrase('mystack'))->toBeNull();
    } finally {
        putenv('LARAKUBE_TOFU_PASSPHRASE');
    }
});

test('a too-short LARAKUBE_TOFU_PASSPHRASE throws instead of weakly encrypting state', function () {
    putenv('LARAKUBE_TOFU_PASSPHRASE=short');

    try {
        expect(fn () => tofuHelper()->encryption('mystack', true))->toThrow(RuntimeException::class);
    } finally {
        putenv('LARAKUBE_TOFU_PASSPHRASE');
    }
});

test('remoteStateConfig requires both bucket and endpoint', function () {
    try {
        expect(tofuHelper()->remoteState())->toBeNull();

        putenv('LARAKUBE_TOFU_STATE_BUCKET=my-bucket');
        expect(tofuHelper()->remoteState())->toBeNull();

        putenv('LARAKUBE_TOFU_STATE_ENDPOINT=https://nyc3.digitaloceanspaces.com');
        expect(tofuHelper()->remoteState())->toBe([
            'bucket' => 'my-bucket',
            'endpoint' => 'https://nyc3.digitaloceanspaces.com',
            'region' => 'us-east-1',
        ]);

        putenv('LARAKUBE_TOFU_STATE_REGION=nyc3');
        expect(tofuHelper()->remoteState()['region'])->toBe('nyc3');
    } finally {
        putenv('LARAKUBE_TOFU_STATE_BUCKET');
        putenv('LARAKUBE_TOFU_STATE_ENDPOINT');
        putenv('LARAKUBE_TOFU_STATE_REGION');
    }
});

test('writeTofuFiles writes backend.tf when remote state is configured and removes it when not', function () {
    try {
        putenv('LARAKUBE_TOFU_STATE_BUCKET=my-bucket');
        putenv('LARAKUBE_TOFU_STATE_ENDPOINT=https://nyc3.digitaloceanspaces.com');

        $dir = tofuHelper()->writeFiles('backend-test-stack', ['main.tf' => '# hcl']);

        expect(file_get_contents($dir.'/backend.tf'))
            ->toContain('bucket = "my-bucket"')
            ->toContain('key    = "tofu-state/backend-test-stack/terraform.tfstate"')
            ->toContain('use_lockfile = true');

        putenv('LARAKUBE_TOFU_STATE_BUCKET');
        putenv('LARAKUBE_TOFU_STATE_ENDPOINT');

        tofuHelper()->writeFiles('backend-test-stack', ['main.tf' => '# hcl']);
        expect(file_exists($dir.'/backend.tf'))->toBeFalse();
    } finally {
        putenv('LARAKUBE_TOFU_STATE_BUCKET');
        putenv('LARAKUBE_TOFU_STATE_ENDPOINT');
    }
});

test('removeTofuWorkdir deletes the stack dir entirely, including a nested .terraform/ cache and stale state', function () {
    // Defensive: a prior interrupted run of this exact test could have left the
    // fixture dir behind (this is real filesystem I/O against the test HOME).
    tofuHelper()->removeWorkdir('destroy-then-recreate');

    $dir = tofuHelper()->writeFiles('destroy-then-recreate', ['main.tf' => '# hcl']);
    mkdir($dir.'/.terraform/providers', 0755, true);
    file_put_contents($dir.'/.terraform/providers/plugin.bin', 'fake-provider-binary');
    file_put_contents($dir.'/.terraform.lock.hcl', '# lockfile');
    file_put_contents($dir.'/terraform.tfstate', 'encrypted-state-under-a-passphrase-about-to-be-forgotten');

    expect(is_dir($dir))->toBeTrue();

    tofuHelper()->removeWorkdir('destroy-then-recreate');

    expect(is_dir($dir))->toBeFalse();
});

test('removeTofuWorkdir is a no-op when the stack was never provisioned', function () {
    // No exception, no warning — just nothing to do.
    tofuHelper()->removeWorkdir('never-existed-stack');
    expect(true)->toBeTrue();
});
