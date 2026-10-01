<?php

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\ResourceNaming;
use App\Enums\SecretKind;

test('CRM resolves every resource name from ToolInstance with the category gone', function (): void {
    $names = ToolInstance::forInstance(ClusterTool::CRM, 'crm-luchtech-dev');

    expect($names->deployment('server'))->toBe('twenty-crm-luchtech-dev')
        ->and($names->deployment('worker'))->toBe('twenty-worker-crm-luchtech-dev')
        ->and($names->secret())->toBe('twenty-secrets-crm-luchtech-dev')
        ->and($names->secret(SecretKind::SMTP))->toBe('twenty-smtp-crm-luchtech-dev')
        ->and($names->secret(SecretKind::OIDC))->toBe('twenty-oidc-crm-luchtech-dev')
        ->and($names->database())->toBe('twenty_crm_luchtech_dev')
        ->and($names->bucket())->toBe('twenty-storage-crm-luchtech-dev')
        ->and($names->vpnMiddleware()->name)->toBe('twenty-vpn-only-crm-luchtech-dev');
});

test('the same name serves CRM and its Twenty alias', function (): void {
    expect(ClusterTool::TWENTY->resourceNaming())->toBe(ResourceNaming::CANONICAL)
        ->and(ClusterTool::CRM->resourceNaming())->toBe(ResourceNaming::CANONICAL)
        ->and(ClusterTool::TWENTY->deploymentName('crm-luchtech-dev'))->toBe(ClusterTool::CRM->deploymentName('crm-luchtech-dev'));
});

test('CRM\'s Redis tenant is its database name, so one install owns one registry row', function (): void {
    $names = ToolInstance::forInstance(ClusterTool::CRM, 'crm-luchtech-dev');

    expect($names->redisTenant())->toBe($names->database())->toBe('twenty_crm_luchtech_dev');
});

test('teardown names every resource the manifest declares for the server and both Deployments', function (): void {
    $components = collect(ClusterTool::CRM->components('crm-luchtech-dev'));
    $refs = $components->flatMap(fn ($c) => collect($c->resources)->map(fn ($r) => "{$r['kind']}/{$r['name']}"))->all();

    expect($components->pluck('deployment')->all())->toBe(['twenty-crm-luchtech-dev', 'twenty-worker-crm-luchtech-dev'])
        ->and($refs)->toBe([
            'service/twenty-crm-luchtech-dev',
            'ingress/twenty-crm-luchtech-dev',
            'secret/twenty-secrets-crm-luchtech-dev',
            'secret/twenty-smtp-crm-luchtech-dev',
            'secret/twenty-oidc-crm-luchtech-dev',
        ]);
});

/**
 * A Redis tenant and its database share one registry row, and Postgres
 * identifiers carry no hyphen. A hyphenated Redis tenant gave CRM two rows for
 * one install and let `--purge` free a name nothing had allocated.
 */
test('a migrated tool never names its Redis tenant with the slug\'s hyphens', function (): void {
    $checked = 0;

    foreach (ClusterTool::cases() as $tool) {
        if ($tool->resourceNaming() !== ResourceNaming::CANONICAL || $tool->commonsRedisKeys() === []) {
            continue;
        }

        foreach ($tool->commonsRedisTenants('blog-example-com') as $tenant) {
            expect($tenant)->not->toContain('-', "{$tool->value}: {$tenant}");
            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(0);
});
