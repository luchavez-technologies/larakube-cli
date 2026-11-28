<?php

namespace App\Support\Templates;

class CloudEndpoint
{
    public const PRODUCTION_URL = 'https://cloud.larakube.app';

    public const STAGING_URL = 'https://cloud-staging.larakube.app';

    /**
     * Resolve the LaraKube Cloud base URL for the current CLI channel.
     *
     * Prefers LARAKUBE_CLOUD_URL (or `templates.cloud_url` config) when set;
     * otherwise defaults to production for stable releases and staging for
     * canary / development builds.
     */
    public static function url(): string
    {
        $envUrl = getenv('LARAKUBE_CLOUD_URL');
        if (! is_string($envUrl) || trim($envUrl) === '') {
            $serverUrl = $_ENV['LARAKUBE_CLOUD_URL'] ?? $_SERVER['LARAKUBE_CLOUD_URL'] ?? null;
            $envUrl = is_string($serverUrl) ? $serverUrl : '';
        }

        if (trim($envUrl) !== '') {
            return rtrim(trim($envUrl), '/');
        }

        $configured = config('templates.cloud_url');
        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), '/');
        }

        return TrustedKeys::isStableRelease()
            ? self::PRODUCTION_URL
            : self::STAGING_URL;
    }
}
