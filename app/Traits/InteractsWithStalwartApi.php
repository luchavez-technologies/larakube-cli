<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;
use stdClass;

trait InteractsWithStalwartApi
{
    protected function stalwartPodName(string $kubectl, string $ns): string
    {
        return trim(Process::run("{$kubectl} get pod -l app=stalwart -n {$ns} -o name --no-headers 2>/dev/null | head -1")->output()) ?: 'stalwart-0';
    }

    protected function stalwartBasicAuth(string $kubectl, string $ns): ?string
    {
        $password = $this->readMailSecret($kubectl, $ns, 'admin-password');
        if ($password === null) {
            return null;
        }

        return base64_encode('admin:'.$password);
    }

    protected function stalwartJmap(string $kubectl, string $ns, array $methodCalls): ?array
    {
        // JSON_UNESCAPED_SLASHES matters here: Stalwart's JMAP parser rejects the
        // request outright (400 notRequest) when method names like "x:Account/set"
        // arrive as the default-escaped "x:Account\/set" — valid JSON, but not what
        // its parser expects.
        $payload = json_encode([
            'methodCalls' => $methodCalls,
            'using' => ['urn:ietf:params:jmap:core', 'urn:stalwart:jmap'],
        ], JSON_UNESCAPED_SLASHES);

        $auth = $this->stalwartBasicAuth($kubectl, $ns);
        if ($auth === null) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'larakube_stalwart_');
        file_put_contents($tmp, $payload);

        $pod = $this->stalwartPodName($kubectl, $ns);

        $result = Process::run(
            "{$kubectl} exec -i -n {$ns} {$pod} -- "
            .'sh -c '.escapeshellarg(
                'curl -s -X POST http://localhost:8080/jmap '
                ."-H 'Content-Type: application/json' "
                ."-H 'Authorization: Basic {$auth}' "
                .'-d @-',
            )
            .' < '.escapeshellarg($tmp),
        );

        @unlink($tmp);

        if (! $result->successful()) {
            return null;
        }

        $response = json_decode($result->output(), true);

        return $response['methodResponses'] ?? null;
    }

    protected function stalwartAccounts(string $kubectl, string $ns): ?array
    {
        $responses = $this->stalwartJmap($kubectl, $ns, [
            ['x:Account/query', ['filter' => new stdClass], 'c0'],
            ['x:Account/get', ['ids' => []], 'c1'],
        ]);

        if ($responses === null || count($responses) < 2) {
            return null;
        }

        $ids = $responses[0][1]['ids'] ?? [];

        if ($ids === []) {
            return [];
        }

        $getResponses = $this->stalwartJmap($kubectl, $ns, [
            ['x:Account/get', ['ids' => $ids], 'c1'],
        ]);

        if ($getResponses === null) {
            return null;
        }

        return $getResponses[0][1]['list'] ?? [];
    }

    /**
     * The named MtaRoute (any @type) matching $name, or null if none exists.
     * Route names are immutable in Stalwart, so callers key off this to decide
     * create vs update.
     */
    protected function stalwartFindRoute(string $kubectl, string $ns, string $name): ?array
    {
        $responses = $this->stalwartJmap($kubectl, $ns, [
            ['x:MtaRoute/query', ['filter' => new stdClass], 'c0'],
            ['x:MtaRoute/get', ['ids' => null], 'c1'],
        ]);

        if ($responses === null || count($responses) < 2) {
            return null;
        }

        foreach ($responses[1][1]['list'] ?? [] as $route) {
            if (($route['name'] ?? null) === $name) {
                return $route;
            }
        }

        return null;
    }

    /**
     * Create or update a Relay-type MtaRoute (a smart-host outbound delivery
     * target). $name is immutable once created, so re-running with the same
     * name is idempotent — it patches the existing route in place.
     *
     * @return string|null the route's id, or null on failure
     */
    protected function stalwartUpsertRelayRoute(
        string $kubectl,
        string $ns,
        string $name,
        string $address,
        int $port,
        bool $implicitTls,
        string $username,
        string $password,
    ): ?string {
        $existing = $this->stalwartFindRoute($kubectl, $ns, $name);

        $props = [
            'address' => $address,
            'port' => $port,
            'protocol' => 'smtp',
            'implicitTls' => $implicitTls,
            'authUsername' => $username,
            'authSecret' => ['@type' => 'Value', 'secret' => $password],
        ];

        if ($existing !== null) {
            $id = $existing['id'];
            $responses = $this->stalwartJmap($kubectl, $ns, [
                ['x:MtaRoute/set', ['update' => [$id => $props]], 'c1'],
            ]);

            // A successful JMAP /set update returns `updated: {<id>: null}` — the
            // id maps to null, so isset() would misread success as failure.
            // array_key_exists() is the correct presence test here.
            $updated = $responses[0][1]['updated'] ?? [];

            return array_key_exists($id, $updated) ? $id : null;
        }

        $responses = $this->stalwartJmap($kubectl, $ns, [
            ['x:MtaRoute/set', ['create' => ['r1' => ['@type' => 'Relay', 'name' => $name] + $props]], 'c1'],
        ]);

        return $responses[0][1]['created']['r1']['id'] ?? null;
    }

    /** Delete a route by id. */
    protected function stalwartDeleteRoute(string $kubectl, string $ns, string $id): bool
    {
        $responses = $this->stalwartJmap($kubectl, $ns, [
            ['x:MtaRoute/set', ['destroy' => [$id]], 'c1'],
        ]);

        return in_array($id, $responses[0][1]['destroyed'] ?? [], true);
    }

    /**
     * The MtaOutboundStrategy singleton — its `route` field is an expression
     * ({else, match}) that resolves to the MtaRoute name used for each
     * outbound message. Stalwart's fixed id for this singleton is "singleton".
     */
    protected function stalwartOutboundStrategy(string $kubectl, string $ns): ?array
    {
        $responses = $this->stalwartJmap($kubectl, $ns, [
            ['x:MtaOutboundStrategy/get', ['ids' => ['singleton']], 'c1'],
        ]);

        return $responses[0][1]['list'][0] ?? null;
    }

    /**
     * Point outbound delivery's `else` branch at $routeName (e.g. a relay),
     * preserving whatever `match` rules are already configured (default:
     * local-domain mail stays on the "local" route). Passing 'mx' reverts to
     * Stalwart's default direct-MX delivery.
     */
    protected function stalwartSetOutboundRoute(string $kubectl, string $ns, string $routeName): bool
    {
        $current = $this->stalwartOutboundStrategy($kubectl, $ns);
        $match = $current['route']['match'] ?? ['0' => ['if' => 'is_local_domain(rcpt_domain)', 'then' => "'local'"]];

        $responses = $this->stalwartJmap($kubectl, $ns, [
            ['x:MtaOutboundStrategy/set', ['update' => ['singleton' => [
                // (object) cast: a numerically-string-keyed array (e.g. ['0' => ...],
                // which is what json_decode(..., true) hands back for the {"0": ...}
                // Stalwart returned) serializes as a JSON array via json_encode(),
                // but this field must round-trip as a JSON object.
                'route' => ['else' => "'{$routeName}'", 'match' => (object) $match],
            ]]], 'c1'],
        ]);

        return array_key_exists('singleton', $responses[0][1]['updated'] ?? []);
    }

    /**
     * Configured email domains (x:Domain — DNS/DKIM/TLS per domain). NOT
     * x:Tenant, which is Stalwart's unrelated multi-tenancy isolation concept;
     * querying that always came back empty even with real domains configured.
     */
    protected function stalwartDomains(string $kubectl, string $ns): ?array
    {
        $responses = $this->stalwartJmap($kubectl, $ns, [
            ['x:Domain/query', ['filter' => new stdClass], 'c0'],
            ['x:Domain/get', ['ids' => []], 'c1'],
        ]);

        if ($responses === null || count($responses) < 2) {
            return null;
        }

        $ids = $responses[0][1]['ids'] ?? [];

        if ($ids === []) {
            return [];
        }

        $getResponses = $this->stalwartJmap($kubectl, $ns, [
            ['x:Domain/get', ['ids' => $ids], 'c1'],
        ]);

        if ($getResponses === null) {
            return null;
        }

        return $getResponses[0][1]['list'] ?? [];
    }
}
