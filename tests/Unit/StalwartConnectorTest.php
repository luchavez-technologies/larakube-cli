<?php

use App\Http\Integrations\Stalwart\Requests\JmapEchoRequest;
use App\Http\Integrations\Stalwart\StalwartConnector;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('JmapEchoRequest hits the port-forwarded local base URL with the given auth header', function (): void {
    Saloon::fake([
        JmapEchoRequest::class => MockResponse::make([
            'methodResponses' => [['Core/echo', ['ping' => 'pong'], 'c1']],
        ]),
    ]);

    $response = (new StalwartConnector(31301, 'Bearer api-key-1'))->send(new JmapEchoRequest(['ping' => 'pong']));

    expect($response->json('methodResponses.0.1'))->toBe(['ping' => 'pong']);

    Saloon::assertSent(function ($request, $response) {
        $pending = $response->getPendingRequest();

        return $pending->getUrl() === 'http://localhost:31301/jmap'
            && $pending->headers()->get('Authorization') === 'Bearer api-key-1'
            && $request->body()->get('methodCalls') === [['Core/echo', ['ping' => 'pong'], 'c1']];
    });
});
