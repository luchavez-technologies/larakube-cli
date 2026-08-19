<?php

/**
 * Renders the actual Plex Commons blade so a compile error or a broken @if
 * can't slip through to the droplet. Asserts the spec drives which services
 * appear in the manifest.
 */

use App\Enums\DatabaseDriver;
use App\Enums\SearchDriver;
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

test('the default Commons manifest has Postgres + Redis, embeds the spec, and omits Meili', function (): void {
    $yaml = plexManifest(plexHelper()->defaultCommonsSpec());

    expect($yaml)
        ->toContain('kind: ConfigMap')
        ->toContain('name: plex-commons')
        ->toContain('commons.json: |')          // spec is embedded (self-describing)
        ->toContain('name: postgres')
        ->toContain('image: postgres:17.9')
        ->toContain('claimName: postgres-data')
        ->toContain('name: redis')
        ->toContain('image: valkey/valkey:8.0-alpine')
        ->not->toContain('name: meilisearch');
});

test('enabling Meilisearch adds it to the manifest', function (): void {
    $spec = plexHelper()->normalizeCommonsSpec(['services' => ['meilisearch' => ['enabled' => true]]]);
    $yaml = plexManifest($spec);

    expect($yaml)
        ->toContain('image: '.SearchDriver::MEILISEARCH->getDockerImage())  // in lockstep with the enum, not a stale literal
        ->toContain('claimName: meilisearch-data');
});

test('enabling object storage adds the SeaweedFS S3 service to the manifest', function (): void {
    $spec = plexHelper()->normalizeCommonsSpec(['services' => ['seaweedfs' => ['enabled' => true]]]);
    $yaml = plexManifest($spec);

    expect($yaml)
        ->toContain('name: seaweedfs')
        ->toContain('image: '.StorageDriver::SEAWEEDFS->getDockerImage())
        ->toContain('claimName: seaweedfs-data')
        ->toContain('"-s3"');  // the S3 gateway is enabled
});

test('MinIO exposes its console port and gets a separate console Ingress when console_host is set', function (): void {
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

test('MinIO console_host is absent by default — no console Ingress without it', function (): void {
    $spec = plexHelper()->normalizeCommonsSpec(['services' => [
        'minio' => ['enabled' => true, 'host' => 'minio.test'],
    ]]);
    $yaml = plexManifest($spec);

    expect($yaml)->not->toContain('name: minio-console');
});

test('SeaweedFS exposes its master port and gets a separate admin Ingress when admin_host is set', function (): void {
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

test('SeaweedFS admin_host is absent by default — no admin Ingress without it', function (): void {
    $spec = plexHelper()->normalizeCommonsSpec(['services' => [
        'seaweedfs' => ['enabled' => true, 'host' => 's3.test'],
    ]]);
    $yaml = plexManifest($spec);

    expect($yaml)->not->toContain('name: seaweedfs-admin');
});

test('enabling Garage adds its Commons service (deployment, config, S3 Ingress) to the manifest', function (): void {
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

test('Garage is disabled by default — no manifest at all without enabling it', function (): void {
    $yaml = plexManifest(plexHelper()->defaultCommonsSpec());

    expect($yaml)->not->toContain('name: garage');
});

test('the pooler is off by default — the postgres Service still routes straight to the engine, no PgBouncer resources appear', function (): void {
    // The whole point of gating on pooler.enabled: a cluster that has never
    // turned this on must render byte-for-byte the same resource set as
    // before the pooler existed, so re-running plex:init/plex:resources is a
    // no-op for it.
    $yaml = plexManifest(plexHelper()->defaultCommonsSpec());

    expect($yaml)
        ->not->toContain('name: pgbouncer')
        ->not->toContain('name: postgres-primary');

    $postgresService = collect(explode("\n---\n", $yaml))
        ->first(fn ($doc) => str_contains($doc, 'kind: Service') && preg_match('/name: postgres\s*$/m', $doc));
    expect($postgresService)->toContain('app: postgres');
});

test('enabling the pooler adds PgBouncer and a direct postgres-primary route, and repoints the postgres Service at it', function (): void {
    $spec = plexHelper()->normalizeCommonsSpec(['services' => [
        'postgres' => ['pooler' => ['enabled' => true, 'mode' => 'session', 'poolSize' => 15, 'maxClients' => 250]],
    ]]);
    $yaml = plexManifest($spec);

    expect($yaml)
        ->toContain('name: pgbouncer')
        ->toContain('image: '.DatabaseDriver::POSTGRESQL->poolerImage())
        ->toContain('name: postgres-primary')
        // The pool knobs from the spec, not hardcoded literals.
        ->toContain('POOL_MODE')
        ->toContain('value: "session"')
        ->toContain('value: "15"')
        ->toContain('value: "250"')
        // auth_query, never a static userlist — dynamic tenants per the plan.
        ->toContain('AUTH_QUERY')
        ->toContain('pg_shadow');

    $docs = explode("\n---\n", $yaml);

    $postgresService = collect($docs)->first(fn ($doc) => str_contains($doc, 'kind: Service') && preg_match('/name: postgres\s*$/m', $doc));
    expect($postgresService)->toContain('app: pgbouncer');

    $primaryService = collect($docs)->first(fn ($doc) => str_contains($doc, 'kind: Service') && str_contains($doc, 'name: postgres-primary'));
    expect($primaryService)->toContain('app: postgres');

    // The Postgres Deployment itself is untouched by pooling — kubectl
    // exec-based admin paths (commonsAdminClient() et al.) target it by
    // Deployment name, not the Service, so it must never be renamed.
    expect($yaml)->toContain('name: postgres'."\n")->toContain('image: postgres:17.9');
});

test('the pooler config comes from the spec, not the target env, and the DB password is never inlined in plaintext', function (): void {
    $spec = plexHelper()->normalizeCommonsSpec(['services' => ['postgres' => ['pooler' => ['enabled' => true]]]]);
    $yaml = plexManifest($spec);

    expect($yaml)
        ->toContain('secretKeyRef')
        ->toContain('name: plex-admin')
        ->toContain('key: POSTGRES_PASSWORD')
        // $(POSTGRES_PASSWORD) is Kubernetes' own env-var interpolation
        // syntax, resolved by the kubelet — never a rendered plaintext value.
        ->toContain('$(POSTGRES_PASSWORD)')
        // Regression guard for the Blade @{{ escape trap: '@' immediately
        // before '{{' silently prints literal template text instead of
        // evaluating it.
        ->not->toContain('{{ \App\Enums\DatabaseDriver')
        ->toContain('@postgres-primary');
});

test('PgBouncer auth_type is scram-sha-256, matching Postgres 17s default password encryption', function (): void {
    // Regression guard: shipped as `md5` first, which broke PgBouncer's own
    // backend connection to Postgres with "cannot do SCRAM authentication:
    // wrong password type" — every tenant failed to connect at once
    // (confirmed live 2026-08-09, reverted within minutes). md5 and
    // scram-sha-256 credentials aren't interchangeable: Postgres 17 stores
    // (and pg_shadow.passwd returns, for auth_query) SCRAM verifiers by
    // default, not md5 hashes.
    $spec = plexHelper()->normalizeCommonsSpec(['services' => ['postgres' => ['pooler' => ['enabled' => true]]]]);
    $yaml = plexManifest($spec);

    expect($yaml)
        ->toContain('value: "scram-sha-256"')
        ->not->toContain('value: "md5"');
});
