<?php

use App\Enums\SearchDriver;

test('scout driver has correct labels', function () {
    expect(SearchDriver::MEILISEARCH->getLabel())->toBe('Meilisearch (Self-hosted)')
        ->and(SearchDriver::TYPESENSE->getLabel())->toBe('Typesense (Self-hosted)')
        ->and(SearchDriver::DATABASE->getLabel())->toBe('Database (No extra infrastructure)');
});

test('scout driver has correct ports', function () {
    expect(SearchDriver::MEILISEARCH->port())->toBe(7700)
        ->and(SearchDriver::TYPESENSE->port())->toBe(8108)
        ->and(SearchDriver::DATABASE->port())->toBe(80);
});

test('scout driver pod names', function () {
    expect(SearchDriver::MEILISEARCH->getPodName())->toBe('meilisearch')
        ->and(SearchDriver::TYPESENSE->getPodName())->toBe('typesense')
        ->and(SearchDriver::DATABASE->getPodName())->toBe('database');
});

test('scout driver select options are valid', function () {
    $options = SearchDriver::getSelectOptions();
    expect($options)->toBeArray()
        ->and($options)->toHaveKey('meilisearch', 'Meilisearch (Self-hosted)');
});
