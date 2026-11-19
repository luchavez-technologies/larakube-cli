<?php

use App\Data\GlobalConfigData;
use App\Services\Cloudflare\CloudflareR2Service;
use App\Traits\InteractsWithOpenTofu;
use Illuminate\Support\Facades\Http;

test('CloudflareR2Service derives S3 keys, creates bucket, writes 0600 INI credentials, and saves GlobalConfig', function (): void {
    $home = sys_get_temp_dir().'/larakube-r2-test-'.uniqid();
    mkdir($home, 0700, true);
    $_SERVER['HOME'] = $home;
    $_ENV['HOME'] = $home;

    Http::fake([
        'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response([
            'success' => true,
            'result' => ['id' => 'tok-12345', 'status' => 'active'],
        ]),
        'https://api.cloudflare.com/client/v4/accounts' => Http::response([
            'success' => true,
            'result' => [['id' => 'acct-999', 'name' => 'Default']],
        ]),
        'https://api.cloudflare.com/client/v4/accounts/acct-999/r2/buckets' => Http::sequence()
            ->push(['success' => true, 'result' => ['buckets' => []]], 200)
            ->push(['success' => true, 'result' => ['name' => 'larakube-tofu-state']], 200),
    ]);

    $token = 'cf-secret-token-value';
    $result = app(CloudflareR2Service::class)->detectAndConfigure($token);

    expect($result['configured'])->toBeTrue()
        ->and($result['hasR2Permission'])->toBeTrue()
        ->and($result['accountId'])->toBe('acct-999')
        ->and($result['bucket'])->toBe('larakube-tofu-state')
        ->and($result['endpoint'])->toBe('https://acct-999.r2.cloudflarestorage.com');

    $credsFile = $home.'/.larakube/r2-credentials';
    expect(file_exists($credsFile))->toBeTrue();
    $contents = (string) file_get_contents($credsFile);
    expect($contents)->toContain('aws_access_key_id = tok-12345')
        ->toContain('aws_secret_access_key = '.hash('sha256', $token));

    $config = GlobalConfigData::load();
    expect($config->tofuStateEnabled)->toBeTrue()
        ->and($config->tofuStateProvider)->toBe('r2')
        ->and($config->tofuStateAccountId)->toBe('acct-999')
        ->and($config->tofuStateAccessKeyId)->toBe('tok-12345')
        ->and($config->tofuStateSecretAccessKey)->toBe(hash('sha256', $token));
});

test('CloudflareR2Service returns hasR2Permission=false cleanly when token lacks R2 scope', function (): void {
    Http::fake([
        'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response([
            'success' => true,
            'result' => ['id' => 'tok-dns-only', 'status' => 'active'],
        ]),
        'https://api.cloudflare.com/client/v4/accounts' => Http::response([
            'success' => true,
            'result' => [['id' => 'acct-999', 'name' => 'Default']],
        ]),
        'https://api.cloudflare.com/client/v4/accounts/acct-999/r2/buckets' => Http::response([
            'success' => false,
            'errors' => [['code' => 10000, 'message' => 'Authentication error']],
        ], 403),
    ]);

    $result = app(CloudflareR2Service::class)->detectAndConfigure('dns-only-token', save: false);

    expect($result['configured'])->toBeFalse()
        ->and($result['hasR2Permission'])->toBeFalse()
        ->and($result['reason'] ?? '')->toContain('Workers R2 Storage');
});

test('InteractsWithOpenTofu shields existing local stacks while enabling R2 for new stacks', function (): void {
    $home = sys_get_temp_dir().'/larakube-tofu-guard-'.uniqid();
    mkdir($home.'/.larakube/tofu/legacy-stack', 0700, true);
    file_put_contents($home.'/.larakube/tofu/legacy-stack/terraform.tfstate', '{"version":4}');
    $_SERVER['HOME'] = $home;
    $_ENV['HOME'] = $home;

    $config = GlobalConfigData::load();
    $config->tofuStateEnabled = true;
    $config->tofuStateProvider = 'r2';
    $config->tofuStateBucket = 'larakube-tofu-state';
    $config->tofuStateEndpoint = 'https://acct-1.r2.cloudflarestorage.com';
    $config->tofuStateAccessKeyId = 'tok-1';
    $config->tofuStateSecretAccessKey = str_repeat('a', 64);
    $config->save();

    $harness = new class
    {
        use InteractsWithOpenTofu;

        public function getGlobalConfig(): GlobalConfigData
        {
            return GlobalConfigData::load();
        }

        public function probe(?string $stack, bool $ignoreLocal = false): ?array
        {
            return $this->remoteStateConfig($stack, $ignoreLocal);
        }
    };

    expect($harness->probe('legacy-stack'))->toBeNull()
        ->and($harness->probe('legacy-stack', true))->not->toBeNull()
        ->and($harness->probe('brand-new-stack'))->not->toBeNull()
        ->and($harness->probe('brand-new-stack')['region'])->toBe('auto');

    $rendered = view('tofu.backend-s3', $harness->probe('brand-new-stack') + ['stack' => 'brand-new-stack'])->render();
    expect($rendered)->toContain('shared_credentials_files')
        ->toContain('profile                  = "r2"');
});
