<?php

use App\Http\Integrations\Zitadel\Requests\CreateUserRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('mail:sync-sso imports existing stalwart accounts into zitadel sso', function (): void {
    ssoRegistered();
    Process::fake([
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart 1/1 1 1 1d'),
        '*get deployment zitadel-sso-example-com*' => Process::result(output: 'zitadel-sso-example-com 1/1 1 1 1d'),
        '*get secret stalwart-secrets*' => Process::result(output: base64_encode('adminpass')),
        '*get secret zitadel-secrets-sso-example-com*' => Process::result(output: base64_encode('pat123')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make([
            'methodResponses' => [
                ['x:Account/query', ['ids' => ['acc1']], 'c0'],
                ['x:Account/get', ['list' => []], 'c1'],
            ],
        ]),
        MockResponse::make([
            'methodResponses' => [
                [
                    'x:Account/get',
                    [
                        'list' => [
                            [
                                'id' => 'acc1',
                                'name' => 'john',
                                'domainId' => 'example.com',
                                'description' => 'John Doe',
                            ],
                        ],
                    ],
                    'c1',
                ],
            ],
        ]),
        CreateUserRequest::class => MockResponse::make(['userId' => 'user-123'], 200),
    ]);

    $this->artisan('mail:sync-sso local')
        ->assertExitCode(0)
        ->expectsOutputToContain('Stalwart → Zitadel SSO Sync Complete');
});

test('mail:sync-sso refuses when stalwart is not installed', function (): void {
    ssoRegistered();
    Process::fake([
        '*larakube.io/tool=mail*' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('mail:sync-sso local')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});
