<?php

use App\Enums\ClusterTool;
use App\Support\Templates\ImageDigestResolver;
use App\Support\Templates\ReleaseManifestBuilder;
use App\Support\Templates\TemplateApp;

test('Plex Commons and every Cluster Tool resolve to a template app, by slug and by folder', function (): void {
    expect(TemplateApp::find('plex')?->folder)->toBe('plex')
        ->and(TemplateApp::find('plex')?->tool)->toBeNull()
        ->and(TemplateApp::forFolder('plex')?->slug)->toBe('plex')
        ->and(TemplateApp::find('n8n')?->tool)->toBe(ClusterTool::N8N)
        ->and(TemplateApp::forFolder('wordpress')?->slug)->toBe('wordpress')
        ->and(TemplateApp::find('nope'))->toBeNull();
});

test('a Plex Commons release pins every engine image and carries the Commons template', function (): void {
    $digests = Mockery::mock(ImageDigestResolver::class);
    $digests->shouldReceive('pin')->andReturnUsing(fn (string $image): string => $image.'@sha256:'.str_repeat('a', 64));

    $built = app(ReleaseManifestBuilder::class)->build(TemplateApp::find('plex'), null, '1.0.0', false, $digests);

    expect($built['manifest']['app'])->toBe('plex')
        ->and($built['manifest']['contract'])->toBe(1)
        ->and(array_keys($built['manifest']['images']))->toContain('postgres', 'mariadb', 'redis', 'seaweedfs')
        ->and($built['manifest']['files'])->toHaveKey('commons.blade.php')
        ->and($built['manifest']['services'])->toBeEmpty();
});
