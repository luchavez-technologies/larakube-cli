<?php

namespace App\Commands\Tool;

use App\Enums\ClusterTool;
use App\Traits\LaraKubeOutput;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * Browse and paginate the full Cluster Tool & Marketplace catalog.
 *
 * Designed for incremental pulls by LaraKube Desktop / MCP and future Cloud
 * marketplace feeds without requiring a live Kubernetes cluster connection.
 */
class ToolCatalogCommand extends Command
{
    use LaraKubeOutput;

    protected $signature = 'tool:catalog
        {--page=1         : Page number (1-indexed)}
        {--per-page=25    : Number of tools per page (1-100)}
        {--q=             : Filter tools by slug, name, or tagline}
        {--category=      : Filter tools by category slug}
        {--source=        : Filter by catalog source (official, community)}
        {--updated-since= : Include entries updated at or after this ISO-8601 timestamp}
        {--local          : Describe initFields using local cluster rules}
        {--json           : Output paginated JSON on stdout}';

    protected $description = 'List and filter the Cluster Tool & Marketplace catalog (paginated)';

    public function handle(): int
    {
        $isLocal = (bool) $this->option('local');
        $query = trim((string) ($this->option('q') ?? ''));
        $category = strtolower(trim((string) ($this->option('category') ?? '')));
        $source = strtolower(trim((string) ($this->option('source') ?? '')));
        $updatedSinceRaw = trim((string) ($this->option('updated-since') ?? ''));
        $updatedSince = $updatedSinceRaw !== '' ? CarbonImmutable::parse($updatedSinceRaw) : null;

        $page = max(1, (int) $this->option('page'));
        $perPage = min(100, max(1, (int) $this->option('per-page')));

        $entries = $this->collectCatalogEntries($isLocal);

        $filtered = array_values(array_filter(
            $entries,
            function (array $entry) use ($query, $category, $source, $updatedSince): bool {
                if ($source !== '' && ($entry['source'] ?? 'official') !== $source) {
                    return false;
                }

                if ($category !== '' && ! in_array($category, (array) ($entry['categories'] ?? []), true)) {
                    return false;
                }

                if ($query !== '') {
                    $q = strtolower($query);
                    $haystack = strtolower(
                        implode(' ', [
                            (string) ($entry['tool'] ?? ''),
                            (string) ($entry['name'] ?? ''),
                            (string) ($entry['brand'] ?? ''),
                            (string) ($entry['tagline'] ?? ''),
                        ]),
                    );
                    if (! str_contains($haystack, $q)) {
                        return false;
                    }
                }

                if ($updatedSince !== null) {
                    $entryUpdated = $entry['remoteUpdatedAt'] ?? null;
                    if (is_string($entryUpdated) && $entryUpdated !== '') {
                        return CarbonImmutable::parse($entryUpdated)->greaterThanOrEqualTo($updatedSince);
                    }

                    // Built-in official entries carry no remote timestamp; include when syncing official or unfiltered delta
                    return ($entry['source'] ?? 'official') === 'official';
                }

                return true;
            },
        ));

        $total = count($filtered);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;
        $pageData = array_slice($filtered, $offset, $perPage);

        $payload = [
            'data' => array_values($pageData),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'synced_at' => now()->toAtomString(),
            ],
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->renderHeader();
        if ($pageData === []) {
            $this->info('No Cluster Tools matched your filters.');

            return self::SUCCESS;
        }

        table(
            headers: ['Slug', 'Name', 'Source', 'Plex DBs', 'SQLite / --no-plex', 'Tagline'],
            rows: array_map(function (array $row): array {
                $caps = is_array($row['commonsCapabilities'] ?? null) ? $row['commonsCapabilities'] : [];
                $plexDbs = implode(', ', (array) ($caps['plexDatabases'] ?? [])) ?: '—';
                $standalone = ! empty($row['supportsNoPlex'])
                    ? (! empty($caps['supportsSqlite']) ? 'SQLite (--no-plex)' : 'Bundled (--no-plex)')
                    : (! empty($caps['supportsSqlite']) ? 'SQLite' : '—');

                return [
                    (string) ($row['tool'] ?? ''),
                    (string) ($row['name'] ?? ''),
                    (string) ($row['source'] ?? 'official'),
                    $plexDbs,
                    $standalone,
                    str((string) ($row['tagline'] ?? ''))->limit(48)->toString(),
                ];
            }, $pageData),
        );

        $this->line("Page {$page} of {$lastPage} ({$total} total)");

        return self::SUCCESS;
    }

    /**
     * Collect official built-in tools merged with any locally cached Cloud community catalog items.
     *
     * @return list<array<string, mixed>>
     */
    private function collectCatalogEntries(bool $isLocal): array
    {
        $entries = [];
        $seen = [];

        foreach (ClusterTool::shippedCases() as $tool) {
            $row = $tool->toCatalogRow(isLocal: $isLocal);
            $row['upvotesCount'] = 0;
            $row['remoteUpdatedAt'] = null;
            $entries[] = $row;
            $seen[$tool->value] = true;
        }

        foreach ($this->readCachedCommunityTools() as $community) {
            $slug = (string) ($community['tool'] ?? $community['slug'] ?? '');
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }

            $caps = is_array($community['commonsCapabilities'] ?? null) ? $community['commonsCapabilities'] : [];
            $dbs = array_values(array_filter(array_map('strval', (array) ($caps['databases'] ?? []))));
            $plexDbs = array_values(array_filter($dbs, fn (string $db): bool => $db !== 'sqlite'));

            $entries[] = [
                'tool' => $slug,
                'icon' => (string) ($community['icon'] ?? '📦'),
                'brand' => (string) ($community['brand'] ?? $community['name'] ?? $slug),
                'label' => (string) ($community['label'] ?? $community['name'] ?? $slug),
                'name' => (string) ($community['name'] ?? $community['label'] ?? $slug),
                'tagline' => (string) ($community['tagline'] ?? ''),
                'logo' => $community['logo'] ?? null,
                'paid' => (bool) ($community['paid'] ?? false),
                'stack' => array_values((array) ($community['stack'] ?? [])),
                'categories' => array_values((array) ($community['categories'] ?? ['productivity'])),
                'source' => 'community',
                'upvotesCount' => (int) ($community['upvotesCount'] ?? 0),
                'remoteUpdatedAt' => $community['remoteUpdatedAt'] ?? $community['updatedAt'] ?? null,
                'requiresAdminEmail' => (bool) ($community['requiresAdminEmail'] ?? false),
                'multiInstance' => (bool) ($community['multiInstance'] ?? true),
                'supportsNoPlex' => (bool) ($community['supportsNoPlex'] ?? false),
                'commonsCapabilities' => [
                    'databases' => $dbs,
                    'plexDatabases' => $plexDbs,
                    'supportsSqlite' => in_array('sqlite', $dbs, true),
                    'cache' => array_values((array) ($caps['cache'] ?? [])),
                    'storage' => array_values((array) ($caps['storage'] ?? [])),
                    'auth' => array_values((array) ($caps['auth'] ?? [])),
                    'mail' => array_values((array) ($caps['mail'] ?? [])),
                ],
                'initFields' => array_values((array) ($community['initFields'] ?? [])),
            ];
            $seen[$slug] = true;
        }

        return $entries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readCachedCommunityTools(): array
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '';
        if ($home === '') {
            return [];
        }

        $cachePath = rtrim($home, '/').'/.larakube/marketplace-cache.json';
        if (! File::exists($cachePath)) {
            return [];
        }

        $decoded = json_decode((string) File::get($cachePath), true);
        if (! is_array($decoded)) {
            return [];
        }

        $items = array_is_list($decoded) ? $decoded : ($decoded['data'] ?? []);

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }
}
