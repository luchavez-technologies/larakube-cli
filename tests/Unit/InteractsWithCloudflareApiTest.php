<?php

use App\Http\Integrations\Cloudflare\Requests\GetZoneByNameRequest;
use App\Http\Integrations\Cloudflare\Requests\ListZonesRequest;
use App\Traits\InteractsWithCloudflareApi;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

function cloudflareApiHarness(): object
{
    return new class
    {
        use InteractsWithCloudflareApi;

        public function zoneId(string $zone, string $token): ?string
        {
            return $this->cloudflareZoneId($zone, $token);
        }

        public function listZones(string $token): array
        {
            return $this->cloudflareListZones($token);
        }
    };
}

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('cloudflareZoneId returns the zone id on a successful lookup', function (): void {
    Saloon::fake([
        GetZoneByNameRequest::class => MockResponse::make([
            'success' => true,
            'result' => [['id' => 'zone-123', 'name' => 'example.com']],
        ]),
    ]);

    expect(cloudflareApiHarness()->zoneId('example.com', 'test-token'))->toBe('zone-123');

    Saloon::assertSent(GetZoneByNameRequest::class);
});

test('cloudflareZoneId returns null when the zone does not exist', function (): void {
    Saloon::fake([
        GetZoneByNameRequest::class => MockResponse::make([
            'success' => true,
            'result' => [],
        ]),
    ]);

    expect(cloudflareApiHarness()->zoneId('missing.example', 'test-token'))->toBeNull();
});

test('cloudflareZoneId returns null on an HTTP-level failure', function (): void {
    Saloon::fake([
        GetZoneByNameRequest::class => MockResponse::make(['success' => false, 'errors' => []], 403),
    ]);

    expect(cloudflareApiHarness()->zoneId('example.com', 'wrong-token'))->toBeNull();
});

test('cloudflareZoneId returns null when the envelope reports success: false, even on HTTP 200', function (): void {
    Saloon::fake([
        GetZoneByNameRequest::class => MockResponse::make([
            'success' => false,
            'errors' => [['code' => 1000, 'message' => 'Invalid request']],
            'result' => null,
        ], 200),
    ]);

    expect(cloudflareApiHarness()->zoneId('example.com', 'test-token'))->toBeNull();
});

test('cloudflareListZones returns every zone on a single page', function (): void {
    Saloon::fake([
        ListZonesRequest::class => MockResponse::make([
            'success' => true,
            'result' => [
                ['id' => 'zone-1', 'name' => 'ourfridays.com'],
                ['id' => 'zone-2', 'name' => 'larakube.app'],
            ],
            'result_info' => ['total_pages' => 1],
        ]),
    ]);

    expect(cloudflareApiHarness()->listZones('test-token'))->toBe([
        'zone-1' => 'ourfridays.com',
        'zone-2' => 'larakube.app',
    ]);

    Saloon::assertSentCount(1);
});

test('cloudflareListZones follows pagination across multiple pages', function (): void {
    Saloon::fake([
        MockResponse::make([
            'success' => true,
            'result' => [['id' => 'zone-1', 'name' => 'ourfridays.com']],
            'result_info' => ['total_pages' => 2],
        ]),
        MockResponse::make([
            'success' => true,
            'result' => [['id' => 'zone-2', 'name' => 'larakube.app']],
            'result_info' => ['total_pages' => 2],
        ]),
    ]);

    expect(cloudflareApiHarness()->listZones('test-token'))->toBe([
        'zone-1' => 'ourfridays.com',
        'zone-2' => 'larakube.app',
    ]);

    Saloon::assertSentCount(2);
});

test('cloudflareListZones returns an empty array on failure', function (): void {
    Saloon::fake([
        ListZonesRequest::class => MockResponse::make(['success' => false, 'errors' => []], 403),
    ]);

    expect(cloudflareApiHarness()->listZones('wrong-token'))->toBe([]);
});
