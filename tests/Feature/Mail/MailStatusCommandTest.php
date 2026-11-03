<?php

use App\Http\Integrations\Zitadel\Requests\SearchEmailProvidersRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\Support\FakeToolRegistry;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('mail:status --json reports not installed when Stalwart has no Deployment', function (): void {
    FakeToolRegistry::install([]);
    Process::fake([
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(),
    ]);

    Artisan::call('mail:status', ['--json' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($output)->toBe([
        'success' => true,
        'installed' => false,
    ]);
});

test('mail:status reports not installed in human readable mode', function (): void {
    FakeToolRegistry::install([]);
    Process::fake([
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(),
    ]);

    $this->artisan('mail:status')
        ->assertExitCode(0)
        ->expectsOutputToContain('Stalwart is not deployed');
});

test('mail:status --json reports zero tools wired when Stalwart is installed but no tools are wired', function (): void {
    FakeToolRegistry::install([
        ['tool' => 'stalwart', 'instance' => '', 'host' => 'mail.example.com'],
    ]);

    Process::fake([
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(),
    ]);

    Artisan::call('mail:status', ['--json' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($output)->toBe([
        'success' => true,
        'installed' => true,
        'wiredTools' => [],
    ]);
});

test('mail:status --json correctly reports one tool wired with exact row attributes', function (): void {
    FakeToolRegistry::install([
        ['tool' => 'stalwart', 'instance' => '', 'host' => 'mail.example.com'],
        ['tool' => 'vaultwarden', 'instance' => 'vault-example-com', 'host' => 'vault.example.com'],
    ]);

    Process::fake([
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get deployment*vaultwarden*' => Process::result(output: 'vaultwarden-vault-example-com   1/1   1   1   10d'),
        '*vaultwarden-smtp*' => Process::result(output: base64_encode('mail.example.com')),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(),
    ]);

    Artisan::call('mail:status', ['--json' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($output['success'])->toBeTrue()
        ->and($output['installed'])->toBeTrue()
        ->and($output['wiredTools'])->toHaveCount(1)
        ->and($output['wiredTools'][0])->toBe([
            'tool' => 'Vaultwarden (Bitwarden Server)',
            'slug' => 'vaultwarden',
            'instance' => 'vault-example-com',
            'host' => 'vault.example.com',
            'wired' => true,
        ]);
});

test('mail:status renders table in non-json mode when tools are wired', function (): void {
    FakeToolRegistry::install([
        ['tool' => 'stalwart', 'instance' => '', 'host' => 'mail.example.com'],
        ['tool' => 'vaultwarden', 'instance' => 'vault-example-com', 'host' => 'vault.example.com'],
    ]);

    Process::fake([
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get deployment*vaultwarden*' => Process::result(output: 'vaultwarden-vault-example-com   1/1   1   1   10d'),
        '*vaultwarden-smtp*' => Process::result(output: base64_encode('mail.example.com')),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(),
    ]);

    $this->artisan('mail:status')
        ->assertExitCode(0)
        ->expectsOutputToContain('Stalwart mail is deployed.');
});

test('mail:status correctly handles Zitadel special-cased API wiring path', function (): void {
    FakeToolRegistry::install([
        ['tool' => 'stalwart', 'instance' => '', 'host' => 'mail.example.com'],
        ssoRegistryRow(),
    ]);

    Process::fake([
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get deployment zitadel-sso-example-com*' => Process::result(output: 'zitadel-sso-example-com   1/1   1   1   10d'),
        '*get secret zitadel-secrets-sso-example-com*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        SearchEmailProvidersRequest::class => MockResponse::make([
            'result' => [
                ['id' => 'provider-123'],
            ],
        ]),
    ]);

    Artisan::call('mail:status', ['--json' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($output['success'])->toBeTrue()
        ->and($output['installed'])->toBeTrue()
        ->and($output['wiredTools'])->toHaveCount(1)
        ->and($output['wiredTools'][0])->toBe([
            'tool' => 'Zitadel (Identity Provider & SSO)',
            'slug' => 'zitadel',
            'instance' => 'sso-example-com',
            'host' => 'sso.example.com',
            'wired' => true,
        ]);
});

test('mail:status omits capable-and-installed tool when its wiring secret is absent', function (): void {
    FakeToolRegistry::install([
        ['tool' => 'stalwart', 'instance' => '', 'host' => 'mail.example.com'],
        ['tool' => 'vaultwarden', 'instance' => 'vault-example-com', 'host' => 'vault.example.com'],
        ['tool' => 'grafana', 'instance' => 'grafana-example-com', 'host' => 'grafana.example.com'],
        ssoRegistryRow(),
    ]);

    Process::fake([
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        // Both vaultwarden, grafana, and zitadel are installed
        '*get deployment*vaultwarden*' => Process::result(output: 'vaultwarden-vault-example-com   1/1   1   1   10d'),
        '*get deployment*grafana*' => Process::result(output: 'grafana-grafana-example-com   1/1   1   1   10d'),
        '*get deployment zitadel-sso-example-com*' => Process::result(output: 'zitadel-sso-example-com   1/1   1   1   10d'),
        '*get secret zitadel-secrets-sso-example-com*' => Process::result(output: base64_encode('zitadel-pat')),
        // ONLY vaultwarden has its secret present; grafana-smtp is absent (exitCode: 1)
        '*vaultwarden-smtp*' => Process::result(output: base64_encode('mail.example.com')),
        '*grafana-smtp*' => Process::result(output: '', exitCode: 1),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(),
    ]);

    // Zitadel has NO email provider configured
    Saloon::fake([
        SearchEmailProvidersRequest::class => MockResponse::make([
            'result' => [],
        ]),
    ]);

    Artisan::call('mail:status', ['--json' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($output['success'])->toBeTrue()
        ->and($output['installed'])->toBeTrue()
        ->and($output['wiredTools'])->toHaveCount(1)
        ->and($output['wiredTools'][0]['slug'])->toBe('vaultwarden');

    $slugs = array_column($output['wiredTools'], 'slug');
    expect($slugs)->not->toContain('grafana')
        ->and($slugs)->not->toContain('zitadel');
});
