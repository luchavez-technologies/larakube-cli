<?php

use App\Traits\InteractsWithZitadelApi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('zitadelAttachActionToFlowTrigger preserves existing actions and appends new action', function () {
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

test('zitadelAttachActionToFlowTrigger returns true early if action is already attached', function () {
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
