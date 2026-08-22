<?php

namespace App\Http\Integrations\OpenBao;

use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;

/**
 * OpenBao is only ever reachable through a `kubectl port-forward` tunnel
 * (ClusterIP-only Service, no Ingress) — callers resolve a local port first
 * (see InteractsWithSecrets::openBaoApi()) and pass it in here. Auth is a
 * custom X-Vault-Token header, not Bearer, so this uses defaultHeaders()
 * rather than a TokenAuthenticator.
 */
class OpenBaoConnector extends Connector
{
    use AcceptsJson;

    public function __construct(protected readonly int $port, protected readonly ?string $token = null) {}

    /**
     * The Base URL of the API
     */
    public function resolveBaseUrl(): string
    {
        return "http://localhost:{$this->port}";
    }

    protected function defaultHeaders(): array
    {
        return $this->token !== null ? ['X-Vault-Token' => $this->token] : [];
    }
}
