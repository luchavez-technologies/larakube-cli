<?php

use App\Enums\ClusterTool;

/**
 * Only DATA and FLOW remain as legacy category cases — both genuinely
 * multi-engine, so each tool-named case (POCKETBASE/DIRECTUS/WORDPRESS,
 * N8N/WINDMILL) legitimately answers for its own engine rather than
 * mirroring a single category twin 1:1. The tool-named-case-vs-category
 * parity check this file used to run for single-target categories (GIT,
 * SECRETS, MAIL, SSO, CHAT, VPN, etc.) no longer has any pairs left to
 * check now that those cases are gone.
 */
test('category() maps every tool-named case to its category and every other case to itself', function (): void {
    expect(ClusterTool::WINDMILL->category())->toBe(ClusterTool::FLOW)
        ->and(ClusterTool::POCKETBASE->category())->toBe(ClusterTool::DATA)
        ->and(ClusterTool::PLAUSIBLE->category())->toBe(ClusterTool::PLAUSIBLE);

    foreach (ClusterTool::cases() as $case) {
        expect($case->category()->category())->toBe($case->category());
    }
});
