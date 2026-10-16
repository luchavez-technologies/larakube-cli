<?php

use App\Enums\ClusterTool;

/**
 * Only DATA remains as a legacy category case — genuinely multi-engine, so
 * each tool-named case (POCKETBASE/DIRECTUS/WORDPRESS) legitimately answers
 * for its own engine rather than mirroring a single category twin 1:1. The
 * tool-named-case-vs-category parity check this file used to run for
 * single-target categories (GIT, SECRETS, MAIL, SSO, CHAT, VPN, FLOW, etc.)
 * no longer has any pairs left to check now that those cases are gone.
 */
test('category() maps every tool-named case to its category and every other case to itself', function (): void {
    expect(ClusterTool::POCKETBASE->category())->toBe(ClusterTool::DATA)
        ->and(ClusterTool::WINDMILL->category())->toBe(ClusterTool::WINDMILL)
        ->and(ClusterTool::PLAUSIBLE->category())->toBe(ClusterTool::PLAUSIBLE);

    foreach (ClusterTool::cases() as $case) {
        expect($case->category()->category())->toBe($case->category());
    }
});
