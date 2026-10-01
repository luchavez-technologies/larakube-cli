<?php

use App\Enums\ClusterTool;

/**
 * `--tool=sso` and `--tool=zitadel` name the same install, and the registry stores
 * the tool-named case while older commands pass the category one. Any answer that
 * differs between the two (a `match` that lists one case and forgets its twin)
 * makes a wire command behave differently by spelling.
 *
 * Only what is meant to differ may: the label, the brand, the command names, the
 * icon and the engine list. DATA, FLOW and ANALYTICS have several tool-named
 * cases per category, so each case legitimately answers for its own engine.
 */
function categoryParityPairs(): array
{
    $pairs = [];
    foreach (ClusterTool::cases() as $category) {
        if (! $category->isLegacy() || in_array($category, [ClusterTool::DATA, ClusterTool::FLOW, ClusterTool::ANALYTICS], true)) {
            continue;
        }

        $tool = $category->canonicalTool();
        if ($tool !== $category) {
            $pairs["{$category->value} / {$tool->value}"] = [$category, $tool];
        }
    }

    return $pairs;
}

test('a tool-named case answers every question the way its category case does', function (ClusterTool $category, ClusterTool $tool): void {
    $mayDiffer = ['getLabel', 'brandName', 'initCommand', 'removeCommand', 'showCommand', 'icon', 'engines', 'defaultEngine', 'productName'];
    $normalise = fn (mixed $value) => is_scalar($value) || $value === null ? $value : json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR);

    $methods = array_filter(
        (new ReflectionClass(ClusterTool::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        fn (ReflectionMethod $m) => ! $m->isStatic()
            && $m->getDeclaringClass()->getName() === ClusterTool::class
            && $m->getNumberOfRequiredParameters() === 0
            && ! in_array($m->getName(), array_merge($mayDiffer, ['category', 'canonicalTool', 'legacyCategoryPrefix', 'isLegacy', 'value', 'name']), true),
    );

    $differs = [];
    foreach ($methods as $method) {
        $name = $method->getName();
        if ($normalise($category->$name()) !== $normalise($tool->$name())) {
            $differs[] = $name;
        }
    }

    expect($differs)->toBe([], "{$category->value} and {$tool->value} disagree");
})->with(fn () => categoryParityPairs());

test('category() maps every tool-named case to its category and every other case to itself', function (): void {
    expect(ClusterTool::ZITADEL->category())->toBe(ClusterTool::SSO)
        ->and(ClusterTool::SSO->category())->toBe(ClusterTool::SSO)
        ->and(ClusterTool::OPENBAO->category())->toBe(ClusterTool::SECRETS)
        ->and(ClusterTool::GRAFANA->category())->toBe(ClusterTool::MONITOR)
        ->and(ClusterTool::GLITCHTIP->category())->toBe(ClusterTool::ERRORS)
        ->and(ClusterTool::NETBIRD->category())->toBe(ClusterTool::VPN)
        ->and(ClusterTool::MATRIX->category())->toBe(ClusterTool::CHAT)
        ->and(ClusterTool::WINDMILL->category())->toBe(ClusterTool::FLOW)
        ->and(ClusterTool::POCKETBASE->category())->toBe(ClusterTool::DATA)
        ->and(ClusterTool::PLAUSIBLE->category())->toBe(ClusterTool::ANALYTICS);

    foreach (ClusterTool::cases() as $case) {
        expect($case->category()->category())->toBe($case->category());
    }
});
