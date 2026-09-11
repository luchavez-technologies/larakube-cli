<?php

use App\Traits\GeneratesProjectInfrastructure;

/**
 * Laravel's Vite+ starter kits ship their own `server` block, so the original
 * two branches (inject when absent / realign when ours) both miss and every new
 * Laravel app fell through to the hands-off advisory — losing HMR alignment.
 */
function viteMergeHarness(): object
{
    return new class
    {
        use GeneratesProjectInfrastructure;

        public function merge(string $content, string $host): ?string
        {
            return $this->mergeIntoViteServerBlock($content, $host);
        }

        /** @return list<string> */
        public function keys(string $body): array
        {
            return $this->topLevelObjectKeys($body);
        }
    };
}

function vitePlusConfig(string $serverBlock = "    server: {\n        watch: {\n            ignored: ['**/vendor/**'],\n        },\n    },\n"): string
{
    return "import { defineConfig } from 'vite-plus';\n\nexport default defineConfig({\n    plugins: [],\n"
        .$serverBlock
        ."    lint: {\n        ignorePatterns: ['vendor/**'],\n    },\n});\n";
}

test('our HMR keys are merged into a server block somebody else wrote', function (): void {
    $merged = viteMergeHarness()->merge(vitePlusConfig(), 'vite.app.test');

    expect($merged)->not->toBeNull()
        ->and($merged)->toContain("origin: 'https://vite.app.test'")
        ->and($merged)->toContain("host: 'vite.app.test'")
        ->and($merged)->toContain('strictPort: true')
        ->and($merged)->toContain('port: 5173');
});

test('their watch.ignored entries survive the merge', function (): void {
    // Replacing the list would silently re-enable watching vendor/, which is
    // what those entries exist to prevent.
    $merged = (string) viteMergeHarness()->merge(vitePlusConfig(), 'vite.app.test');

    expect($merged)->toContain("'**/vendor/**'")
        ->and($merged)->toContain("'**/.infrastructure/volume_data/**'");
});

test('a value the developer already set is never overwritten', function (): void {
    $custom = vitePlusConfig("    server: {\n        port: 3000,\n        strictPort: false,\n    },\n");

    $merged = (string) viteMergeHarness()->merge($custom, 'vite.app.test');

    expect($merged)->toContain('port: 3000,')
        ->and($merged)->toContain('strictPort: false,')
        ->and($merged)->not->toContain('port: 5173')
        // …while the keys they did NOT set still get added.
        ->and($merged)->toContain("origin: 'https://vite.app.test'");
});

test('the merged block is marked so re-runs realign instead of re-merging', function (): void {
    $merged = (string) viteMergeHarness()->merge(vitePlusConfig(), 'vite.app.test');

    // Trait constants are only reachable through a class that uses the trait.
    expect($merged)->toContain(viteMergeHarness()::VITE_MANAGED_SENTINEL);

    // Second pass finds every key present, so there is nothing left to add.
    expect(viteMergeHarness()->merge($merged, 'vite.app.test'))->toBeNull();
});

test('nested and quoted colons are not mistaken for top-level keys', function (): void {
    // `host:` inside hmr{} and the colon inside 'https://…' both used to look
    // like top-level keys to a naive scan, which would skip adding the real ones.
    $keys = viteMergeHarness()->keys("\n    origin: 'https://x.test',\n    hmr: {\n        host: 'x.test',\n    },\n");

    expect($keys)->toContain('origin')
        ->and($keys)->toContain('hmr')
        ->and($keys)->not->toContain('host');
});

test('an unparseable server block falls back to the advisory', function (): void {
    // Unbalanced braces: refuse rather than write a mangled config.
    expect(viteMergeHarness()->merge("export default defineConfig({\n    server: {\n        watch: {\n", 'vite.app.test'))
        ->toBeNull();
});
