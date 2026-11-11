<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

test('tool:catalog paginates official cluster tools with metadata and compatibility fields', function (): void {
    $exit = Artisan::call('tool:catalog --page=1 --per-page=5 --json');
    expect($exit)->toBe(0);

    $payload = json_decode(Artisan::output(), true);
    expect($payload)->toHaveKeys(['data', 'meta'])
        ->and($payload['data'])->toHaveCount(5)
        ->and($payload['meta']['current_page'])->toBe(1)
        ->and($payload['meta']['per_page'])->toBe(5)
        ->and($payload['meta']['total'])->toBeGreaterThan(15);

    $first = $payload['data'][0];
    expect($first)->toHaveKeys([
        'tool', 'name', 'brand', 'tagline', 'categories', 'source',
        'supportsNoPlex', 'commonsCapabilities', 'initFields',
    ])
        ->and($first['source'])->toBe('official')
        ->and($first['commonsCapabilities'])->toHaveKeys(['databases', 'plexDatabases', 'supportsSqlite']);
});

test('tool:catalog filters by query and category', function (): void {
    $exit = Artisan::call('tool:catalog --q=n8n --json');
    expect($exit)->toBe(0);

    $payload = json_decode(Artisan::output(), true);
    $slugs = array_column($payload['data'], 'tool');

    expect($slugs)->toContain('n8n');

    $n8n = collect($payload['data'])->firstWhere('tool', 'n8n');
    expect($n8n['supportsNoPlex'])->toBeTrue()
        ->and($n8n['commonsCapabilities']['plexDatabases'])->toBe(['postgresql'])
        ->and($n8n['commonsCapabilities']['supportsSqlite'])->toBeTrue();

    $catExit = Artisan::call('tool:catalog --category=database --json');
    expect($catExit)->toBe(0);

    $catPayload = json_decode(Artisan::output(), true);
    expect($catPayload['data'])->not->toBeEmpty();
    foreach ($catPayload['data'] as $row) {
        expect($row['categories'])->toContain('database');
    }
});

test('tool:catalog merges cached community marketplace submissions and supports source and updated-since filters', function (): void {
    $tempHome = sys_get_temp_dir().'/larakube_test_catalog_'.uniqid();
    File::ensureDirectoryExists($tempHome.'/.larakube');
    $originalHome = $_SERVER['HOME'] ?? null;

    $_SERVER['HOME'] = $tempHome;

    try {
        File::put($tempHome.'/.larakube/marketplace-cache.json', json_encode([
            [
                'tool' => 'community-erp',
                'name' => 'Community ERP',
                'tagline' => 'Open source ERP submitted via Cloud',
                'categories' => ['productivity'],
                'upvotesCount' => 42,
                'remoteUpdatedAt' => '2026-11-10T10:00:00Z',
                'supportsNoPlex' => true,
                'commonsCapabilities' => [
                    'databases' => ['postgresql', 'sqlite'],
                    'cache' => ['redis'],
                    'storage' => ['s3'],
                    'auth' => ['oidc'],
                    'mail' => ['smtp'],
                ],
                'initFields' => [],
            ],
            [
                'tool' => 'legacy-community-app',
                'name' => 'Legacy Community',
                'tagline' => 'Older submission',
                'categories' => ['devops'],
                'remoteUpdatedAt' => '2026-10-01T00:00:00Z',
                'commonsCapabilities' => ['databases' => ['mysql']],
            ],
        ]));

        $exit = Artisan::call('tool:catalog --source=community --updated-since=2026-11-01T00:00:00Z --json');
        expect($exit)->toBe(0);

        $payload = json_decode(Artisan::output(), true);
        expect($payload['data'])->toHaveCount(1)
            ->and($payload['data'][0]['tool'])->toBe('community-erp')
            ->and($payload['data'][0]['source'])->toBe('community')
            ->and($payload['data'][0]['upvotesCount'])->toBe(42)
            ->and($payload['data'][0]['commonsCapabilities']['plexDatabases'])->toBe(['postgresql'])
            ->and($payload['data'][0]['commonsCapabilities']['supportsSqlite'])->toBeTrue();
    } finally {
        if ($originalHome !== null) {
            $_SERVER['HOME'] = $originalHome;
        }
        File::deleteDirectory($tempHome);
    }
});
