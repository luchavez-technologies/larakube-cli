<?php

namespace App\Http\Integrations\Stalwart;

use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;

/**
 * Stalwart's JMAP endpoint is only reachable inside the cluster (no public
 * exposure when mail:init --vpn-only is set) — callers resolve a local port
 * via `kubectl port-forward` first and pass it in here. $authHeader is the
 * full header value (either "Bearer {api-key}" or "Basic {base64}") as
 * already computed by InteractsWithStalwartApi::stalwartAuthHeader() — kept
 * as one opaque string rather than re-deriving the scheme here, since that
 * method's fallback/bootstrap logic stays put for now (this connector only
 * replaces the transport, not the auth-resolution rules).
 */
class StalwartConnector extends Connector
{
    use AcceptsJson;

    public function __construct(protected readonly int $port, protected readonly string $authHeader) {}

    /**
     * The Base URL of the API
     */
    public function resolveBaseUrl(): string
    {
        return "http://localhost:{$this->port}";
    }

    protected function defaultHeaders(): array
    {
        return ['Authorization' => $this->authHeader];
    }
}
