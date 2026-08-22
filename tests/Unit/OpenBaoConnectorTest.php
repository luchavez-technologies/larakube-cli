<?php

use App\Http\Integrations\OpenBao\OpenBaoConnector;
use App\Http\Integrations\OpenBao\Requests\SysHealthRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('SysHealthRequest hits the port-forwarded local base URL unauthenticated', function (): void {
    Saloon::fake([
        SysHealthRequest::class => MockResponse::make(['initialized' => true, 'sealed' => false]),
    ]);

    $response = (new OpenBaoConnector(31201))->send(new SysHealthRequest);

    expect($response->json())->toBe(['initialized' => true, 'sealed' => false]);

    Saloon::assertSent(fn ($request, $response) => $response->getPendingRequest()->getUrl() === 'http://localhost:31201/v1/sys/health'
        && $response->getPendingRequest()->headers()->get('X-Vault-Token') === null);
});

test('OpenBaoConnector sends X-Vault-Token only when a token is given', function (): void {
    Saloon::fake([SysHealthRequest::class => MockResponse::make([])]);

    (new OpenBaoConnector(31201, 'root-token'))->send(new SysHealthRequest);

    Saloon::assertSent(fn ($request, $response) => $response->getPendingRequest()->headers()->get('X-Vault-Token') === 'root-token');
});
