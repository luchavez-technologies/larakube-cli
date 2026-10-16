<?php

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;

/**
 * ADR 0021: every resource is `{component}[-{token}]-{instance}` — the legacy
 * category (`design-`, `errors-`, `support-`...) never appears in a name.
 */
dataset('tools on the canonical naming', [
    'resume' => [ClusterTool::RESUME, 'reactive'],
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
    // Hand-built names are how init and remove drift apart. Pocketbase/directus/wordpress (names passed in by
    // their init) and `external-dns` (keyed by zone group) are the only packs that do not read it.
    $exempt = ['external-dns', 'pocketbase', 'directus', 'wordpress'];
    $packs = ['umami', 'matrix', 'twenty', 'headlamp', 'penpot', 'ocis', 'glitchtip', 'n8n', 'windmill', 'forgejo', 'metabase', 'kutt', 'stalwart',
        'livekit', 'grafana', 'outline', 'yopass', 'sendrec', 'resume', 'openbao', 'teable', 'documenso', 'zitadel', 'chatwoot', 'planka',
        'kuma', 'vaultwarden', 'netbird', 'bulwark', ...$exempt];

    foreach (array_diff($packs, $exempt) as $pack) {
        $source = '';
        foreach (glob(resource_path("views/k8s/{$pack}/*.blade.php")) as $file) {
            $source .= file_get_contents($file);
        }

        expect(str_contains($source, 'ToolInstance'))->toBeTrue("k8s/{$pack} does not read ToolInstance");
    }
});
