<?php

namespace App\Services\Cloud;

/**
 * The Ubuntu image each provider boots a new VPS from. LaraKube CLI servers
 * run Ubuntu 24.04 LTS (Noble Numbat) only; ADR 0034 says why not 26.04 yet.
 */
class UbuntuImageResolver
{
    public const VERSION = '24.04';

    /**
     * @return array{image?: string, amiPattern?: string}
     */
    public static function resolve(string $provider): array
    {
        return match ($provider) {
            'hetzner' => ['image' => 'ubuntu-24.04'],
            'do' => ['image' => 'ubuntu-24-04-x64'],
            'gcp' => ['image' => 'ubuntu-os-cloud/ubuntu-2404-lts-amd64'],
            'aws' => ['amiPattern' => 'ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*'],
            default => [],
        };
    }
}
