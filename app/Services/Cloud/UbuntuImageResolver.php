<?php

namespace App\Services\Cloud;

class UbuntuImageResolver
{
    public const DEFAULT_VERSION = '24.04';

    public const SUPPORTED_VERSIONS = ['24.04', '26.04'];

    /**
     * Resolve cloud provider OS image configuration for the given Ubuntu version.
     *
     * @return array{image?: string, amiPattern?: string}
     */
    public static function resolve(string $provider, string $version = self::DEFAULT_VERSION): array
    {
        $v = in_array($version, self::SUPPORTED_VERSIONS, true) ? $version : self::DEFAULT_VERSION;

        return match ($provider) {
            'hetzner' => [
                'image' => match ($v) {
                    '26.04' => 'ubuntu-26.04',
                    default => 'ubuntu-24.04',
                },
            ],
            'do' => [
                'image' => match ($v) {
                    '26.04' => 'ubuntu-26-04-x64',
                    default => 'ubuntu-24-04-x64',
                },
            ],
            'gcp' => [
                'image' => match ($v) {
                    '26.04' => 'ubuntu-os-cloud/ubuntu-2604-lts-amd64',
                    default => 'ubuntu-os-cloud/ubuntu-2404-lts-amd64',
                },
            ],
            'aws' => [
                'amiPattern' => match ($v) {
                    '26.04' => 'ubuntu/images/hvm-ssd-gp3/ubuntu-resolute-26.04-amd64-server-*',
                    default => 'ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*',
                },
            ],
            default => [],
        };
    }
}
