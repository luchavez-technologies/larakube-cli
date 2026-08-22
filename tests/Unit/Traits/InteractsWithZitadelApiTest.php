<?php

use App\Http\Integrations\Zitadel\Requests\CreateUserRequest;
use App\Http\Integrations\Zitadel\Requests\SearchUsersRequest;
use App\Traits\InteractsWithZitadelApi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('zitadelAttachActionToFlowTrigger preserves existing actions and appends new action', function (): void {
    $trait = new class
    {
        use InteractsWithZitadelApi;

        public function attach(string $host, string $pat, int $flowType, int $triggerType, string $actionId): bool
        {
            return $this->zitadelAttachActionToFlowTrigger($host, $pat, $flowType, $triggerType, $actionId);
        }
    };

    Http::fake([
        'https://sso.test/management/v1/flows/2' => Http::response([
            'flow' => [
                'triggerActions' => [
                    [
                        'triggerType' => ['id' => '4'],
                        'actions' => [
                            ['id' => 'existing-action-1'],
                        ],
                    ],
                ],
            ],
        ]),
        'https://sso.test/management/v1/flows/2/trigger/4' => Http::response(['details' => ['sequence' => '1']]),
    ]);

    $result = $trait->attach('sso.test', 'pat123', 2, 4, 'new-action-2');

    expect($result)->toBeTrue();

    Http::assertSent(function (Request $request) {
        if ($request->url() === 'https://sso.test/management/v1/flows/2/trigger/4') {
            return $request['actionIds'] === ['existing-action-1', 'new-action-2'];
        }

        return true;
    });
});

test('zitadelCreateUser resolves and returns the existing user id when Zitadel reports the user already exists', function (): void {
    // Confirmed live (2026-08-20): a create call can fail with "User already
    // exists" even on what the caller believed was a first attempt — e.g. a
    // prior run's response never made it back to the CLI even though
    // Zitadel's write went through. This must resolve the existing user
    // rather than permanently failing the whole command on every retry.
    $trait = new class
    {
        use InteractsWithZitadelApi;

        public function create(string $host, string $pat, string $email): ?string
        {
            return $this->zitadelCreateUser($host, $pat, $email, 'Existing User', 'pw', 'org-1');
        }
    };

    Saloon::fake([
        CreateUserRequest::class => MockResponse::make([
            'code' => 6,
            'message' => 'User already exists (V3-DKcYh)',
        ], 409),
        SearchUsersRequest::class => MockResponse::make([
            'result' => [['userId' => 'existing-uid-789']],
        ]),
    ]);

    expect($trait->create('sso.test', 'pat123', 'existing@partner.example'))->toBe('existing-uid-789');
});

test('zitadelCreateUser returns null on a genuine failure unrelated to already-existing', function (): void {
    $trait = new class
    {
        use InteractsWithZitadelApi;

        public function create(string $host, string $pat, string $email): ?string
        {
            return $this->zitadelCreateUser($host, $pat, $email, 'New User', 'pw', 'org-1');
        }
    };

    Saloon::fake([
        CreateUserRequest::class => MockResponse::make([
            'code' => 3,
            'message' => 'Domain is already reserved and cannot be used (COMMAND-SFd21)',
        ], 400),
    ]);

    expect($trait->create('sso.test', 'pat123', 'nobody@partner.example'))->toBeNull();
    Saloon::assertNotSent(SearchUsersRequest::class);
});

test('zitadelAttachActionToFlowTrigger returns true early if action is already attached', function (): void {
    $trait = new class
    {
        use InteractsWithZitadelApi;

        public function attach(string $host, string $pat, int $flowType, int $triggerType, string $actionId): bool
        {
            return $this->zitadelAttachActionToFlowTrigger($host, $pat, $flowType, $triggerType, $actionId);
        }
    };

    Http::fake([
        'https://sso.test/management/v1/flows/2' => Http::response([
            'flow' => [
                'triggerActions' => [
                    [
                        'triggerType' => ['id' => '4'],
                        'actions' => [
                            ['id' => 'existing-action-1'],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $result = $trait->attach('sso.test', 'pat123', 2, 4, 'existing-action-1');

    expect($result)->toBeTrue();
    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://sso.test/management/v1/flows/2/trigger/4');
});
