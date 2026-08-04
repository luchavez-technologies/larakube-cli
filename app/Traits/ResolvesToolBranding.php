<?php

namespace App\Traits;

use App\Enums\ClusterTool;

use function Laravel\Prompts\text;

/**
 * Resolve and persist an operator-customized brand name and logo URL for a tool.
 *
 * Fallback priority for brand name:
 *   1. --app-name flag (if explicitly passed)
 *   2. Cluster tool registry (`brand_name` stored in `larakube-tools-registry`)
 *   3. Interactive prompt (only when running interactively AND tool has whiteLabel() spec)
 *   4. ClusterTool::brandName() (static default)
 *
 * Fallback priority for logo URL:
 *   1. --logo-url flag (if explicitly passed)
 *   2. Cluster tool registry (`logo_url` stored in `larakube-tools-registry`)
 *   3. null (no logo URL override)
 */
trait ResolvesToolBranding
{
    /**
     * Resolve branding for the given tool, prompting interactively if supported
     * and saving any updated branding to the cluster registry via registerTool().
     *
     * @return array{appName: string, logoUrl: ?string}
     */
    protected function resolveToolBranding(?string $kubectl, ClusterTool $tool): array
    {
        $flagAppName = (string) ($this->option('app-name') ?? '');
        $flagLogoUrl = (string) ($this->option('logo-url') ?? '');

        // Fetch stored registry entry if available
        $registered = ($kubectl !== null && method_exists($this, 'getRegisteredTools'))
            ? ($this->getRegisteredTools($kubectl)[$tool->value] ?? [])
            : [];

        $storedAppName = $registered['brand_name'] ?? null;
        $storedLogoUrl = $registered['logo_url'] ?? null;

        // Determine final appName
        $appName = match (true) {
            $flagAppName !== '' => $flagAppName,
            $storedAppName !== null && $storedAppName !== '' => $storedAppName,
            default => null,
        };

        // Determine final logoUrl
        $logoUrl = match (true) {
            $flagLogoUrl !== '' => $flagLogoUrl,
            $storedLogoUrl !== null && $storedLogoUrl !== '' => $storedLogoUrl,
            default => null,
        };

        // If interactive, tool supports whitelabeling, and no explicit flag or stored value exists, prompt for app name
        if ($appName === null && $tool->whiteLabel() !== null && method_exists($this, 'cannotPrompt') && ! $this->cannotPrompt()) {
            $prompted = text(
                label: "Custom brand name for {$tool->productName()}",
                placeholder: "e.g. Acme {$tool->brandName()}",
                default: $tool->brandName(),
            );
            $appName = trim($prompted) !== '' ? trim($prompted) : $tool->brandName();
        }

        if ($appName === null) {
            $appName = $tool->brandName();
        }

        // Save updated branding to cluster registry if changed or freshly supplied
        if ($kubectl !== null && method_exists($this, 'registerTool')) {
            $updates = [];
            if ($appName !== $tool->brandName() || $storedAppName !== null) {
                $updates['brand_name'] = $appName;
            }
            if ($logoUrl !== null) {
                $updates['logo_url'] = $logoUrl;
            }
            if ($updates !== []) {
                $this->registerTool($kubectl, $tool, $updates);
            }
        }

        return [
            'appName' => $appName,
            'logoUrl' => $logoUrl,
        ];
    }
}
