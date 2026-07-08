<?php

/**
 * Tests for InteractsWithOpenTofu's Process-backed leaves. The install flows
 * (offerTofuInstall/ensureUnzip's package-manager branches) mix real sudo,
 * platform detection, and Prompts confirms, and runTofu's streaming apply/
 * destroy/init calls are left to a real droplet smoke test.
 */

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

        expect(tofuHelper()->resolve())->toBe(['path' => $fakeTerraform, 'isOpenTofu' => false]);
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
