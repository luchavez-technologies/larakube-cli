<?php

namespace App\Traits;

use App\Enums\ClusterTool;

use function Laravel\Prompts\text;

/**
 * Resolve an operator-customized brand name and logo URL for a tool.
 *
 * Fallback priority for brand name:
 *   1. --app-name flag (if explicitly passed)
 *   2. The previous install's install-options stamp (`app-name`) on its workloads
 *   3. Interactive prompt (only when running interactively AND tool has whiteLabel() spec)
 *   4. ClusterTool::brandName() (static default)
 *
 * Fallback priority for logo URL:
 *   1. --logo-url flag (if explicitly passed)
 *   2. The previous install's install-options stamp (`logo-url`)
 *   3. null (no logo URL override)
 *
 * Whatever is settled on is written back into the options, so this install's
 * own stamp carries it forward to the next re-run or `tool:update`.
 */
trait ResolvesToolBranding
{
    /**
     * Resolve branding for the given tool, prompting interactively if supported.
     *
     * @return array{appName: string, logoUrl: ?string}
     */
    protected function resolveToolBranding(?string $kubectl, ClusterTool $tool, ?string $instance = null): array
    {
        $flagAppName = (string) ($this->option('app-name') ?? '');
        $flagLogoUrl = (string) ($this->option('logo-url') ?? '');

        $recorded = ($kubectl !== null && method_exists($this, 'recordedInstallOptions'))
            ? $this->recordedInstallOptions($kubectl, $tool, $instance)
            : [];

        $storedAppName = is_string($recorded['app-name'] ?? null) ? $recorded['app-name'] : null;
        $storedLogoUrl = is_string($recorded['logo-url'] ?? null) ? $recorded['logo-url'] : null;

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

        if (method_exists($this, 'rememberOption')) {
            if ($appName !== $tool->brandName()) {
                $this->rememberOption('app-name', $appName);
            }

            if ($logoUrl !== null) {
                $this->rememberOption('logo-url', $logoUrl);
            }
        }

        return [
            'appName' => $appName,
            'logoUrl' => $logoUrl,
        ];
    }
}
