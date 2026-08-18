<?php

use App\Commands\Crm\CrmInitCommand;
use App\Commands\Crm\CrmRemoveCommand;
use App\Commands\Crm\CrmShowCommand;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

test('crm:init deploys Twenty CRM using commons postgres and redis', function () {
    Process::fake([
        '*plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
                'seaweedfs' => ['enabled' => true],
            ],
        ]),
        '*plex-registry*' => json_encode(['tenants' => []]),
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*exec*' => Process::result(output: 'CREATE DATABASE'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create secret*' => Process::result(output: 'secret created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout status*' => Process::result(output: 'rollout success'),
        '*get secret*' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan(CrmInitCommand::class, [
        'environment' => 'local',
        '--no-interaction' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('Twenty CRM stack is live.');
});

test('crm:init detects MinIO rather than assuming SeaweedFS when that\'s what plex:init actually provisioned', function () {
    // Regression: the Commons S3 backend is an operator choice at plex:init
    // (StorageDriver has 3 options), not a fixed SeaweedFS install — an
    // earlier version of this wiring hardcoded StorageDriver::SEAWEEDFS.
    Process::fake([
        '*plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
                'minio' => ['enabled' => true],
            ],
        ]),
        '*plex-registry*' => json_encode(['tenants' => []]),
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*exec*' => Process::result(output: 'CREATE DATABASE'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create secret*' => Process::result(output: 'secret created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout status*' => Process::result(output: 'rollout success'),
        '*get secret*' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan(CrmInitCommand::class, [
        'environment' => 'local',
        '--no-interaction' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('Commons MinIO');

    Process::assertRan(fn ($process) => str_contains($process->command, 'deploy/minio'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'deploy/seaweedfs'));
});

test('crm:show displays status table for Twenty CRM', function () {
    Process::fake([
        '*get deployment *' => Process::result(output: 'crm-twenty-crm-dev-test   1/1   1   1   10d'),
    ]);

    $this->artisan(CrmShowCommand::class, [
        'environment' => 'local',
    ])->assertExitCode(0);
});

test('crm:remove cleans up Twenty CRM resources', function () {
    Process::fake([
        '*get secret larakube-tools-registry*' => json_encode([
            ['tool' => 'crm', 'instance' => 'crm-dev-test', 'host' => 'crm.dev.test'],
        ]),
        '*delete deployment/crm-twenty-crm-dev-test*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan(CrmRemoveCommand::class, [
        'environment' => 'local',
        '--force' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);
});

test('mail:wire correctly targets Twenty CRM for SMTP email delivery', function () {
    expect(ClusterTool::CRM->smtpEnv())->toBe([
        'namespace' => 'larakube-shared',
        'also_patch' => ['crm-twenty-worker'],
        'deployment' => 'crm-twenty',
        'secret' => 'crm-smtp',
        'static' => [
            'EMAIL_DRIVER' => 'smtp',
        ],
        'vars' => [
            'host' => 'EMAIL_SMTP_HOST',
            'port' => 'EMAIL_SMTP_PORT',
            'user' => 'EMAIL_SMTP_USER',
            'password' => 'EMAIL_SMTP_PASSWORD',
            'from' => 'EMAIL_FROM_ADDRESS',
        ],
    ]);
});

/**
 * Regression: ClusterTool::smtpEnv() calls $vendor->smtpEnv($instance) with
 * ONE positional argument. CrmTool used to declare
 * smtpEnv(?string $engine, ?string $instance) — an extra leading parameter
 * that doesn't exist on the HasSmtpWiring contract — so that lone argument
 * landed in CRM's $engine slot and $instance stayed null forever, silently
 * targeting the unsuffixed 'crm-twenty' deployment for every real instance.
 * The zero-arg test above can't catch this; it has to actually pass an
 * instance through the real wrapper call, the way mail:wire does.
 */
test('smtpEnv() actually suffixes CRM deployment names for a real instance', function () {
    expect(ClusterTool::CRM->smtpEnv(instance: 'crm-luchtech-dev'))->toBe([
        'namespace' => 'larakube-shared',
        'also_patch' => ['crm-twenty-worker-crm-luchtech-dev'],
        'deployment' => 'crm-twenty-crm-luchtech-dev',
        'secret' => 'crm-smtp-crm-luchtech-dev',
        'static' => [
            'EMAIL_DRIVER' => 'smtp',
        ],
        'vars' => [
            'host' => 'EMAIL_SMTP_HOST',
            'port' => 'EMAIL_SMTP_PORT',
            'user' => 'EMAIL_SMTP_USER',
            'password' => 'EMAIL_SMTP_PASSWORD',
            'from' => 'EMAIL_FROM_ADDRESS',
        ],
    ]);
});

test('crm does not claim OIDC wiring — Twenty paywalls SSO behind its paid Organization tier', function () {
    // Confirmed live 2026-08-18: sso:wire --tool=crm crashed on a missing
    // 'redirect_path' key CrmTool::oidcEnv() never declared. Rather than
    // guess at Twenty's real OIDC callback shape (its login route embeds a
    // dynamic identity-provider id: /auth/oidc/login/:identityProviderId,
    // not a fixed path) for a feature self-hosted Twenty can't actually use
    // without a paid license, CrmTool drops HasOidcWiring entirely — same
    // pattern as Planka/Tasks (see ClusterToolLifecycleTest). sso:wire now
    // refuses cleanly via hasSsoWire() instead of registering a Zitadel
    // client Twenty could never honor.
    expect(ClusterTool::CRM->vendor())->not->toBeInstanceOf(App\Contracts\HasOidcWiring::class)
        ->and(ClusterTool::CRM->hasSsoWire())->toBeFalse();
});

test('crm:init errors instead of guessing when multiple instances are already registered and --domain is omitted', function () {
    // Regression guard for the confirmed live 2026-08-14 duplicate-registration
    // bug: a no-flag re-run's registry lookup under the default 'main' instance
    // never matched CRM's always-derived-slug entries, so it silently derived
    // a fresh host/instance and created a second, duplicate CRM deployment.
    Process::fake([
        '*larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'crm', 'instance' => 'crm-luchtech-dev', 'host' => 'crm.luchtech.dev'],
            ['tool' => 'crm', 'instance' => 'crm2-luchtech-dev', 'host' => 'crm2.luchtech.dev'],
        ]))),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan(CrmInitCommand::class, [
        'environment' => 'local',
        '--no-interaction' => true,
    ])->run();
})->throws(RuntimeException::class, 'pass --domain=<host>');
