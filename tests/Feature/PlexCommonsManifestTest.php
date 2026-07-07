<?php

/**
 * Renders the actual Plex Commons blade so a compile error or a broken @if
 * can't slip through to the droplet. Asserts the spec drives which services
 * appear in the manifest.
 */

use App\Enums\ScoutDriver;
use App\Enums\StorageDriver;
use App\Traits\InteractsWithPlex;

function plexManifest(array $spec): string
{
    $json = (string) json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    return view('k8s.plex.commons', [
        'spec' => $spec,
        'specJsonIndented' => preg_replace('/^/m', '    ', $json),
    ])->render();
}

function plexHelper(): object
{
    return new class
    {
        use InteractsWithPlex;
    };
}

test('the default Commons manifest has Postgres + Redis, embeds the spec, and omits Meili', function () {
    $yaml = plexManifest(plexHelper()->defaultCommonsSpec());

    expect($yaml)
        ->toContain('kind: ConfigMap')
        ->toContain('name: plex-commons')
        ->toContain('commons.json: |')          // spec is embedded (self-describing)
        ->toContain('name: postgres')
        ->toContain('image: postgres:17.9')
        ->toContain('claimName: postgres-data')
        ->toContain('name: redis')
        ->toContain('image: redis:7.4')
        ->not->toContain('name: meilisearch');
});

test('enabling Meilisearch adds it to the manifest', function () {
    $spec = plexHelper()->normalizeCommonsSpec(['services' => ['meilisearch' => ['enabled' => true]]]);
    $yaml = plexManifest($spec);

    expect($yaml)
        ->toContain('image: '.ScoutDriver::MEILISEARCH->getDockerImage())  // in lockstep with the enum, not a stale literal
        ->toContain('claimName: meilisearch-data');
});

test('enabling object storage adds the SeaweedFS S3 service to the manifest', function () {
    $spec = plexHelper()->normalizeCommonsSpec(['services' => ['seaweedfs' => ['enabled' => true]]]);
    $yaml = plexManifest($spec);

    expect($yaml)
        ->toContain('name: seaweedfs')
        ->toContain('image: '.StorageDriver::SEAWEEDFS->getDockerImage())
        ->toContain('claimName: seaweedfs-data')
        ->toContain('"-s3"');  // the S3 gateway is enabled
});

test('MinIO exposes its console port and gets a separate console Ingress when console_host is set', function () {
    // Regression guard: the S3 Ingress (minio-s3) only ever routed port 9000
    // (the S3 API) — visiting it in a browser expecting the web console
    // showed nothing useful, since the Service didn't even expose 9001 and
    // no Ingress routed to it.
    $spec = plexHelper()->normalizeCommonsSpec(['services' => [
        'minio' => ['enabled' => true, 'host' => 'minio.test', 'console_host' => 'minio-console.test'],
    ]]);
    $yaml = plexManifest($spec);

    expect($yaml)
        ->toContain('name: minio-console')
        ->toContain('host: minio-console.test')
        ->toContain('number: 9001');

    // The Service itself must expose 9001, not just 9000, for either Ingress
    // to be able to route to it.
    $serviceDoc = collect(explode("\n---\n", $yaml))
        ->first(fn ($doc) => str_contains($doc, 'kind: Service') && str_contains($doc, 'name: minio'));
    expect($serviceDoc)->toContain('port: 9001');
});

test('MinIO console_host is absent by default — no console Ingress without it', function () {
    $spec = plexHelper()->normalizeCommonsSpec(['services' => [
        'minio' => ['enabled' => true, 'host' => 'minio.test'],
    ]]);
    $yaml = plexManifest($spec);

    expect($yaml)->not->toContain('name: minio-console');
});

test('SeaweedFS exposes its master port and gets a separate admin Ingress when admin_host is set', function () {
    // Same gap as MinIO: the S3 Ingress only ever routed the S3 gateway port —
    // the master's own admin UI (always running, since `weed server` binds it
    // by default) was never exposed via Service or Ingress.
    $spec = plexHelper()->normalizeCommonsSpec(['services' => [
        'seaweedfs' => ['enabled' => true, 'host' => 's3.test', 'admin_host' => 'seaweedfs-admin.test'],
    ]]);
    $yaml = plexManifest($spec);

    expect($yaml)
        ->toContain('name: seaweedfs-admin')
        ->toContain('host: seaweedfs-admin.test')
        ->toContain('number: 9333');

    $serviceDoc = collect(explode("\n---\n", $yaml))
        ->first(fn ($doc) => str_contains($doc, 'kind: Service') && str_contains($doc, 'name: seaweedfs'));
    expect($serviceDoc)->toContain('port: 9333');
});

test('SeaweedFS admin_host is absent by default — no admin Ingress without it', function () {
    $spec = plexHelper()->normalizeCommonsSpec(['services' => [
        'seaweedfs' => ['enabled' => true, 'host' => 's3.test'],
    ]]);
    $yaml = plexManifest($spec);

    expect($yaml)->not->toContain('name: seaweedfs-admin');
});

test('enabling Garage adds its Commons service (deployment, config, S3 Ingress) to the manifest', function () {
    $spec = plexHelper()->normalizeCommonsSpec(['services' => [
        'garage' => ['enabled' => true, 'host' => 'garage.test'],
    ]]);
    $yaml = plexManifest($spec);

    expect($yaml)
        ->toContain('name: garage')
        ->toContain('image: '.StorageDriver::GARAGE->getDockerImage())
        ->toContain('claimName: garage-data')
        ->toContain('name: garage-config')
        ->toContain('replication_factor = 1')
        ->toContain('name: garage-s3')
        ->toContain('host: garage.test');

    // Path-style only for the Commons — no root_domain/virtual-host config,
    // unlike the per-project Garage ingress.
    expect($yaml)->not->toContain('root_domain =');
});

test('Garage is disabled by default — no manifest at all without enabling it', function () {
    $yaml = plexManifest(plexHelper()->defaultCommonsSpec());

    expect($yaml)->not->toContain('name: garage');
});
