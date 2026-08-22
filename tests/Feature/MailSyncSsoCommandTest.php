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
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart 1/1 1 1 1d'),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel 1/1 1 1 1d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('adminpass')),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('pat123')),
        '*get pod -l app=stalwart*' => Process::result(output: 'stalwart-0'),
        '*exec *' => Process::sequence([
            Process::result(output: json_encode([
                'methodResponses' => [
                    ['x:Account/query', ['ids' => ['acc1']], 'c0'],
                    ['x:Account/get', ['list' => []], 'c1'],
                ],
            ])),
            Process::result(output: json_encode([
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
            ])),
        ]),
    ]);

    Saloon::fake([
        CreateUserRequest::class => MockResponse::make(['userId' => 'user-123'], 200),
    ]);

    $this->artisan('mail:sync-sso local')
        ->assertExitCode(0)
        ->expectsOutputToContain('Stalwart → Zitadel SSO Sync Complete');
});

test('mail:sync-sso refuses when stalwart is not installed', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('mail:sync-sso local')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});
