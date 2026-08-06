<?php

use App\Commands\Data\DataInitCommand;
use App\Commands\Data\DataRemoveCommand;
use App\Commands\Data\DataShowCommand;
use Illuminate\Support\Facades\Process;

test('data:init, data:show, and data:remove are registered', function () {
    $this->artisan('list --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('data:init')
        ->expectsOutputToContain('data:show')
        ->expectsOutputToContain('data:remove');
});

test('data:init deploys Directus with Postgres, Redis, and SeaweedFS S3', function () {
    Process::fake([
        '*plex-commons*' => Process::result(output: '{"services":{"postgres":{"enabled":true},"redis":{"enabled":true},"seaweedfs":{"enabled":true}}}'),
        '*plex-registry*' => Process::result(output: '{"tenants":{}}'),
        '*plex-admin*' => Process::result(output: base64_encode('s3-access-key')),
        '*data-secrets*' => Process::result(output: ''),
        '*create namespace*' => Process::result(output: 'namespace/larakube-shared created'),
        '*create secret*' => Process::result(output: 'secret created'),
        '*create configmap*' => Process::result(output: 'configmap created'),
        '*exec*deploy/postgres*' => Process::result(output: 'CREATE DATABASE'),
        '*apply*' => Process::result(output: 'deployment.apps/data-directus created'),
        '*rollout status*' => Process::result(output: 'deployment "data-directus" successfully rolled out'),
    ]);

    $this->artisan(DataInitCommand::class, [
        'environment' => 'local',
        '--engine' => 'directus',
        '--no-interaction' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('Directus Data / Headless CMS stack is live')
        ->expectsOutputToContain('https://data.');
});

test('data manifest wires the Commons Redis via the generic REDIS var, not CACHE_REDIS', function () {
    // Regression guard for a real incident (2026-08-05): Directus 12's cache/
    // system-cache/deployment-cache/lock-cache namespaces all read a single
    // generic REDIS var (see @directus/api/dist/cache.js) — CACHE_REDIS is
    // never read at all, so every namespace silently fell back to ioredis's
    // localhost default. Non-fatal (WARN, not ERROR) but every single HTTP
    // request paid a ~3.6s doomed-connection retry tax, confirmed live.
    $manifest = view('k8s.data.shared', [
        'engine' => 'directus',
        'host' => 'data.example.test',
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 4,
        'vpnOnly' => false,
        'isLocal' => true,
        'proxied' => false,
        'authProviders' => 'local',
    ])->render();

    expect($manifest)->not->toContain('- name: CACHE_REDIS');

    preg_match('/- name: REDIS\s*\n\s*value: "([^"]*)"/', $manifest, $m);
    expect($m[1] ?? null)->toBe('redis://redis.larakube-plex.svc.cluster.local:6379/4');
});

test('data:init returns a failing exit code and does not claim success when kubectl apply is rejected', function () {
    // Regression guard: withSpin()'s success check is `!== false`, and the
    // old runStreaming() call returned an int exit code — never `=== false`
    // — so a rejected kubectl apply still printed a green check and "Directus
    // Headless CMS stack is live." applyAndVerifyRollout() returns a real
    // bool, which withSpin can act on.
    Process::fake([
        '*plex-commons*' => Process::result(output: '{"services":{"postgres":{"enabled":true},"redis":{"enabled":true},"seaweedfs":{"enabled":true}}}'),
        '*plex-registry*' => Process::result(output: '{"tenants":{}}'),
        '*plex-admin*' => Process::result(output: base64_encode('s3-access-key')),
        '*data-secrets*' => Process::result(output: ''),
        '*create namespace*' => Process::result(output: 'namespace/larakube-shared created'),
        '*create secret*' => Process::result(output: 'secret created'),
        '*create configmap*' => Process::result(output: 'configmap created'),
        '*exec*deploy/postgres*' => Process::result(output: 'CREATE DATABASE'),
        '*apply*' => Process::result(output: 'The Deployment "data-directus" is invalid', exitCode: 1),
        '*rollout status*' => Process::result(output: 'deployment "data-directus" successfully rolled out'),
    ]);

    $this->artisan(DataInitCommand::class, [
        'environment' => 'local',
        '--no-interaction' => true,
    ])
        ->assertExitCode(1)
        ->doesntExpectOutputToContain('Directus Headless CMS stack is live');
});

test('data manifest declares the mail:wire/sso:wire static keys as literals, not valueFrom', function () {
    // Regression guard: mail:wire/sso:wire set these 6 keys via plain
    // literals (kubectl set env NAME=value), never through the data-smtp/
    // data-oidc Secrets. Declaring them here as valueFrom made a later
    // data:init re-run fail — kubectl apply's merge re-adds valueFrom on top
    // of the live literal value already set, and the two are mutually
    // exclusive (the exact bug confirmed live on Documenso, 2026-08-05).
    $manifest = view('k8s.data.shared', [
        'host' => 'data.example.test',
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 3,
        'vpnOnly' => false,
        'isLocal' => true,
        'proxied' => false,
        'authProviders' => 'local,zitadel',
    ])->render();

    foreach ([
        'EMAIL_TRANSPORT' => 'smtp',
        'AUTH_PROVIDERS' => 'local,zitadel',
        'AUTH_ZITADEL_DRIVER' => 'openid',
        'AUTH_ZITADEL_SCOPE' => 'openid email profile',
        'AUTH_ZITADEL_IDENTIFIER_KEY' => 'email',
        'AUTH_ZITADEL_ALLOW_PUBLIC_REGISTRATION' => 'true',
    ] as $name => $value) {
        preg_match('/- name: '.$name.'\s*\n\s*(value|valueFrom):\s*"?([^"\n]*)"?/', $manifest, $m);
        expect($m[1] ?? null)->toBe('value')
            ->and(trim($m[2] ?? '', '"'))->toBe($value);
    }
});

function fakeDataInitProcess(bool $ssoWired, ?string &$appliedManifest): void
{
    Process::fake(function ($process) use ($ssoWired, &$appliedManifest) {
        $cmd = $process->command;

        if (str_contains($cmd, 'apply -f')) {
            preg_match('/apply -f (\'[^\']*\'|"[^"]*"|\S+)/', $cmd, $m);
            $path = trim($m[1] ?? '', '\'"');
            if ($path !== '' && file_exists($path) && str_contains($path, 'larakube-data-directus')) {
                $appliedManifest = file_get_contents($path);
            }

            return Process::result(output: 'applied');
        }

        return match (true) {
            str_contains($cmd, 'plex-commons') => Process::result(output: '{"services":{"postgres":{"enabled":true},"redis":{"enabled":true},"seaweedfs":{"enabled":true}}}'),
            str_contains($cmd, 'plex-registry') => Process::result(output: '{"tenants":{}}'),
            str_contains($cmd, 'plex-admin') => Process::result(output: base64_encode('s3-access-key')),
            str_contains($cmd, 'data-oidc') => $ssoWired
                ? Process::result(output: base64_encode('zitadel-client-id'))
                : Process::result(output: '', exitCode: 1),
            str_contains($cmd, 'data-secrets') => Process::result(output: ''),
            str_contains($cmd, 'exec') && str_contains($cmd, 'deploy/postgres') => Process::result(output: 'CREATE DATABASE'),
            str_contains($cmd, 'rollout status') => Process::result(output: 'deployment "data-directus" successfully rolled out'),
            default => Process::result(output: ''),
        };
    });
}

test('data:init omits zitadel from AUTH_PROVIDERS until sso:wire has actually registered it', function () {
    // Regression guard for a real incident (2026-08-05): Directus eagerly
    // constructs an OpenIDAuthDriver for every provider named in
    // AUTH_PROVIDERS. Listing "zitadel" unconditionally — before sso:wire
    // ever ran, with no client_id/issuer — crashed the pod in a
    // CrashLoopBackOff with "Invalid provider config" instead of just
    // omitting the unconfigured provider.
    $appliedManifest = null;
    fakeDataInitProcess(ssoWired: false, appliedManifest: $appliedManifest);

    $this->artisan(DataInitCommand::class, [
        'environment' => 'local',
        '--engine' => 'directus',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    preg_match('/- name: AUTH_PROVIDERS\s*\n\s*value: "([^"]*)"/', $appliedManifest, $m);
    expect($m[1] ?? null)->toBe('local');
});

test('data:init includes zitadel in AUTH_PROVIDERS once sso:wire has registered it', function () {
    $appliedManifest = null;
    fakeDataInitProcess(ssoWired: true, appliedManifest: $appliedManifest);

    $this->artisan(DataInitCommand::class, [
        'environment' => 'local',
        '--engine' => 'directus',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    preg_match('/- name: AUTH_PROVIDERS\s*\n\s*value: "([^"]*)"/', $appliedManifest, $m);
    expect($m[1] ?? null)->toBe('local,zitadel');
});

test('data:show displays status table for Directus', function () {
    Process::fake([
        '*get deployment data-directus*' => Process::result(output: 'data-directus   1/1   1   1   10d'),
    ]);

    $this->artisan(DataShowCommand::class, [
        'environment' => 'local',
    ])
        ->assertExitCode(0);
});

test('data:remove tears down Directus stack', function () {
    Process::fake([
        '*data-secrets*' => Process::result(output: 'data-secrets'),
        '*plex-registry*' => Process::result(output: '{"tenants":{}}'),
        '*exec*deploy/postgres*' => Process::result(output: 'DROP DATABASE'),
        '*create configmap*' => Process::result(output: 'configmap created'),
        '*delete deployment/data-directus*' => Process::result(output: 'deployment.apps "data-directus" deleted'),
    ]);

    $this->artisan(DataRemoveCommand::class, [
        'environment' => 'local',
        '--force' => true,
    ])
        ->assertExitCode(0);
});
