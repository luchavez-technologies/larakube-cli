<?php

use App\Http\Integrations\Cloudflare\Requests\CreateDnsRecordRequest;
use App\Http\Integrations\Cloudflare\Requests\GetZoneByNameRequest;
use App\Http\Integrations\Cloudflare\Requests\ListDnsRecordsRequest;
use App\Http\Integrations\Cloudflare\Requests\ListZonesRequest;
use App\Http\Integrations\Cloudflare\Requests\PatchDnsRecordRequest;
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

        public function upsertTxtRecord(string $zoneId, string $token, string $name, string $content, int $ttl = 120): bool
        {
            return $this->cloudflareUpsertTxtRecord($zoneId, $token, $name, $content, $ttl);
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
        ListZonesRequest::class => MockResponse::make([
            'success' => true,
            'result' => [],
        ]),
    ]);

    expect(cloudflareApiHarness()->zoneId('missing.example', 'test-token'))->toBeNull();
});

test('cloudflareZoneId returns null on an HTTP-level failure', function (): void {
    Saloon::fake([
        GetZoneByNameRequest::class => MockResponse::make(['success' => false, 'errors' => []], 403),
        ListZonesRequest::class => MockResponse::make(['success' => false, 'errors' => []], 403),
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
        ListZonesRequest::class => MockResponse::make([
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

test('cloudflareUpsertTxtRecord creates a record when none exists yet', function (): void {
    Saloon::fake([
        ListDnsRecordsRequest::class => MockResponse::make(['success' => true, 'result' => []]),
        CreateDnsRecordRequest::class => MockResponse::make(['success' => true, 'result' => ['id' => 'rec-1']]),
    ]);

    expect(cloudflareApiHarness()->upsertTxtRecord('zone-1', 'test-token', '_challenge.example.com', 'abc'))->toBeTrue();

    Saloon::assertSent(fn ($request) => $request instanceof CreateDnsRecordRequest
        && $request->body()->get('type') === 'TXT'
        && $request->body()->get('name') === '_challenge.example.com'
        && $request->body()->get('content') === 'abc');
    Saloon::assertNotSent(PatchDnsRecordRequest::class);
});

test('cloudflareUpsertTxtRecord patches the existing record instead of duplicating it', function (): void {
    Saloon::fake([
        ListDnsRecordsRequest::class => MockResponse::make(['success' => true, 'result' => [['id' => 'rec-1']]]),
        PatchDnsRecordRequest::class => MockResponse::make(['success' => true]),
    ]);

    expect(cloudflareApiHarness()->upsertTxtRecord('zone-1', 'test-token', '_challenge.example.com', 'new-value'))->toBeTrue();

    Saloon::assertSent(fn ($request) => $request instanceof PatchDnsRecordRequest
        && $request->body()->get('content') === 'new-value');
    Saloon::assertNotSent(CreateDnsRecordRequest::class);
});

test('cloudflareUpsertTxtRecord returns false when the write fails', function (): void {
    Saloon::fake([
        ListDnsRecordsRequest::class => MockResponse::make(['success' => true, 'result' => []]),
        CreateDnsRecordRequest::class => MockResponse::make(['success' => false, 'errors' => []], 400),
    ]);

    expect(cloudflareApiHarness()->upsertTxtRecord('zone-1', 'test-token', '_challenge.example.com', 'abc'))->toBeFalse();
});
