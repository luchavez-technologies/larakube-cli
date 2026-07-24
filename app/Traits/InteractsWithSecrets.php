<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

trait InteractsWithSecrets
{
    /** Status + body of the last failed Infisical API call, for diagnostics. */
    protected ?string $lastInfisicalError = null;

    /** The dedicated namespace the Infisical Secrets Manager lives in. */
    protected function secretsNamespace(): string
    {
        return 'larakube-secrets';
    }

    /** Build the kubectl command, optionally scoped to a specific context, pinned to ~/.kube/config. */
    protected function secretsKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    /** Infisical backend Deployment present? A cheap "is Infisical installed" probe. */
    protected function isSecretsInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment infisical-backend -n {$ns} --no-headers")->output();

        return trim($out) !== '';
    }

    /**
     * True when the Infisical bootstrap secret exists in the secrets namespace.
     */
    protected function isInfisicalBootstrapped(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get secret infisical-bootstrap -n {$ns} --no-headers 2>/dev/null")->output();

        return trim($out) !== '';
    }

    /**
     * Read a key from the infisical-bootstrap Secret.  Returns null when the
     * secret doesn't exist or the key is missing.
     */
    protected function readInfisicalBootstrapSecret(string $kubectl, string $ns, string $key): ?string
    {
        $out = trim(Process::run(
            "{$kubectl} get secret infisical-bootstrap -n {$ns} -o jsonpath='{.data.{$key}}' 2>/dev/null",
        )->output());

        return $out !== '' ? base64_decode($out) : null;
    }

    /**
     * Return access information for the Infisical instance — null if not
     * installed. Supports both local (TLD-derived) and cloud (persisted host)
     * environments. The optional $context lets callers target a specific
     * kube-context (e.g. from AboutCommand).
     *
     * @return array{host: string, label: string}|null
     */
    protected function secretsAccess(string $environment, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->secretsKubectl($context);
        $ns = $this->secretsNamespace();

        if (! $this->isSecretsInstalled($kubectl, $ns)) {
            return null;
        }

        $host = $this->resolveSecretsHostReadOnly($environment, $config);

        return [
            'host' => $host ?? '',
            'label' => 'Infisical',
        ];
    }

    /** Resolve the Infisical host from config or derive from TLD (read-only, no prompt). */
    protected function resolveSecretsHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::SECRETS;

        if ($config && $env !== 'local') {
            $host = $config->getEnvironment($env)?->hosts[$service->value] ?? null;
            if ($host !== null) {
                return $host;
            }
        }

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return null;
    }

    /**
     * Make an authenticated HTTP request to the Infisical API.
     * Uses kubectl port-forward for cluster access, Laravel Http facade for requests.
     *
     * @param  string  $kubectl  Full kubectl command (with context if scoped)
     * @param  string  $method  GET | POST | PATCH | DELETE
     * @param  string  $path  API path, e.g. /api/v1/auth/signup
     * @param  array|null  $data  Request body (null = no body)
     * @param  string|null  $token  Bearer token (null = no auth header)
     */
    protected function infisicalApi(
        string $kubectl,
        string $method,
        string $path,
        ?array $data = null,
        ?string $token = null,
    ): ?array {
        $ns = $this->secretsNamespace();
        $port = random_int(30000, 31000);

        $pf = Process::start("{$kubectl} port-forward -n {$ns} svc/infisical-backend {$port}:8080");

        if ($pf->running()) {
            usleep(2_000_000);
        }

        try {
            $url = "http://localhost:{$port}{$path}";

            $request = Http::timeout(15)->withoutVerifying();

            if ($token !== null) {
                $request = $request->withToken($token);
            }

            if ($data !== null) {
                $response = $request->withBody(json_encode($data), 'application/json')->send($method, $url);
            } else {
                $response = $request->send($method, $url);
            }

            if ($response->failed()) {
                // Keep the status + body. Discarding them turned every failure
                // into an unexplained "failed" spinner with nothing to act on —
                // an expired token, a wrong field name and an unreachable
                // backend were all indistinguishable.
                $this->lastInfisicalError = trim(sprintf(
                    'HTTP %d %s — %s',
                    $response->status(),
                    $method,
                    Str::limit(trim($response->body()), 300) ?: '(empty body)',
                ));

                return null;
            }

            $this->lastInfisicalError = null;

            return $response->json();
        } finally {
            if ($pf->running()) {
                $pf->stop(0, 2);
            }
        }
    }
}
