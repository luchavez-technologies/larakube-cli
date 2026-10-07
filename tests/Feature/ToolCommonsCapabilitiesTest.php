<?php

use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

test('every tool declares standardized commons capabilities', function (): void {
    foreach (ClusterTool::cases() as $tool) {
        $caps = $tool->commonsCapabilities();

        expect($caps)->toBeArray()
            ->toHaveKeys(['databases', 'cache', 'storage', 'auth', 'mail'])
            ->and($caps['databases'])->toBeArray()
            ->and($caps['cache'])->toBeArray()
            ->and($caps['storage'])->toBeArray()
            ->and($caps['auth'])->toBeArray()
            ->and($caps['mail'])->toBeArray();
    }
});

test('specific tools report accurate commons capabilities', function (): void {
    $pb = ClusterTool::POCKETBASE->commonsCapabilities();
    expect($pb['databases'])->toBe(['sqlite'])
        ->and($pb['cache'])->toBeEmpty()
        ->and($pb['storage'])->toBe(['s3'])
        ->and($pb['auth'])->toBe(['oidc'])
        ->and($pb['mail'])->toBe(['smtp']);

    $directus = ClusterTool::DIRECTUS->commonsCapabilities();
    expect($directus['databases'])->toContain('postgresql', 'mysql')
        ->and($directus['cache'])->toBe(['redis'])
        ->and($directus['storage'])->toBe(['s3'])
        ->and($directus['auth'])->toBe(['oidc'])
        ->and($directus['mail'])->toBe(['smtp']);

    $wp = ClusterTool::WORDPRESS->commonsCapabilities();
    expect($wp['databases'])->toContain('sqlite', 'mysql', 'mariadb')
        ->and($wp['cache'])->toBe(['redis'])
        ->and($wp['storage'])->toBe(['s3'])
        ->and($wp['mail'])->toBe(['smtp']);

    $n8n = ClusterTool::N8N->commonsCapabilities();
    expect($n8n['databases'])->toContain('postgresql', 'sqlite')
        ->and($n8n['cache'])->toBe(['redis'])
        ->and($n8n['storage'])->toBe(['s3'])
        ->and($n8n['auth'])->toBe(['oidc'])
        ->and($n8n['mail'])->toBe(['smtp']);

    $kuma = ClusterTool::KUMA->commonsCapabilities();
    expect($kuma['databases'])->toBe(['sqlite'])
        ->and($kuma['mail'])->toBe(['smtp']);
});

test('legacy categories delegate commons capabilities to their canonical tools', function (): void {
    expect(ClusterTool::DATA->commonsCapabilities('pocketbase'))
        ->toBe(ClusterTool::POCKETBASE->commonsCapabilities())
        ->and(ClusterTool::DATA->commonsCapabilities('wordpress'))
        ->toBe(ClusterTool::WORDPRESS->commonsCapabilities())
        ->and(ClusterTool::DATA->commonsCapabilities('directus'))
        ->toBe(ClusterTool::DIRECTUS->commonsCapabilities())
        ->and(ClusterTool::FLOW->commonsCapabilities('n8n'))
        ->toBe(ClusterTool::N8N->commonsCapabilities());
});

test('tool:list includes commonsCapabilities in json output', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('tool:list local --json');
    expect($exit)->toBe(0);

    $rows = json_decode(Artisan::output(), true);
    expect($rows)->toBeArray()->not->toBeEmpty();

    foreach ($rows as $row) {
        expect($row)->toHaveKey('commonsCapabilities')
            ->and($row['commonsCapabilities'])->toBeArray()
            ->toHaveKeys(['databases', 'cache', 'storage', 'auth', 'mail']);
    }
});
