<?php

namespace App\Services\Share;

use App\Http\Integrations\Cloudflare\CloudflareConnector;
use App\Http\Integrations\Cloudflare\Requests\CreateDnsRecordRequest;
use App\Http\Integrations\Cloudflare\Requests\CreateTunnelRequest;
use App\Http\Integrations\Cloudflare\Requests\DeleteDnsRecordRequest;
use App\Http\Integrations\Cloudflare\Requests\DeleteTunnelRequest;
use App\Http\Integrations\Cloudflare\Requests\GetTunnelTokenRequest;
use App\Http\Integrations\Cloudflare\Requests\ListDnsRecordsRequest;
use App\Http\Integrations\Cloudflare\Requests\ListTunnelsRequest;
use App\Http\Integrations\Cloudflare\Requests\ListZonesRequest;
use App\Http\Integrations\Cloudflare\Requests\PatchDnsRecordRequest;
use App\Http\Integrations\Cloudflare\Requests\PutTunnelConfigurationRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;
use Saloon\Http\Response;

/**
 * Gives a project stable public names for every service the browser talks to, under a domain the
 * person owns on Cloudflare: one tunnel, one route and one proxied DNS record per name. The API token
 * is held only for the length of the call and never written anywhere.
 */
final class DomainShare
{
    /** The label put in front of the project-and-box name for each service. */
    private const array PREFIXES = [
        'web' => '',
        'hmr' => 'vite-',
        'reverb' => 'ws-',
        'storage' => 's3-',
        'storage-console' => 's3c-',
    ];

    private readonly CloudflareConnector $api;

    public function __construct(string $token)
    {
        $this->api = CloudflareConnector::make($token);
    }

    /**
     * Every domain the token can see, with the account that owns it.
     *
     * @return list<array{id: string, name: string, accountId: string}>
     */
    public function zones(): array
    {
        $zones = [];
        $page = 1;

        do {
            $data = $this->result($this->api->send(ListZonesRequest::make($page)), 'list your Cloudflare domains (the token needs Zone → Zone → Read)');

            foreach (Arr::get($data, 'result', []) as $zone) {
                if (isset($zone['id'], $zone['name'], $zone['account']['id'])) {
                    $zones[] = ['id' => $zone['id'], 'name' => $zone['name'], 'accountId' => $zone['account']['id']];
                }
            }

            $totalPages = (int) (Arr::get($data, 'result_info.total_pages') ?? 1);
            $page++;
        } while ($page <= $totalPages);

        return $zones;
    }

    /**
     * The public host of each service: a single label under the domain, since Cloudflare's free
     * certificate covers `*.domain` but not a second level below it.
     *
     * @param  list<string>  $services  the keys of the services being shared
     * @return array<string, string> [service => host]
     */
    public function hosts(string $project, string $box, string $zone, array $services): array
    {
        $base = Str::slug($project.'-'.$box);
        $hosts = [];

        foreach ($services as $service) {
            $label = (self::PREFIXES[$service] ?? Str::slug($service).'-').$base;

            if (strlen($label) > 63) {
                throw new RuntimeException("The name '{$label}' is longer than the 63 characters a DNS label may have. Use a shorter project or dev box name.");
            }

            $hosts[$service] = $label.'.'.$zone;
        }

        return $hosts;
    }

    /**
     * Find the tunnel for this project and box or make it, and return its id and the token its connector runs on.
     *
     * @return array{id: string, token: string}
     */
    public function ensureTunnel(string $accountId, string $name): array
    {
        $found = $this->result($this->api->send(ListTunnelsRequest::make($accountId, $name)), 'list tunnels (the token needs Account → Cloudflare Tunnel → Edit)');
        $id = Arr::get($found, 'result.0.id');

        if ($id === null) {
            $created = $this->result($this->api->send(CreateTunnelRequest::make($accountId, $name)), 'create the tunnel (the token needs Account → Cloudflare Tunnel → Edit)');
            $id = Arr::get($created, 'result.id');
        }

        $token = Arr::get($this->result($this->api->send(GetTunnelTokenRequest::make($accountId, (string) $id)), 'read the tunnel token'), 'result');

        if (! is_string($id) || ! is_string($token) || $token === '') {
            throw new RuntimeException('Cloudflare did not return a tunnel to run.');
        }

        return ['id' => $id, 'token' => $token];
    }

    /**
     * Replace the tunnel's routes: each host to its service inside the cluster, then the catch-all.
     *
     * @param  array<string, string>  $hosts  [service => host]
     * @param  array<string, string>  $targets  [service => in-cluster URL]
     */
    public function route(string $accountId, string $tunnelId, array $hosts, array $targets): void
    {
        $ingress = [];

        foreach ($hosts as $service => $host) {
            $rule = ['hostname' => $host, 'service' => $targets[$service]];

            // Vite refuses a Host it does not list, and the public name is one it cannot know.
            if ($service === 'hmr') {
                $rule['originRequest'] = ['httpHostHeader' => 'localhost'];
            }

            $ingress[] = $rule;
        }

        $ingress[] = ['service' => 'http_status:404'];

        $this->result($this->api->send(PutTunnelConfigurationRequest::make($accountId, $tunnelId, $ingress)), 'set the tunnel routes');
    }

    /**
     * One proxied CNAME per host, pointing at the tunnel. A record that already exists is updated, not duplicated.
     *
     * @param  array<string, string>  $hosts  [service => host]
     */
    public function point(string $zoneId, string $tunnelId, array $hosts): void
    {
        $target = $tunnelId.'.cfargotunnel.com';

        foreach ($hosts as $host) {
            $existing = $this->result($this->api->send(ListDnsRecordsRequest::make($zoneId, 'CNAME', $host)), 'look up DNS records (the token needs Zone → DNS → Edit)');
            $recordId = Arr::get($existing, 'result.0.id');

            $this->result($this->api->send($recordId === null
                ? CreateDnsRecordRequest::make($zoneId, 'CNAME', $host, $target, 1, true)
                : PatchDnsRecordRequest::make($zoneId, (string) $recordId, $target, 1, true)), "set the DNS record for {$host}");
        }
    }

    /**
     * Remove the DNS records, then the tunnel itself. Records or a tunnel already gone are fine.
     *
     * @param  list<string>  $hosts
     */
    public function remove(string $accountId, string $zoneId, string $tunnelId, array $hosts): void
    {
        foreach ($hosts as $host) {
            $existing = $this->result($this->api->send(ListDnsRecordsRequest::make($zoneId, 'CNAME', $host)), 'look up DNS records');
            $recordId = Arr::get($existing, 'result.0.id');

            if ($recordId !== null) {
                $this->result($this->api->send(DeleteDnsRecordRequest::make($zoneId, (string) $recordId)), "delete the DNS record for {$host}");
            }
        }

        $response = $this->api->send(DeleteTunnelRequest::make($accountId, $tunnelId));

        // 404-ish "not found" means it is already gone, which is the goal.
        if ($response->failed() && $response->status() !== 404 && ! $this->alreadyGone($response)) {
            throw new RuntimeException('Cloudflare could not delete the tunnel: '.$this->message($response).' (stop the connector first: larakube share --stop).');
        }
    }

    /** @return array<string, mixed> */
    private function result(Response $response, string $doing): array
    {
        $data = $response->json();

        if ($response->failed() || Arr::get($data, 'success') !== true) {
            throw new RuntimeException("Cloudflare could not {$doing}: ".$this->message($response));
        }

        return $data;
    }

    private function message(Response $response): string
    {
        return (string) (Arr::get($response->json(), 'errors.0.message') ?? 'HTTP '.$response->status());
    }

    private function alreadyGone(Response $response): bool
    {
        return stripos($this->message($response), 'not found') !== false;
    }
}
