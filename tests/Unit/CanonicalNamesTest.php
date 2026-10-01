<?php

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;

/**
 * ADR 0021: every resource is `{component}[-{token}]-{instance}` — the legacy
 * category (`design-`, `errors-`, `support-`...) never appears in a name.
 */
dataset('tools on the canonical naming', [
    'paste' => [ClusterTool::PASTE, 'yopass'],
    'uptime' => [ClusterTool::UPTIME, 'kuma'],
    'insights' => [ClusterTool::INSIGHTS, 'metabase'],
    'errors' => [ClusterTool::ERRORS, 'glitchtip'],
    'support' => [ClusterTool::SUPPORT, 'chatwoot'],
    'record' => [ClusterTool::RECORD, 'sendrec'],
    'resume' => [ClusterTool::RESUME, 'reactive'],
    'design' => [ClusterTool::DESIGN, 'penpot'],
]);

test('a migrated tool names its resources after the component, then the instance', function (ClusterTool $tool, string $component): void {
    $names = ToolInstance::forInstance($tool, 'x-example-com');

    expect($names->deployment())->toStartWith("{$component}")
        ->and($names->deployment())->toEndWith('-x-example-com')
        ->and($names->secret())->toEndWith('-secrets-x-example-com')
        ->and($names->secret(SecretKind::OIDC))->toEndWith('-oidc-x-example-com');

    if ($names->vpnMiddleware() !== null) {
        expect($names->vpnMiddleware()->name)->toEndWith('-vpn-only-x-example-com');
    }

    foreach ($tool->components('x-example-com') as $c) {
        expect($c->deployment)->not->toStartWith($tool->value.'-')
            ->and($c->deployment)->toEndWith('-x-example-com');
    }
})->with('tools on the canonical naming');

test('a migrated tool labels what it deploys with its identity', function (ClusterTool $tool): void {
    expect(ToolInstance::forInstance($tool, 'x-example-com')->labels())
        ->toHaveKeys(['larakube.io/managed-by', 'larakube.io/tool', 'larakube.io/component', 'larakube.io/instance']);
})->with('tools on the canonical naming');

test('every Cluster Tool is on the canonical naming, so a new one has to choose it', function (): void {
    foreach (ClusterTool::cases() as $tool) {
        expect($tool->resourceNaming())->toBe(App\Enums\ResourceNaming::CANONICAL);
    }
});

test('a Cluster Tool manifest pack takes its names from ToolInstance', function (): void {
    // Hand-built names are how init and remove drift apart. `data` (names passed in by
    // its init) and `dns` (keyed by zone group) are the only packs that do not read it.
    $exempt = ['data', 'dns'];
    $packs = ['analytics', 'chat', 'crm', 'dashboard', 'design', 'drive', 'errors', 'flow', 'git', 'insights', 'link', 'mail',
        'meet', 'monitoring', 'notes', 'paste', 'record', 'resume', 'secrets', 'sheet', 'sign', 'sso', 'support', 'tasks',
        'uptime', 'vault', 'vpn', 'webmail', ...$exempt];

    foreach (array_diff($packs, $exempt) as $pack) {
        $source = '';
        foreach (glob(resource_path("views/k8s/{$pack}/*.blade.php")) as $file) {
            $source .= file_get_contents($file);
        }

        expect(str_contains($source, 'ToolInstance'))->toBeTrue("k8s/{$pack} does not read ToolInstance");
    }
});
