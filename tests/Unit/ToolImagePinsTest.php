<?php

use App\Support\ToolImagePins;

test('an image reference reduces to its repository, whatever registry spelling or tag it uses', function (string $reference, string $repository): void {
    expect(ToolImagePins::repository($reference))->toBe($repository);
})->with([
    'bare official' => ['postgres:17', 'postgres'],
    'explicit docker.io library' => ['docker.io/library/postgres:17.9', 'postgres'],
    'docker hub user' => ['docker.io/grafana/grafana:10.4.2', 'grafana/grafana'],
    'digest' => ['ghcr.io/zitadel/zitadel:v4.16.1@sha256:abc', 'ghcr.io/zitadel/zitadel'],
    'registry with port' => ['registry.example.com:5000/team/app:1.2', 'registry.example.com:5000/team/app'],
    'no tag' => ['ghcr.io/org/app', 'ghcr.io/org/app'],
]);
