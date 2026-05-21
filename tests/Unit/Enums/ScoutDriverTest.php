<?php

use App\Enums\ScoutDriver;

test('scout driver has correct labels', function () {
    expect(ScoutDriver::MEILISEARCH->getLabel())->toBe('Meilisearch (Self-hosted)')
        ->and(ScoutDriver::TYPESENSE->getLabel())->toBe('Typesense (Self-hosted)')
        ->and(ScoutDriver::DATABASE->getLabel())->toBe('Database (No extra infrastructure)');
});

test('scout driver has correct ports', function () {
    expect(ScoutDriver::MEILISEARCH->port())->toBe(7700)
        ->and(ScoutDriver::TYPESENSE->port())->toBe(8108)
        ->and(ScoutDriver::DATABASE->port())->toBe(80);
});

test('scout driver pod names', function () {
    expect(ScoutDriver::MEILISEARCH->getPodName())->toBe('meilisearch')
        ->and(ScoutDriver::TYPESENSE->getPodName())->toBe('typesense')
        ->and(ScoutDriver::DATABASE->getPodName())->toBe('database');
});

test('scout driver select options are valid', function () {
    $options = ScoutDriver::getSelectOptions();
    expect($options)->toBeArray()
        ->and($options)->toHaveKey('meilisearch', 'Meilisearch (Self-hosted)');
});
