<?php

use App\Http\Integrations\Cloudflare\Requests\GetZoneSettingRequest;
use App\Http\Integrations\Cloudflare\Requests\ListDnsRecordsRequest;
use App\Http\Integrations\Cloudflare\Requests\ListZonesRequest;
use App\Http\Integrations\Cloudflare\Requests\VerifyTokenRequest;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('dns:verify outputs json when --json is passed', function (): void {
    Saloon::fake([
        VerifyTokenRequest::class => MockResponse::make(['success' => true, 'result' => ['status' => 'active']]),
        ListZonesRequest::class => MockResponse::make(['success' => true, 'result' => [['id' => 'z1', 'name' => 'domain.com']], 'result_info' => ['total_pages' => 1]]),
        ListDnsRecordsRequest::class => MockResponse::make(['success' => true, 'result' => []]),
        GetZoneSettingRequest::class => MockResponse::make(['success' => true, 'result' => ['value' => 'strict']]),
    ]);

    $exit = Artisan::call('dns:verify', [
        '--provider' => 'cloudflare',
        '--token' => 'my-test-token',
        '--json' => true,
    ]);

    expect($exit)->toBe(0);

    $output = trim(Artisan::output());
    $data = json_decode($output, true);

    expect($data)->toBeArray()
        ->and($data['valid'])->toBeTrue()
        ->and($data['zones'])->toBe(['domain.com'])
        ->and($data['checks']['dns_edit'])->toBeTrue()
        ->and($data['checks']['ssl_read'])->toBeTrue();
});

test('dns:verify returns exit code 1 on invalid token in --json mode', function (): void {
    Saloon::fake([
        VerifyTokenRequest::class => MockResponse::make(['success' => false, 'errors' => [['message' => 'bad token']]], 401),
    ]);

    $exit = Artisan::call('dns:verify', [
        '--provider' => 'cloudflare',
        '--token' => 'bad-token',
        '--json' => true,
    ]);

    expect($exit)->toBe(1);

    $output = trim(Artisan::output());
    $data = json_decode($output, true);

    expect($data['valid'])->toBeFalse()
        ->and($data['checks']['token_active'])->toBeFalse();
});
