<?php

use App\Enums\ClusterTool;

/**
 * LaraKube Desktop draws a brand icon per tool and falls back to an emoji when
 * it has none. A new tool should not silently take the fallback, so this fails
 * until Desktop's icon map names it. Skipped where Desktop's source is not
 * checked out beside the CLI.
 */
test('every shipped tool is named in Desktop\'s icon map', function (): void {
    $path = base_path('../desktop/resources/js/components/tool-logo.tsx');

    if (! is_file($path)) {
        $this->markTestSkipped('LaraKube Desktop is not checked out beside the CLI.');
    }

    $source = strtolower((string) file_get_contents($path));
    $missing = [];

    foreach (ClusterTool::shippedCases() as $tool) {
        $names = [$tool->value, $tool->canonicalTool()->value, strtolower($tool->canonicalTool()->brandName())];

        if (array_filter($names, fn (string $name): bool => str_contains($source, $name)) === []) {
            $missing[] = $tool->value;
        }
    }

    expect($missing)->toBe([], 'Desktop has no icon for: '.implode(', ', $missing));
});
