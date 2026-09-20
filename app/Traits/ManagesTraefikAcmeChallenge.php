<?php

namespace App\Traits;

use App\Http\Integrations\Cloudflare\CloudflareConnector;
use App\Http\Integrations\Cloudflare\Requests\CreateDnsRecordRequest;
use App\Http\Integrations\Cloudflare\Requests\DeleteDnsRecordRequest;
use App\Http\Integrations\Cloudflare\Requests\GetIpRangesRequest;
use App\Services\Kubectl;
use Illuminate\Support\Arr;
use JsonException;
use Throwable;

/**
 * Which Let's Encrypt challenge a cloud cluster's Traefik uses, and what the
 * switch to the Cloudflare DNS challenge depends on.
 *
 * The cluster is the source of truth: the `traefik/traefik-acme-cloudflare`
 * Secret existing means "DNS challenge". Every Traefik render reads it, so
 * `cloud:init` or `traefik:setup` never silently reverts what `tls:init` set.
 */
trait ManagesTraefikAcmeChallenge
{
    protected const TRAEFIK_ACME_TOKEN_SECRET = 'traefik-acme-cloudflare';

    /** The environment variable non-interactive `tls:init` reads the token from. */
    protected const TLS_TOKEN_ENV = 'LARAKUBE_CLOUDFLARE_TOKEN';

    protected function traefikUsesDnsChallenge(string $kubectl): bool
    {
        return trim(Kubectl::fromPrefix($kubectl)->raw(['get', 'secret', self::TRAEFIK_ACME_TOKEN_SECRET, '-n', 'traefik', '-o', 'name', '--ignore-not-found'])->output) !== '';
    }

    /** Managed clusters (DOKS) keep acme.json on a PVC; VPS clusters use a hostPath. */
    protected function traefikIsManaged(string $kubectl): bool
    {
        return trim(Kubectl::fromPrefix($kubectl)->raw(['get', 'pvc', 'traefik-acme', '-n', 'traefik', '-o', 'name', '--ignore-not-found'])->output) !== '';
    }

    /** The ACME email the running Traefik already uses, if any. */
    protected function liveTraefikAcmeEmail(string $kubectl): ?string
    {
        $args = $this->liveTraefikArgs($kubectl);

        return preg_match('/acme\.email=([^"\s,\]]+)/', $args, $m) === 1 ? $m[1] : null;
    }

    /**
     * The Cloudflare edge ranges Traefik should trust for forwarded headers,
     * so apps see the visitor's IP instead of Cloudflare's. Fetched fresh
     * (Cloudflare changes them now and then); if that fails, the ranges the
     * running Traefik already trusts, never none while hosts may be proxied.
     *
     * @return list<string>
     */
    protected function cloudflareTrustedRanges(string $kubectl): array
    {
        try {
            $response = CloudflareConnector::make('')->send(GetIpRangesRequest::make());
            $body = $response->json();
            $ranges = [...($body['result']['ipv4_cidrs'] ?? []), ...($body['result']['ipv6_cidrs'] ?? [])];
            if ($response->successful() && ($body['success'] ?? false) === true && $ranges !== []) {
                return array_values($ranges);
            }
        } catch (Throwable) {
            // Fall through to what's already live.
        }

        $args = $this->liveTraefikArgs($kubectl);

        return preg_match('/forwardedHeaders\.trustedIPs=([^"\s\]]+)/', $args, $m) === 1 ? explode(',', $m[1]) : [];
    }

    /**
     * Every Ingress on the cluster, reduced to what certificate management
     * cares about.
     *
     * @return list<array{ingress: string, hosts: list<string>, letsencrypt: bool, proxied: bool}>
     */
    protected function clusterIngresses(string $kubectl): array
    {
        $json = Kubectl::fromPrefix($kubectl)->raw(['get', 'ingress', '-A', '-o', 'json'])->json();

        return array_values(array_map(function (array $item): array {
            $annotations = $item['metadata']['annotations'] ?? [];

            return [
                'ingress' => ($item['metadata']['namespace'] ?? '').'/'.($item['metadata']['name'] ?? ''),
                'hosts' => array_values(array_filter(array_map(
                    fn (array $rule) => (string) ($rule['host'] ?? ''),
                    $item['spec']['rules'] ?? [],
                ))),
                'letsencrypt' => ($annotations['traefik.ingress.kubernetes.io/router.tls.certresolver'] ?? null) === 'letsencrypt',
                'proxied' => ($annotations['external-dns.alpha.kubernetes.io/cloudflare-proxied'] ?? null) === 'true',
            ];
        }, is_array($json) ? ($json['items'] ?? []) : []));
    }

    /**
     * Hosts whose certificates Traefik obtains from Let's Encrypt.
     *
     * @param  list<array{ingress: string, hosts: list<string>, letsencrypt: bool, proxied: bool}>  $ingresses
     * @return list<string>
     */
    protected function letsEncryptHosts(array $ingresses): array
    {
        $hosts = [];
        foreach ($ingresses as $ingress) {
            if ($ingress['letsencrypt']) {
                array_push($hosts, ...$ingress['hosts']);
            }
        }

        $hosts = array_values(array_unique($hosts));
        sort($hosts);

        return $hosts;
    }

    /**
     * Hosts routed through the Cloudflare proxy.
     *
     * @param  list<array{ingress: string, hosts: list<string>, letsencrypt: bool, proxied: bool}>  $ingresses
     * @return list<string>
     */
    protected function proxiedHosts(array $ingresses): array
    {
        $hosts = [];
        foreach ($ingresses as $ingress) {
            if ($ingress['proxied']) {
                array_push($hosts, ...$ingress['hosts']);
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * The zone a host belongs to (the longest matching zone name), or null.
     *
     * @param  list<string>  $zones
     */
    protected function zoneForHost(string $host, array $zones): ?string
    {
        $match = null;
        foreach ($zones as $zone) {
            $zone = strtolower($zone);
            $fits = strtolower($host) === $zone || str_ends_with(strtolower($host), ".{$zone}");
            if ($fits && ($match === null || strlen($zone) > strlen($match))) {
                $match = $zone;
            }
        }

        return $match;
    }

    /**
     * Domains of the certificates stored in Traefik's acme.json. Only domain
     * names leave the pod; the file also holds private keys.
     *
     * @return list<string>
     */
    protected function storedCertificateDomains(string $kubectl): array
    {
        $output = Kubectl::fromPrefix($kubectl)->exec('traefik', 'deploy/traefik', [
            'sh', '-c', 'cat /acme/acme.json /data/acme.json 2>/dev/null | grep -o \'"main": *"[^"]*"\'',
        ])->output;

        preg_match_all('/"main":\s*"([^"]+)"/', $output, $matches);

        $domains = array_values(array_unique($matches[1]));
        sort($domains);

        return $domains;
    }

    /**
     * Prove the token can write DNS in this zone: create, then delete, a
     * short-lived TXT record. A read-only token passes every other check and
     * only fails months later, at renewal.
     */
    protected function cloudflareCanWriteDns(string $token, string $zoneId, string $zone): bool
    {
        $connector = CloudflareConnector::make($token);

        $create = $connector->send(CreateDnsRecordRequest::make(
            $zoneId, 'TXT', "_larakube-tls-check.{$zone}", 'larakube tls:init write check', 60,
        ));

        try {
            $body = $create->json();
        } catch (JsonException) {
            return false;
        }

        $recordId = Arr::get($body, 'result.id');
        if ($create->failed() || Arr::get($body, 'success') !== true || $recordId === null) {
            return false;
        }

        $connector->send(DeleteDnsRecordRequest::make($zoneId, (string) $recordId));

        return true;
    }

    /** The running Traefik's container arguments, as the jsonpath array string. */
    private function liveTraefikArgs(string $kubectl): string
    {
        return Kubectl::fromPrefix($kubectl)->raw([
            'get', 'deployment', 'traefik', '-n', 'traefik',
            '-o', 'jsonpath={.spec.template.spec.containers[0].args}', '--ignore-not-found',
        ])->output;
    }
}
