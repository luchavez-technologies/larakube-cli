<?php

use App\Http\Integrations\OpenBao\OpenBaoConnector;
use App\Http\Integrations\OpenBao\Requests\DynamicNoBodyRequest;
use App\Http\Integrations\OpenBao\Requests\DynamicRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('DynamicNoBodyRequest hits the port-forwarded local base URL unauthenticated, no body attached', function (): void {
    Saloon::fake([
        DynamicNoBodyRequest::class => MockResponse::make(['initialized' => true, 'sealed' => false]),
    ]);

    $response = (new OpenBaoConnector(31201))->send(new DynamicNoBodyRequest('GET', '/v1/sys/health'));

    expect($response->json())->toBe(['initialized' => true, 'sealed' => false]);

    Saloon::assertSent(function ($request, $response) {
        $pending = $response->getPendingRequest();

        return $pending->getUrl() === 'http://localhost:31201/v1/sys/health'
            && $pending->getMethod()->value === 'GET'
            && $pending->headers()->get('X-Vault-Token') === null
            && $pending->body() === null;
    });
});

test('DynamicRequest carries a JSON body and the given X-Vault-Token', function (): void {
    Saloon::fake([DynamicRequest::class => MockResponse::make(['id' => 'ok'])]);

    (new OpenBaoConnector(31201, 'root-token'))
        ->send(new DynamicRequest('POST', '/v1/sys/mounts/secret', ['type' => 'kv-v2']));

    Saloon::assertSent(function ($request, $response) {
        $pending = $response->getPendingRequest();

        return $pending->getUrl() === 'http://localhost:31201/v1/sys/mounts/secret'
            && $pending->getMethod()->value === 'POST'
            && $pending->headers()->get('X-Vault-Token') === 'root-token'
            && $request->body()->get('type') === 'kv-v2';
    });
});

test('openBaoFake() dispatches by path, mirroring the old Http::fake() URL-pattern map', function (): void {
    Saloon::fake([
        DynamicNoBodyRequest::class => openBaoFake([
            '*/v1/sys/init' => ['initialized' => true],
            '*/v1/database/static-roles/*' => ['data' => ['role' => 'found']],
        ]),
    ]);

    $init = (new OpenBaoConnector(31201))->send(new DynamicNoBodyRequest('GET', '/v1/sys/init'));
    $role = (new OpenBaoConnector(31201))->send(new DynamicNoBodyRequest('GET', '/v1/database/static-roles/zitadel'));
    $unmatched = (new OpenBaoConnector(31201))->send(new DynamicNoBodyRequest('GET', '/v1/sys/seal-status'));

    expect($init->json())->toBe(['initialized' => true])
        ->and($role->json())->toBe(['data' => ['role' => 'found']])
        ->and($unmatched->json())->toBeEmpty();
});
