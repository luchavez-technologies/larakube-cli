<?php

namespace App\Contracts;

use App\Data\ToolSetting;

/**
 * An app with settings worth a form, and environment defaults it ships with.
 * The defaults are applied from their own ConfigMap ahead of the app's custom
 * variables, so a saved setting or a custom variable always wins.
 */
interface HasToolSettings
{
    /** @return list<ToolSetting> */
    public function settings(): array;

    /**
     * Every default this app sets through the environment, including ones that
     * aren't offered as settings.
     *
     * @return array<string, string>
     */
    public function defaultEnv(): array;
}
