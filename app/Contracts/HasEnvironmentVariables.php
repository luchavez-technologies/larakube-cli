<?php

namespace App\Contracts;

use App\Data\ConfigData;

interface HasEnvironmentVariables
{
    /**
     * Get the environment variables for the component.
     *
     * @param  ConfigData|null  $config  The project configuration.
     * @param  string  $environment  The environment (local, production).
     * @return array<string, string>
     */
    public function getEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array;

    /**
     * Get only the non-sensitive environment variables (for ConfigMaps).
     */
    public function getPublicEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array;

    /**
     * Get only the sensitive environment variables (for Secrets).
     */
    public function getSecretEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array;
}
