<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

/**
 * The reusable "put this secret in Infisical and sync it into a namespace"
 * primitive.
 *
 * This existed only as a wall of inline YAML string-concatenation inside
 * MailInitCommand::configureStalwartStore(), so nothing else could use it —
 * which is exactly why Commons credentials never became env-based. Extracted
 * here so plex:rotate, plex:join and every {tool}:init share one implementation
 * of the CRD triple (auth Secret → InfisicalAuth → InfisicalStaticSecret).
 *
 * Everything is best-effort and reports its own failures: Infisical is an
 * OPTIONAL capability. A cluster without it falls back to literal values, so a
 * missing bootstrap must never be fatal — but it must never be silent either.
 */
trait SyncsInfisicalSecrets
{
    use InteractsWithSecrets;

    /**
     * Whether this cluster can back secrets with Infisical at all. Callers use
     * this to choose between the env-backed and literal paths.
     */
    protected function infisicalAvailable(string $kubectl): bool
    {
        return $this->isInfisicalBootstrapped($kubectl, $this->secretsNamespace());
    }

    /**
     * Write (or overwrite) a single secret value in the LaraKube Infisical
     * project. Returns false when Infisical isn't usable or the API refused —
     * the caller decides whether that's fatal.
     */
    protected function pushInfisicalSecret(string $kubectl, string $key, string $value, string $environment = 'production'): bool
    {
        $token = $this->readInfisicalBootstrapSecret($kubectl, $this->secretsNamespace(), 'bootstrap-token');
        if ($token === null) {
            return false;
        }

        $projectId = $this->readInfisicalBootstrapSecret($kubectl, $this->secretsNamespace(), 'project-id');
        if ($projectId === null) {
            return false;
        }

        // POST creates; if the key already exists Infisical answers 400, so a
        // rotation has to fall through to PATCH on the same path.
        $created = $this->infisicalApi($kubectl, 'POST', "/api/v3/secrets/raw/{$key}", [
            'workspaceId' => $projectId,
            'environment' => $environment,
            'type' => 'shared',
            'secretValue' => $value,
        ], $token);

        if (is_array($created) && ! isset($created['error'])) {
            return true;
        }

        $updated = $this->infisicalApi($kubectl, 'PATCH', "/api/v3/secrets/raw/{$key}", [
            'workspaceId' => $projectId,
            'environment' => $environment,
            'type' => 'shared',
            'secretValue' => $value,
        ], $token);

        return is_array($updated) && ! isset($updated['error']);
    }

    /**
     * Ensure the Infisical operator syncs the LaraKube project's secrets into
     * a native k8s Secret named $secretName inside $ns, so workloads there can
     * consume them via envFrom/secretKeyRef.
     *
     * Idempotent: re-applying the same manifests is a no-op, which is what
     * makes it safe to call from both {tool}:init and plex:rotate.
     */
    protected function syncInfisicalToNamespace(string $kubectl, string $ns, string $secretName, string $environment = 'production'): bool
    {
        $secretsNs = $this->secretsNamespace();

        $projectId = $this->readInfisicalBootstrapSecret($kubectl, $secretsNs, 'project-id');
        $clientId = $this->readInfisicalBootstrapSecret($kubectl, $secretsNs, 'client-id');
        $clientSecret = $this->readInfisicalBootstrapSecret($kubectl, $secretsNs, 'client-secret');

        if ($projectId === null || $clientId === null || $clientSecret === null) {
            $missing = array_keys(array_filter([
                'project-id' => $projectId === null,
                'client-id' => $clientId === null,
                'client-secret' => $clientSecret === null,
            ]));

            $this->line(
                '  <fg=gray>Could not create the Infisical sync CRD — missing from infisical-bootstrap: </>'
                .'<fg=yellow>'.implode(', ', $missing).'</><fg=gray>. Re-run </><fg=blue>larakube secrets:init</><fg=gray>.</>',
            );

            return false;
        }

        $authName = "{$secretName}-infisical-auth";

        $manifest = view('k8s.secrets.infisical-sync', [
            'namespace' => $ns,
            'authName' => $authName,
            'secretName' => $secretName,
            'clientId' => base64_encode($clientId),
            'clientSecret' => base64_encode($clientSecret),
            'environmentSlug' => $environment,
            'hostAPI' => "http://infisical-backend.{$secretsNs}.svc.cluster.local:8080",
        ])->render();

        $tmp = tempnam(sys_get_temp_dir(), 'larakube_infisical_sync_');
        file_put_contents($tmp, $manifest);
        $ok = Process::run("{$kubectl} apply -f ".escapeshellarg($tmp))->successful();
        @unlink($tmp);

        return $ok;
    }

    /**
     * Nudge the workloads that consume a synced Secret so they pick up a
     * rotated value. The operator updates the Secret in place, but a process
     * that read its env at startup keeps the old value until it restarts.
     */
    protected function restartSecretConsumers(string $kubectl, string $ns, string $deployment): bool
    {
        return Process::run("{$kubectl} rollout restart deployment/{$deployment} -n {$ns}")->successful();
    }
}
