<?php

namespace App\Traits;

use App\Services\Kubectl;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * Wires an OpenBao static-role ExternalSecret (`creationPolicy: Merge`) into a
 * tenant project's Kubernetes Secret without needing a project working directory.
 *
 * Requires SyncsClusterSecrets + InteractsWithSecrets on the composing class.
 */
trait WiresTenantDbSecrets
{
    /**
     * Wire a tenant's DB_PASSWORD to an OpenBao static role via ExternalSecret
     * (creationPolicy: Merge), creating the target Secret first if absent so
     * ESO Merge never fails on a freshly staged namespace.
     */
    protected function wireTenantDbSecret(
        string $kubectl,
        string $namespace,
        string $roleName,
        string $secretName = 'laravel-secrets',
        string $passwordKey = 'DB_PASSWORD',
    ): bool {
        $kube = Kubectl::fromPrefix($kubectl);

        $existing = trim((string) Process::run(
            "{$kubectl} get secret ".escapeshellarg($secretName).' -n '.escapeshellarg($namespace).' --ignore-not-found -o name',
        )->output());

        if ($existing === '' && ! $kube->putSecret($namespace, $secretName, [])->ok) {
            return false;
        }

        $refreshTimeBefore = $this->externalSecretRefreshTime($kubectl, $namespace, 'laravel-secrets-db');

        $manifest = view('k8s.openbao.eso-db-static', [
            'namespace' => $namespace,
            'openbaoServer' => $this->openBaoServerUrl($kubectl),
            'secretName' => $secretName,
            'roleName' => $roleName,
            'passwordKey' => $passwordKey,
        ])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-eso-db-static-'.$roleName.'.yaml');
        file_put_contents($tmp, $manifest);
        $applied = Process::run("{$kubectl} apply -f ".escapeshellarg($tmp))->successful();
        $temporaryDirectory->delete();

        if (! $applied) {
            return false;
        }

        $this->forceExternalSecretReconcile($kubectl, $namespace, 'laravel-secrets-db');

        if (! $this->waitForExternalSecretSynced($kubectl, $namespace, 'laravel-secrets-db', $refreshTimeBefore)) {
            return false;
        }

        if ($kube->hasDeployment($namespace, 'web')) {
            $this->restartSecretConsumers($kubectl, $namespace, 'web');
        }

        return true;
    }
}
