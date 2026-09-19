<?php

use App\Data\ResourceRef;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;

test('the instance is derived from the host, never passed in', function (): void {
    $instance = ToolInstance::forHost(ClusterTool::PASTE, 'paste.example.com');

    expect($instance->instance)->toBe('paste-example-com')
        ->and($instance->namespace())->toBe(ClusterTool::PASTE->namespace());
});

test('every name comes from the same ClusterTool derivation :init and :remove use', function (): void {
    $instance = ToolInstance::forHost(ClusterTool::PASTE, 'paste.example.com');

    expect($instance->deployment())->toBe(ClusterTool::PASTE->deploymentName('paste-example-com'))
        ->and($instance->commonsRedisTenants())->toBe(['paste_yopass_paste-example-com'])
        ->and($instance->commonsBuckets())->toBe(['paste-yopass-paste-example-com'])
        ->and($instance->vpnMiddleware())->toEqual(new ResourceRef('Middleware', 'paste-yopass-vpn-only-paste-example-com', 'larakube-shared'));
});

test('two hosts never share a name', function (): void {
    $a = ToolInstance::forHost(ClusterTool::PASTE, 'a.example.com');
    $b = ToolInstance::forHost(ClusterTool::PASTE, 'b.example.com');

    expect($a->deployment())->not->toBe($b->deployment())
        ->and(array_intersect($a->commonsRedisTenants(), $b->commonsRedisTenants()))->toBeEmpty()
        ->and(array_intersect($a->commonsBuckets(), $b->commonsBuckets()))->toBeEmpty();
});

test('an unknown component is an error, not a guessed name', function (): void {
    ToolInstance::forHost(ClusterTool::PASTE, 'paste.example.com')->deployment('nope');
})->throws(LogicException::class);

test('every other resource shares the component\'s stem (ADR 0021)', function (): void {
    $instance = ToolInstance::forHost(ClusterTool::LINK, 'link.example.com');

    expect($instance->base())->toBe('link-kutt')
        ->and($instance->secret())->toBe('link-kutt-secrets-link-example-com')
        ->and($instance->secret(App\Enums\SecretKind::OIDC))->toBe('link-kutt-oidc-link-example-com')
        ->and($instance->configMap('config'))->toBe('link-kutt-config-link-example-com')
        ->and($instance->volume())->toBe('link-kutt-storage-link-example-com');
});

test('the single-tenant helpers refuse a tool that has none, instead of guessing', function (): void {
    ToolInstance::forHost(ClusterTool::PASTE, 'paste.example.com')->database();
})->throws(LogicException::class, 'uses no Commons database');
