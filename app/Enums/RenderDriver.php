<?php

namespace App\Enums;

use App\Contracts\PlexProvisionable;

/**
 * Commons-only backing services with no project-level equivalent: nothing
 * "chooses" a renderer the way a project chooses its database or cache. A tool
 * that needs one requests it from the Commons (`sign:init` → headless Chrome).
 */
enum RenderDriver: string implements PlexProvisionable
{
    public function getLabel(): ?string
    {
        return match ($this) {
            self::HEADLESS_CHROME => 'Headless Chrome (PDF rendering)',
        };
    }

    public function isPlexReady(): bool
    {
        return true;
    }

    public function commonsServiceName(): ?string
    {
        return $this->value;
    }

    public function getDockerImage(): string
    {
        return match ($this) {
            self::HEADLESS_CHROME => 'docker.io/chromedp/headless-shell:151.0.7922.109',
        };
    }

    public function port(): int
    {
        return match ($this) {
            self::HEADLESS_CHROME => 9222,
        };
    }
    /** Headless Chromium over the DevTools protocol, for PDF rendering. */
    case HEADLESS_CHROME = 'headless-shell';
}
