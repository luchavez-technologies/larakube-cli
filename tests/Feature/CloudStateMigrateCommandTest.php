<?php

use App\Data\GlobalConfigData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

test('cloud:state:r2 --json configures R2 from Cloudflare token', function (): void {
    Http::fake([
        'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response([
            'success' => true,
            'result' => ['id' => 'tok-abc', 'status' => 'active'],
        ]),
        'https://api.cloudflare.com/client/v4/accounts' => Http::response([
            'success' => true,
            'result' => [['id' => 'acct-777']],
        ]),
        'https://api.cloudflare.com/client/v4/accounts/acct-777/r2/buckets' => Http::response([
            'success' => true,
            'result' => ['buckets' => [['name' => 'larakube-tofu-state']]],
        ]),
    ]);

    $this->artisan('cloud:state:r2 --token=my-cf-token --json')
        ->assertExitCode(0)
        ->expectsOutputToContain('"configured":true');
});

test('cloud:state:migrate writes backend.tf, runs tofu init -migrate-state, and archives local tfstate', function (): void {
    $home = sys_get_temp_dir().'/larakube-migrate-'.uniqid();
    mkdir($home.'/.larakube/tofu/prod-vps', 0700, true);
    mkdir($home.'/.larakube/bin', 0700, true);
    file_put_contents($home.'/.larakube/bin/tofu', "#!/bin/sh\nexit 0\n");
    chmod($home.'/.larakube/bin/tofu', 0755);
    file_put_contents($home.'/.larakube/tofu/prod-vps/terraform.tfstate', '{"version":4}');
    $_SERVER['HOME'] = $home;
    $_ENV['HOME'] = $home;

    $config = GlobalConfigData::load();
    $config->tofuStateEnabled = true;
    $config->tofuStateProvider = 'r2';
    $config->tofuStateBucket = 'larakube-tofu-state';
    $config->tofuStateEndpoint = 'https://acct-1.r2.cloudflarestorage.com';
    $config->tofuStateAccessKeyId = 'tok-1';
    $config->tofuStateSecretAccessKey = str_repeat('b', 64);
    $config->save();

    Process::fake([
        '*command -v tofu*' => Process::result(output: $home.'/.larakube/bin/tofu'),
        '*init -migrate-state*' => Process::result(output: 'Successfully configured the backend "s3"!'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('cloud:state:migrate prod-vps --force --json')
        ->assertExitCode(0)
        ->expectsOutputToContain('"prod-vps"');

    $dir = $home.'/.larakube/tofu/prod-vps';
    expect(file_exists($dir.'/backend.tf'))->toBeTrue()
        ->and(file_exists($dir.'/terraform.tfstate'))->toBeFalse()
        ->and(file_exists($dir.'/.local-backup/terraform.tfstate'))->toBeTrue();
});

test('cloud:state:migrate omits use_lockfile when local OpenTofu is older than 1.10', function (): void {
    $home = sys_get_temp_dir().'/larakube-migrate-162-'.uniqid();
    mkdir($home.'/.larakube/tofu/prod-vps', 0700, true);
    mkdir($home.'/.larakube/bin', 0700, true);
    file_put_contents($home.'/.larakube/bin/tofu', "#!/bin/sh\nexit 0\n");
    chmod($home.'/.larakube/bin/tofu', 0755);
    file_put_contents($home.'/.larakube/tofu/prod-vps/terraform.tfstate', '{"version":4}');
    $_SERVER['HOME'] = $home;
    $_ENV['HOME'] = $home;

    $config = GlobalConfigData::load();
    $config->tofuStateEnabled = true;
    $config->tofuStateProvider = 'r2';
    $config->tofuStateBucket = 'larakube-tofu-state';
    $config->tofuStateEndpoint = 'https://acct-1.r2.cloudflarestorage.com';
    $config->tofuStateAccessKeyId = 'tok-1';
    $config->tofuStateSecretAccessKey = str_repeat('b', 64);
    $config->save();

    Process::fake([
        '*command -v tofu*' => Process::result(output: $home.'/.larakube/bin/tofu'),
        '*--version*' => Process::result(output: "OpenTofu v1.6.2\non darwin_arm64"),
        '*init -migrate-state*' => Process::result(output: 'Successfully configured the backend "s3"!'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('cloud:state:migrate prod-vps --force --json')
        ->assertExitCode(0);

    $backendTf = (string) file_get_contents($home.'/.larakube/tofu/prod-vps/backend.tf');
    expect($backendTf)->not->toContain('use_lockfile')
        ->toContain('endpoints = {');
});

test('cloud:state:migrate rolls back backend.tf if tofu migration fails', function (): void {
    $home = sys_get_temp_dir().'/larakube-migrate-fail-'.uniqid();
    mkdir($home.'/.larakube/tofu/prod-vps', 0700, true);
    mkdir($home.'/.larakube/bin', 0700, true);
    file_put_contents($home.'/.larakube/bin/tofu', "#!/bin/sh\nexit 1\n");
    chmod($home.'/.larakube/bin/tofu', 0755);
    file_put_contents($home.'/.larakube/tofu/prod-vps/terraform.tfstate', '{"version":4}');
    $_SERVER['HOME'] = $home;
    $_ENV['HOME'] = $home;

    $config = GlobalConfigData::load();
    $config->tofuStateEnabled = true;
    $config->tofuStateProvider = 'r2';
    $config->tofuStateBucket = 'larakube-tofu-state';
    $config->tofuStateEndpoint = 'https://acct-1.r2.cloudflarestorage.com';
    $config->tofuStateAccessKeyId = 'tok-1';
    $config->tofuStateSecretAccessKey = str_repeat('b', 64);
    $config->save();

    Process::fake([
        '*command -v tofu*' => Process::result(output: $home.'/.larakube/bin/tofu'),
        '*init -migrate-state*' => Process::result(output: '', errorOutput: 'AccessDenied', exitCode: 1),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('cloud:state:migrate prod-vps --force --json')
        ->assertExitCode(1);

    $dir = $home.'/.larakube/tofu/prod-vps';
    expect(file_exists($dir.'/backend.tf'))->toBeFalse()
        ->and(file_exists($dir.'/terraform.tfstate'))->toBeTrue();
});
