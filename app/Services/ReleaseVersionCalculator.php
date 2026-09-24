<?php

declare(strict_types=1);

namespace App\Services;

class ReleaseVersionCalculator
{
    /**
     * @param  array<int, array{hash?: string, subject: string, body?: string}>  $commits
     * @return array{
     *     should_release: bool,
     *     current_version: string,
     *     next_version: ?string,
     *     bump_type: ?string,
     *     release_notes: string,
     *     reason?: string
     * }
     */
    public function calculate(string $currentVersion, array $commits, ?string $repo = null): array
    {
        $normalizedCurrent = $this->normalizeVersion($currentVersion);
        if ($normalizedCurrent === null) {
            $normalizedCurrent = 'v0.0.0';
        }

        if (empty($commits)) {
            return [
                'should_release' => false,
                'current_version' => $normalizedCurrent,
                'next_version' => null,
                'bump_type' => null,
                'release_notes' => '',
                'reason' => 'No commits found to evaluate.',
            ];
        }

        preg_match('/^v?(\d+)\.(\d+)\.(\d+)$/', $normalizedCurrent, $matches);
        $major = isset($matches[1]) ? (int) $matches[1] : 0;
        $minor = isset($matches[2]) ? (int) $matches[2] : 0;
        $patch = isset($matches[3]) ? (int) $matches[3] : 0;

        $hasBreaking = false;
        $hasFeat = false;
        $hasFix = false;
        $explicitReleaseAs = null;

        $categorized = [
            'breaking' => [],
            'features' => [],
            'fixes' => [],
            'maintenance' => [],
        ];

        foreach ($commits as $commit) {
            $subject = trim($commit['subject']);
            $body = trim($commit['body'] ?? '');
            $hash = substr(trim($commit['hash'] ?? ''), 0, 7);

            // Check for Release-As override in subject or body
            if (preg_match('/(?:^|\n)Release-As:\s*v?([0-9]+\.[0-9]+\.[0-9]+)/i', $body."\n".$subject, $releaseAsMatch)) {
                $explicitReleaseAs = 'v'.$releaseAsMatch[1];
            }

            // Parse Conventional Commit header: type(scope)!: message
            $isBreaking = false;
            $type = 'other';
            $scope = null;
            $description = $subject;

            if (preg_match('/^(\w+)(?:\(([^)]+)\))?(!)?:\s*(.+)$/', $subject, $headerMatch)) {
                $type = strtolower($headerMatch[1]);
                $scope = ! empty($headerMatch[2]) ? $headerMatch[2] : null;
                $isBreaking = ! empty($headerMatch[3]);
                $description = $headerMatch[4];
            }

            // Check body for BREAKING CHANGE:
            if (preg_match('/(?:^|\n)BREAKING[ -]CHANGE:\s*(.+)/', $body, $breakingMatch)) {
                $isBreaking = true;
                if (empty($description)) {
                    $description = trim($breakingMatch[1]);
                }
            }

            if ($isBreaking) {
                $hasBreaking = true;
                $categorized['breaking'][] = [
                    'hash' => $hash,
                    'scope' => $scope,
                    'description' => $description,
                ];
            }

            if ($type === 'feat') {
                $hasFeat = true;
                $categorized['features'][] = [
                    'hash' => $hash,
                    'scope' => $scope,
                    'description' => $description,
                ];
            } elseif (in_array($type, ['fix', 'perf'], true)) {
                $hasFix = true;
                $categorized['fixes'][] = [
                    'hash' => $hash,
                    'scope' => $scope,
                    'description' => $description,
                ];
            } else {
                $categorized['maintenance'][] = [
                    'hash' => $hash,
                    'scope' => $scope,
                    'description' => $description,
                ];
            }
        }

        // Determine if release is warranted
        if ($explicitReleaseAs !== null) {
            $nextVersion = $explicitReleaseAs;
            $bumpType = 'explicit';
        } elseif ($hasBreaking || $hasFeat || $hasFix) {
            if ($major === 0) {
                // Pre-v1 rules: breaking or feat bumps minor, fix bumps patch
                if ($hasBreaking || $hasFeat) {
                    $bumpType = 'minor';
                    $nextVersion = sprintf('v0.%d.0', $minor + 1);
                } else {
                    $bumpType = 'patch';
                    $nextVersion = sprintf('v0.%d.%d', $minor, $patch + 1);
                }
            } else {
                // Post-v1 rules: breaking bumps major, feat bumps minor, fix bumps patch
                if ($hasBreaking) {
                    $bumpType = 'major';
                    $nextVersion = sprintf('v%d.0.0', $major + 1);
                } elseif ($hasFeat) {
                    $bumpType = 'minor';
                    $nextVersion = sprintf('v%d.%d.0', $major, $minor + 1);
                } else {
                    $bumpType = 'patch';
                    $nextVersion = sprintf('v%d.%d.%d', $major, $minor, $patch + 1);
                }
            }
        } else {
            return [
                'should_release' => false,
                'current_version' => $normalizedCurrent,
                'next_version' => null,
                'bump_type' => null,
                'release_notes' => '',
                'reason' => 'No release-triggering commits found (only chore/docs/refactor/test).',
            ];
        }

        $releaseNotes = $this->generateReleaseNotes($nextVersion, $categorized, $normalizedCurrent, $repo);

        return [
            'should_release' => true,
            'current_version' => $normalizedCurrent,
            'next_version' => $nextVersion,
            'bump_type' => $bumpType,
            'release_notes' => $releaseNotes,
        ];
    }

    /**
     * @param  array<string, array<int, array{hash: string, scope: ?string, description: string}>>  $categorized
     */
    protected function generateReleaseNotes(string $nextVersion, array $categorized, string $previousVersion, ?string $repo): string
    {
        $lines = ["## What's Changed in {$nextVersion}", ''];

        if (! empty($categorized['breaking'])) {
            $lines[] = '### ⚠️ Breaking Changes';
            foreach ($categorized['breaking'] as $item) {
                $prefix = $item['scope'] ? "**{$item['scope']}:** " : '';
                $hash = $item['hash'] ? " ({$item['hash']})" : '';
                $lines[] = "- {$prefix}{$item['description']}{$hash}";
            }
            $lines[] = '';
        }

        if (! empty($categorized['features'])) {
            $lines[] = '### 🚀 Features';
            foreach ($categorized['features'] as $item) {
                $prefix = $item['scope'] ? "**{$item['scope']}:** " : '';
                $hash = $item['hash'] ? " ({$item['hash']})" : '';
                $lines[] = "- {$prefix}{$item['description']}{$hash}";
            }
            $lines[] = '';
        }

        if (! empty($categorized['fixes'])) {
            $lines[] = '### 🐛 Bug Fixes & Improvements';
            foreach ($categorized['fixes'] as $item) {
                $prefix = $item['scope'] ? "**{$item['scope']}:** " : '';
                $hash = $item['hash'] ? " ({$item['hash']})" : '';
                $lines[] = "- {$prefix}{$item['description']}{$hash}";
            }
            $lines[] = '';
        }

        if ($repo && $previousVersion !== 'v0.0.0') {
            $lines[] = "**Full Changelog**: https://github.com/{$repo}/compare/{$previousVersion}...{$nextVersion}";
        }

        return trim(implode("\n", $lines));
    }

    protected function normalizeVersion(string $version): ?string
    {
        $trimmed = trim($version);
        if (preg_match('/^v?(\d+\.\d+\.\d+)$/', $trimmed, $matches)) {
            return 'v'.$matches[1];
        }

        return null;
    }
}
