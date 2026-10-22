<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Traits\ReadsEnvSources;
use Illuminate\Support\Facades\Process;

class ReadsEnvSourcesTestConsumer
{
    use ReadsEnvSources;

    public function testResolveSecretName(string $namespace, ?string $kubectl = null, ?ConfigData $config = null): string
    {
        return $this->resolveSecretName($namespace, $kubectl, $config);
    }

    public function testResolveConfigMapName(string $namespace, ?string $kubectl = null, ?ConfigData $config = null): string
    {
        return $this->resolveConfigMapName($namespace, $kubectl, $config);
    }

    public function testReadCanonicalSecret(string $namespace, ?string $kubectl = null): array
    {
        return $this->readCanonicalSecret($namespace, $kubectl);
    }

    public function testReadCanonicalConfig(string $namespace, ?string $kubectl = null): array
    {
        return $this->readCanonicalConfig($namespace, $kubectl);
    }
}

test('resolveSecretName uses app-secrets when present on cluster', function (): void {
    $consumer = new ReadsEnvSourcesTestConsumer;

    Process::fake([
        '*get secret app-secrets*' => Process::result(output: "secret/app-secrets\n"),
        '*' => Process::result(),
    ]);

    expect($consumer->testResolveSecretName('shop-production'))->toBe('app-secrets');
});

test('resolveSecretName falls back to laravel-secrets when present on cluster', function (): void {
    $consumer = new ReadsEnvSourcesTestConsumer;

    Process::fake([
        '*get secret app-secrets*' => Process::result(output: '', exitCode: 1),
        '*get secret laravel-secrets*' => Process::result(output: "secret/laravel-secrets\n"),
        '*' => Process::result(),
    ]);

    expect($consumer->testResolveSecretName('shop-production'))->toBe('laravel-secrets');
});

test('resolveSecretName defaults to app-secrets for non-Laravel frameworks on fresh cluster', function (): void {
    $consumer = new ReadsEnvSourcesTestConsumer;

    Process::fake([
        '*' => Process::result(output: '', exitCode: 1),
    ]);

    $astroConfig = ConfigData::from(['name' => 'astro-app', 'framework' => AppFramework::ASTRO->value]);
    expect($consumer->testResolveSecretName('astro-production', config: $astroConfig))->toBe('app-secrets');

    $laravelConfig = ConfigData::from(['name' => 'laravel-app', 'framework' => AppFramework::LARAVEL->value]);
    expect($consumer->testResolveSecretName('laravel-production', config: $laravelConfig))->toBe('laravel-secrets');
});

test('resolveConfigMapName defaults to app-config for non-Laravel frameworks on fresh cluster', function (): void {
    $consumer = new ReadsEnvSourcesTestConsumer;

    Process::fake([
        '*' => Process::result(output: '', exitCode: 1),
    ]);

    $astroConfig = ConfigData::from(['name' => 'astro-app', 'framework' => AppFramework::ASTRO->value]);
    expect($consumer->testResolveConfigMapName('astro-production', config: $astroConfig))->toBe('app-config');

    $laravelConfig = ConfigData::from(['name' => 'laravel-app', 'framework' => AppFramework::LARAVEL->value]);
    expect($consumer->testResolveConfigMapName('laravel-production', config: $laravelConfig))->toBe('laravel-config');
});

test('readCanonicalSecret checks app-secrets first and falls back to laravel-secrets', function (): void {
    $consumer = new ReadsEnvSourcesTestConsumer;

    // 1. app-secrets has data
    Process::fake([
        '*app-secrets*' => Process::result(output: json_encode([
            'data' => ['APP_KEY' => base64_encode('app-secret-val')],
        ])),
    ]);
    expect($consumer->testReadCanonicalSecret('shop-production'))->toBe(['APP_KEY' => 'app-secret-val']);

    // 2. app-secrets is empty/missing, falls back to laravel-secrets
    Process::fake([
        '*app-secrets*' => Process::result(output: '', exitCode: 1),
        '*laravel-secrets*' => Process::result(output: json_encode([
            'data' => ['APP_KEY' => base64_encode('legacy-secret-val')],
        ])),
    ]);
    expect($consumer->testReadCanonicalSecret('shop-production'))->toBe(['APP_KEY' => 'legacy-secret-val']);
});

test('readCanonicalConfig checks app-config first and falls back to laravel-config', function (): void {
    $consumer = new ReadsEnvSourcesTestConsumer;

    // 1. app-config has data
    Process::fake([
        '*app-config*' => Process::result(output: json_encode([
            'data' => ['APP_NAME' => 'ModernApp'],
        ])),
    ]);
    expect($consumer->testReadCanonicalConfig('shop-production'))->toBe(['APP_NAME' => 'ModernApp']);

    // 2. app-config is empty/missing, falls back to laravel-config
    Process::fake([
        '*app-config*' => Process::result(output: '', exitCode: 1),
        '*laravel-config*' => Process::result(output: json_encode([
            'data' => ['APP_NAME' => 'LegacyApp'],
        ])),
    ]);
    expect($consumer->testReadCanonicalConfig('shop-production'))->toBe(['APP_NAME' => 'LegacyApp']);
});
