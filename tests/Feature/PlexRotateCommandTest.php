<?php

use App\Enums\CommonsSecret;
use Illuminate\Support\Facades\Process;

/** A Commons spec with Postgres enabled and one tenant allocated. */
function rotateFakes(array $overrides = []): array
{
    return array_merge([
        '*get configmap plex-commons*' => Process::result(
            output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]]),
        ),
        '*get configmap plex-registry*' => Process::result(
            output: (string) json_encode(['tenants' => ['demo-production' => ['db' => 'demo-production', 'db_service' => 'postgres']]]),
        ),
        // No infisical-bootstrap Secret → literal fallback mode.
        '*infisical-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*exec *' => Process::result(output: 'ALTER ROLE'),
        '*apply -f*' => Process::result(output: 'configured'),
        '*' => Process::result(output: ''),
    ], $overrides);
}

test('plex:rotate refuses when there is no Commons', function () {
    Process::fake([
        '*get configmap plex-commons*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('plex:rotate local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('No Plex Commons found');
});

test('plex:rotate rejects an unknown credential kind by name', function () {
    Process::fake(rotateFakes());

    $this->artisan('plex:rotate local --only=nope --force')
        ->assertExitCode(1)
        ->expectsOutputToContain("Unknown credential kind 'nope'")
        ->expectsOutputToContain('db, s3, admin, tools');
});

test('without Infisical it says plainly that a redeploy is required', function () {
    // The whole point of the feature is that the weak mode is never mistaken
    // for the strong one.
    Process::fake(rotateFakes());

    $this->artisan('plex:rotate local --only=db --force')
        ->expectsOutputToContain('Literal rotation')
        ->expectsOutputToContain('redeploy IS required');
});

test('--tenant rejects a name that is not actually a tenant', function () {
    Process::fake(rotateFakes());

    $this->artisan('plex:rotate local --only=db --tenant=ghost --force')
        ->assertExitCode(1)
        ->expectsOutputToContain("'ghost' is not a tenant");
});

test('credentials that cannot be rotated yet are reported, never silently skipped', function () {
    Process::fake(rotateFakes());

    $this->artisan('plex:rotate local --only=s3,admin --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('not rotatable yet')
        ->expectsOutputToContain('not rotated automatically yet');
});

test('the per-tenant Infisical key is namespaced so two tenants never collide', function () {
    $a = CommonsSecret::TENANT_DB->infisicalKey('shop-production');
    $b = CommonsSecret::TENANT_DB->infisicalKey('blog-production');

    expect($a)->toBe('PLEX_SHOP_PRODUCTION_DB_PASSWORD')
        ->and($b)->toBe('PLEX_BLOG_PRODUCTION_DB_PASSWORD')
        ->and($a)->not->toBe($b);
});

test('only the tenant database is per-tenant; the rest are cluster-wide', function () {
    expect(CommonsSecret::TENANT_DB->isPerTenant())->toBeTrue()
        ->and(CommonsSecret::COMMONS_S3->isPerTenant())->toBeFalse()
        ->and(CommonsSecret::COMMONS_ADMIN->isPerTenant())->toBeFalse()
        ->and(CommonsSecret::TOOL_STORE->isPerTenant())->toBeFalse();
});

test('the admin password never leaks into an application env', function () {
    // It is an operator credential for the Commons superuser — an app that
    // received it would be able to read every other tenant's data.
    expect(CommonsSecret::COMMONS_ADMIN->envKeys())->toBe([]);
});
