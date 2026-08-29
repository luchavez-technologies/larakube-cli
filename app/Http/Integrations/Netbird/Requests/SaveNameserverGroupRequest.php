<?php

namespace App\Http\Integrations\Netbird\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Create or replace the split-DNS nameserver group for VPN-only hosts.
 *
 * Peers resolve these exact hosts through the in-cluster gateway instead of
 * public DNS, which is what makes a VPN-gated ingress reachable without an
 * /etc/hosts entry: public DNS answers with the cluster's public address, and
 * Traefik's allow-list then sees the user's ISP address and 403s.
 *
 * Two fields carry the whole design:
 *
 *   - `domains` is an EXACT list, never a wildcard. Pointing a whole zone here
 *     sends every hostname in it through one gateway pod -- including names
 *     served somewhere else entirely (a marketing site on a CDN, the SSO host
 *     the VPN itself depends on), which the gateway has no route for.
 *   - `primary` must be false. A primary group answers everything; this one is
 *     only meant to win for the domains listed above.
 */
class SaveNameserverGroupRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    protected Method $method;

    /**
     * @param  list<string>  $domains
     * @param  list<string>  $groups
     */
    public function __construct(
        protected readonly string $name,
        protected readonly string $resolverIp,
        protected readonly int $resolverPort,
        protected readonly array $domains,
        protected readonly array $groups,
        protected readonly ?string $id = null,
    ) {
        $this->method = $id === null ? Method::POST : Method::PUT;
    }

    public function resolveEndpoint(): string
    {
        return $this->id === null
            ? 'api/dns/nameservers'
            : 'api/dns/nameservers/'.$this->id;
    }

    protected function defaultBody(): array
    {
        return [
            'name' => $this->name,
            'description' => 'Split-DNS for VPN-only hosts, managed by the LaraKube CLI',
            'nameservers' => [[
                'ip' => $this->resolverIp,
                'ns_type' => 'udp',
                'port' => $this->resolverPort,
            ]],
            'enabled' => true,
            'groups' => $this->groups,
            'primary' => false,
            'domains' => $this->domains,
            // Exact hosts only, so appending a search domain can never help --
            // it can only turn a correct lookup into a surprising one.
            'search_domains_enabled' => false,
        ];
    }
}
