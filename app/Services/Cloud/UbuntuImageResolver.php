<?php

namespace App\Services\Cloud;

/**
 * The Ubuntu image each provider boots a new VPS from. LaraKube CLI servers
 * run Ubuntu 26.04 LTS (Resolute Raccoon) only.
 */
class UbuntuImageResolver
{
    public const VERSION = '26.04';

    /**
     * @return array{image?: string, amiPattern?: string}
     */
    public static function resolve(string $provider): array
    {
        return match ($provider) {
            'hetzner' => ['image' => 'ubuntu-26.04'],
            'do' => ['image' => 'ubuntu-26-04-x64'],
            'gcp' => ['image' => 'ubuntu-os-cloud/ubuntu-2604-lts-amd64'],
            'aws' => ['amiPattern' => 'ubuntu/images/hvm-ssd-gp3/ubuntu-resolute-26.04-amd64-server-*'],
            default => [],
        };
    }
}
