<?php

use App\Data\GlobalConfigData;
use App\Http\Integrations\Cloudflare\Requests\GetUserRequest as CloudflareGetUserRequest;
use App\Http\Integrations\Cloudflare\Requests\TokenExchangeRequest as CloudflareTokenExchangeRequest;
use App\Http\Integrations\DigitalOcean\Requests\GetAccountRequest;
use App\Http\Integrations\DigitalOcean\Requests\TokenExchangeRequest;
use App\Services\BrowserOpener;
use App\Services\Cloud\FakeLoopbackHttpServer;
use App\Services\Cloud\LoopbackHttpServerInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

test('--json signs in to Google Cloud through gcloud without a browser and reports success', function (): void {
    Process::fake([
        'command -v gcloud' => Process::result(output: '/usr/bin/gcloud'),
        '*auth login*' => Process::result(output: ''),
    ]);

    expect(Artisan::call('cloud:login', ['--provider' => 'gcp', '--json' => true]))->toBe(0)
        ->and(json_decode(Arr::last(array_filter(explode("\n", trim(Artisan::output())))), true))->toBe(['success' => true, 'provider' => 'gcp']);

    Process::assertRan(fn ($process): bool => str_contains($process->command, '/usr/bin/gcloud auth login --no-launch-browser --update-adc'));
});

test('a missing provider flag stops a non-interactive run', function (): void {
    Process::fake();

    expect(Artisan::call('cloud:login', ['--json' => true, '--no-interaction' => true]))->toBe(1)
        ->and(json_decode(Arr::last(array_filter(explode("\n", trim(Artisan::output())))), true))->toMatchArray(['success' => false]);
});

test('a missing gcloud is reported with the command that installs it', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1)]);

    expect(Artisan::call('cloud:login', ['--provider' => 'gcp', '--json' => true]))->toBe(1)
        ->and(json_decode(Arr::last(array_filter(explode("\n", trim(Artisan::output())))), true)['error'])->toContain('larakube setup --tools=gcloud');
});

test('a sign-in gcloud did not finish fails', function (): void {
    Process::fake([
        'command -v gcloud' => Process::result(output: '/usr/bin/gcloud'),
        '*auth login*' => Process::result(exitCode: 1),
    ]);

    expect(Artisan::call('cloud:login', ['--provider' => 'gcp', '--json' => true]))->toBe(1);
});

function cloudLoginDoFakeServer(): FakeLoopbackHttpServer
{
    $fake = new FakeLoopbackHttpServer;
    app()->bind(LoopbackHttpServerInterface::class, fn () => $fake);

    return $fake;
}

afterEach(function (): void {
    MockClient::destroyGlobal();
    Str::createRandomStringsNormally();
});

test('--provider=do opens the browser, exchanges the code with no client_secret, and stores the DO account by its uuid', function (): void {
    Process::fake(['*' => Process::result()]); // BrowserOpener::open()

    Str::createRandomStringsUsing(fn (): string => 'fixed-test-state-aaaaaaaaaaaaaaaa');

    $server = cloudLoginDoFakeServer();
    $server->queueResponse(['code' => 'the-auth-code', 'state' => 'fixed-test-state-aaaaaaaaaaaaaaaa']);

    Saloon::fake([
        TokenExchangeRequest::class => MockResponse::make(['access_token' => 'do-access', 'refresh_token' => 'do-refresh', 'expires_in' => 3600]),
        GetAccountRequest::class => MockResponse::make(['account' => ['uuid' => 'do-uuid-1', 'email' => 'jamescarlo@example.com']]),
    ]);

    expect(Artisan::call('cloud:login', ['--provider' => 'do', '--json' => true]))->toBe(0);

    $result = json_decode(Arr::last(array_filter(explode("\n", trim(Artisan::output())))), true);
    expect($result)->toBe(['success' => true, 'provider' => 'do', 'account' => 'jamescarlo@example.com']);

    Saloon::assertSent(function ($request) {
        if (! $request instanceof TokenExchangeRequest) {
            return false;
        }

        $body = $request->body()->all();

        return $body['code'] === 'the-auth-code' && ! array_key_exists('client_secret', $body);
    });

    $account = GlobalConfigData::load()->getDefaultCloudAccount('do');
    expect($account['providerAccountId'])->toBe('do-uuid-1')
        ->and($account['token'])->toBe('do-access')
        ->and($account['refreshToken'])->toBe('do-refresh')
        ->and($account['authMethod'])->toBe('oauth');
});

test('--provider=do signing in with the SAME DO account again refreshes it instead of creating a second account', function (): void {
    Process::fake(['*' => Process::result()]);
    Str::createRandomStringsUsing(fn (): string => 'fixed-test-state-aaaaaaaaaaaaaaaa');

    $server = cloudLoginDoFakeServer();
    $server->queueResponse(['code' => 'code-1', 'state' => 'fixed-test-state-aaaaaaaaaaaaaaaa']);
    Saloon::fake([
        TokenExchangeRequest::class => MockResponse::make(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600]),
        GetAccountRequest::class => MockResponse::make(['account' => ['uuid' => 'do-uuid-1', 'email' => 'same@example.com']]),
    ]);
    Artisan::call('cloud:login', ['--provider' => 'do', '--json' => true]);

    $server->queueResponse(['code' => 'code-2', 'state' => 'fixed-test-state-aaaaaaaaaaaaaaaa']);
    Saloon::fake([
        TokenExchangeRequest::class => MockResponse::make(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 3600]),
        GetAccountRequest::class => MockResponse::make(['account' => ['uuid' => 'do-uuid-1', 'email' => 'same@example.com']]),
    ]);
    Artisan::call('cloud:login', ['--provider' => 'do', '--json' => true]);

    $accounts = GlobalConfigData::load()->getCloudAccounts('do');
    expect($accounts)->toHaveCount(1)
        ->and($accounts[0]['token'])->toBe('access-2');
});

test('--provider=do times out cleanly when nothing ever hits the local callback', function (): void {
    Process::fake(['*' => Process::result()]);

    $server = cloudLoginDoFakeServer();
    $server->queueResponse(null); // simulates the real server's timeout

    expect(Artisan::call('cloud:login', ['--provider' => 'do', '--json' => true]))->toBe(1);

    $result = json_decode(Arr::last(array_filter(explode("\n", trim(Artisan::output())))), true);
    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('No browser response');
});

test('--provider=do discards a callback whose state does not match, rather than exchanging it', function (): void {
    Process::fake(['*' => Process::result()]);
    Str::createRandomStringsUsing(fn (): string => 'the-real-state-aaaaaaaaaaaaaaaaa');

    Saloon::fake([]);

    $server = cloudLoginDoFakeServer();
    $server->queueResponse(['code' => 'attacker-code', 'state' => 'forged-state']);

    expect(Artisan::call('cloud:login', ['--provider' => 'do', '--json' => true]))->toBe(1);

    Saloon::assertNothingSent();
});

test('--provider=cloudflare opens the browser, exchanges the code with no client_secret, and stores the account by its user id', function (): void {
    Process::fake(['*' => Process::result()]);
    Str::createRandomStringsUsing(fn (): string => 'fixed-test-state-bbbbbbbbbbbbbbbb');

    $server = cloudLoginDoFakeServer();
    $server->queueResponse(['code' => 'the-auth-code', 'state' => 'fixed-test-state-bbbbbbbbbbbbbbbb']);

    Saloon::fake([
        CloudflareTokenExchangeRequest::class => MockResponse::make(['access_token' => 'cf-access', 'refresh_token' => 'cf-refresh', 'expires_in' => 3600]),
        CloudflareGetUserRequest::class => MockResponse::make(['result' => ['id' => 'cf-user-1', 'email' => 'jamescarlo@example.com'], 'success' => true]),
    ]);

    expect(Artisan::call('cloud:login', ['--provider' => 'cloudflare', '--json' => true]))->toBe(0);

    $result = json_decode(Arr::last(array_filter(explode("\n", trim(Artisan::output())))), true);
    expect($result)->toBe(['success' => true, 'provider' => 'cloudflare', 'account' => 'jamescarlo@example.com']);

    Saloon::assertSent(function ($request) {
        if (! $request instanceof CloudflareTokenExchangeRequest) {
            return false;
        }

        $body = $request->body()->all();

        return $body['code'] === 'the-auth-code' && ! array_key_exists('client_secret', $body);
    });

    $account = GlobalConfigData::load()->getDefaultCloudAccount('cloudflare');
    expect($account['providerAccountId'])->toBe('cf-user-1')
        ->and($account['token'])->toBe('cf-access')
        ->and($account['refreshToken'])->toBe('cf-refresh')
        ->and($account['authMethod'])->toBe('oauth');
});

test('--provider=cloudflare signing in with the SAME account again refreshes it instead of creating a second account', function (): void {
    Process::fake(['*' => Process::result()]);
    Str::createRandomStringsUsing(fn (): string => 'fixed-test-state-bbbbbbbbbbbbbbbb');

    $server = cloudLoginDoFakeServer();
    $server->queueResponse(['code' => 'code-1', 'state' => 'fixed-test-state-bbbbbbbbbbbbbbbb']);
    Saloon::fake([
        CloudflareTokenExchangeRequest::class => MockResponse::make(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600]),
        CloudflareGetUserRequest::class => MockResponse::make(['result' => ['id' => 'cf-user-1', 'email' => 'same@example.com'], 'success' => true]),
    ]);
    Artisan::call('cloud:login', ['--provider' => 'cloudflare', '--json' => true]);

    $server->queueResponse(['code' => 'code-2', 'state' => 'fixed-test-state-bbbbbbbbbbbbbbbb']);
    Saloon::fake([
        CloudflareTokenExchangeRequest::class => MockResponse::make(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 3600]),
        CloudflareGetUserRequest::class => MockResponse::make(['result' => ['id' => 'cf-user-1', 'email' => 'same@example.com'], 'success' => true]),
    ]);
    Artisan::call('cloud:login', ['--provider' => 'cloudflare', '--json' => true]);

    $accounts = GlobalConfigData::load()->getCloudAccounts('cloudflare');
    expect($accounts)->toHaveCount(1)
        ->and($accounts[0]['token'])->toBe('access-2');
});
