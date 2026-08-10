<?php

use App\Data\InstanceData;
use App\Enums\ClusterTool;

test('InstanceData round-trips a raw registry entry onto its properties', function () {
    // The registry's own raw arrays use these exact camelCase keys (see
    // InteractsWithToolRegistry/ResolvesToolBranding) — matching
    // ConfigData/GlobalConfigData's convention of no name translation
    // between the Data class and its persisted JSON.
    $data = InstanceData::from([
        'tool' => 'data',
        'host' => 'data.example.com',
        'instance' => 'main',
        'aliases' => ['alt.example.com'],
        'brandName' => 'Acme CMS',
        'logoUrl' => 'https://example.com/logo.png',
        'adminEmail' => 'admin@example.com',
        'engine' => 'directus',
        'installedAt' => '2026-08-01T09:00:00+00:00',
        'updatedAt' => '2026-08-09T12:00:00+00:00',
    ]);

    expect($data->tool)->toBe('data')
        ->and($data->getTool())->toBe(ClusterTool::DATA)
        ->and($data->host)->toBe('data.example.com')
        ->and($data->instance)->toBe('main')
        ->and($data->aliases)->toBe(['alt.example.com'])
        ->and($data->brandName)->toBe('Acme CMS')
        ->and($data->logoUrl)->toBe('https://example.com/logo.png')
        ->and($data->adminEmail)->toBe('admin@example.com')
        ->and($data->engine)->toBe('directus')
        ->and($data->installedAt)->toBe('2026-08-01T09:00:00+00:00')
        ->and($data->updatedAt)->toBe('2026-08-09T12:00:00+00:00');
});

test('InstanceData stores dates as ISO-8601 strings, not raw timestamps', function () {
    $data = InstanceData::from([
        'tool' => 'sso',
        'installedAt' => '2026-08-01T09:00:00+00:00',
        'updatedAt' => '2026-08-01T09:00:00+00:00',
    ]);

    expect($data->installedAt)->toBeString()
        ->and($data->installedAt)->toBe('2026-08-01T09:00:00+00:00')
        ->and($data->updatedAt)->toBeString();
});

test('getTool() returns null for an unrecognized or missing tool value', function () {
    expect((new InstanceData(tool: 'not-a-real-tool'))->getTool())->toBeNull()
        ->and((new InstanceData)->getTool())->toBeNull();
});

test('adminEmail defaults to null for tools that never record one (e.g. mail, flow)', function () {
    $data = InstanceData::from([
        'tool' => 'flow',
        'host' => 'flow.example.com',
        'instance' => 'main',
    ]);

    expect($data->adminEmail)->toBeNull();
});

test('engine defaults to null for tools with no engine concept', function () {
    $data = InstanceData::from([
        'tool' => 'sso',
        'host' => 'sso.example.com',
        'instance' => 'main',
    ]);

    expect($data->engine)->toBeNull();
});
