<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

trait InteractsWithSign
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    protected function signNamespace(): string
    {
        return ClusterTool::DOCUMENSO->namespace();
    }

    protected function isSignInstalled(string $kubectl, string $ns): bool
    {
        return Kubectl::fromPrefix($kubectl)->hasDeploymentLabelled($ns, 'larakube-tool=sign');
    }

    protected function readSignSecret(string $kubectl, ToolInstance $names, string $key, ?string $secret = null): ?string
    {
        return $this->readClusterSecretKey($kubectl, $names->namespace(), $secret ?? $names->secret(), $key);
    }

    /**
     * The Secret holding Documenso's P12 signing certificate and its
     * passphrase. Generated once and never rotated: documents already signed
     * keep verifying against it.
     *
     * Built via ext-openssl (PHP's bundled OpenSSL functions), never the
     * `openssl` CLI binary: `tool:init` dispatches natively on Windows (see
     * WslBridge::NATIVE_WINDOWS_COMMANDS), and Windows has no `openssl.exe`
     * on PATH by default. ext-openssl's openssl_pkcs12_export() already
     * writes the older PBE encryption Documenso's P12 parser (node-forge)
     * needs — it's the `openssl` CLI's own default that changed with OpenSSL
     * 3 (hence the old `-legacy` flag this replaced), not the underlying
     * library function PHP calls directly.
     */
    protected function ensureSignSigningCert(string $kubectl, ToolInstance $names): bool
    {
        $secret = $names->name('signing-cert');

        if ($this->readSignSecret($kubectl, $names, 'passphrase', $secret) !== null) {
            return true;
        }

        $passphrase = bin2hex(random_bytes(16));
        $cn = $names->host !== '' ? $names->host : $names->instance;

        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($privateKey === false) {
            return false;
        }

        $csr = openssl_csr_new(['commonName' => $cn], $privateKey);

        if ($csr === false) {
            return false;
        }

        $certificate = openssl_csr_sign($csr, null, $privateKey, 3650);

        if ($certificate === false || ! openssl_pkcs12_export($certificate, $p12, $privateKey, $passphrase)) {
            return false;
        }

        $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
        $dir = $temporaryDirectory->path();
        file_put_contents("{$dir}/cert.p12", $p12);
        file_put_contents("{$dir}/passphrase", $passphrase);

        $stored = Process::run(
            "{$kubectl} create secret generic {$secret} -n {$names->namespace()} "
            ."--from-file=cert.p12={$dir}/cert.p12 --from-file=passphrase={$dir}/passphrase "
            ."--dry-run=client -o yaml | {$kubectl} apply -f -",
        )->successful();

        $temporaryDirectory->delete();

        return $stored;
    }

    protected function resolveSignHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::SIGN;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    protected function signAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = $this->signNamespace();

        if (! $this->isSignInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveSignHostReadOnly($env, $config),
            'label' => 'Documenso',
        ];
    }
}
