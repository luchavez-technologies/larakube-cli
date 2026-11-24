<?php

use App\Vendors\SecretTool;

/**
 * Extract every literal container image reference from a tool's Blade templates:
 * 1. YAML `image: <repo>:<tag>` lines where the value is a non-Blade literal.
 * 2. PHP image variable assignments in `@php` blocks (`$image = $image ?? 'repo:tag'`
 *    or `$esoImage = "repo:{$esoVersion}"`), interpolating local `$var = '...'` scalars
 *    defined in the same template.
 *
 * @return list<string>
 */
function extractOpenbaoTemplateImages(string $directory): array
{
    $found = [];

    foreach (glob(rtrim($directory, '/').'/*.blade.php') ?: [] as $file) {
        $content = (string) file_get_contents($file);

        // 1. Collect simple scalar string assignments (e.g. $esoVersion = 'v2.11.0';)
        $scalars = [];
        if (preg_match_all('/\$([a-zA-Z_]\w*)\s*=\s*\'([^\'\r\n]+)\'\s*;/', $content, $scalarMatches, PREG_SET_ORDER)) {
            foreach ($scalarMatches as $match) {
                $scalars[$match[1]] = $match[2];
            }
        }

        // 2. Collect literal image variable assignments ($image = ... or $*Image = ...)
        if (preg_match_all('/\$(?:[a-zA-Z_]\w*Image|image)\s*=\s*(?:\$\w+\s*\?\?\s*)?["\']([^"\'\r\n]+)["\']\s*;/', $content, $assignMatches)) {
            foreach ($assignMatches[1] as $rawImage) {
                $resolved = preg_replace_callback(
                    '/\{\$([a-zA-Z_]\w*)\}/',
                    fn (array $m): string => $scalars[$m[1]] ?? $m[0],
                    $rawImage,
                ) ?? $rawImage;

                if (str_contains($resolved, ':') && ! str_contains($resolved, '{')) {
                    $found[] = $resolved;
                }
            }
        }

        // 3. Collect YAML `image:` lines with non-Blade literal references
        if (preg_match_all('/^\s*image:\s*["\']?([^\s"\'{}]+:[^\s"\'{}]+)["\']?\s*$/m', $content, $yamlMatches)) {
            foreach ($yamlMatches[1] as $literalImage) {
                $found[] = $literalImage;
            }
        }
    }

    return array_values(array_unique($found));
}

test('openbao template literal image references stay in sync with SecretTool::images()', function (): void {
    $expectedImages = array_values((new SecretTool)->images());
    $templateImages = extractOpenbaoTemplateImages(base_path('resources/views/k8s/openbao'));

    expect($templateImages)->not->toBeEmpty();

    foreach ($templateImages as $image) {
        expect($expectedImages)->toContain($image);
    }

    expect($templateImages)->toEqualCanonicalizing($expectedImages);
});
