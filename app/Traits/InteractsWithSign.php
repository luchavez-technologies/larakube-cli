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
        return ClusterTool::SIGN->namespace();
    }

    protected function isSignInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment -l larakube-tool=sign -n {$ns} --no-headers --ignore-not-found")->output();

        return trim($out) !== '';
    }

    protected function readSignSecret(string $kubectl, ToolInstance $names, string $key, ?string $secret = null): ?string
    {
        return $this->readClusterSecretKey($kubectl, $names->namespace(), $secret ?? $names->secret(), $key);
    }

    /**
     * The Secret holding Documenso's P12 signing certificate and its
     * passphrase. Generated once and never rotated: documents already signed
     * keep verifying against it.
     */
    protected function ensureSignSigningCert(string $kubectl, ToolInstance $names): bool
    {
        $secret = $names->name('signing-cert');

        if ($this->readSignSecret($kubectl, $names, 'passphrase', $secret) !== null) {
            return true;
        }

        $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
        $dir = $temporaryDirectory->path();
        file_put_contents("{$dir}/passphrase", bin2hex(random_bytes(16)));

        // Documenso's P12 parser (node-forge) only reads the older PBE
        // encryption. OpenSSL 3 needs -legacy for it; LibreSSL and OpenSSL 1.1
        // already write it and reject the flag.
        $legacy = str_starts_with(trim(Process::run('openssl version')->output()), 'OpenSSL 3') ? ' -legacy' : '';

        $generated = Process::run(
            "openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -keyout {$dir}/key.pem -out {$dir}/cert.pem -subj "
            .escapeshellarg('/CN='.($names->host !== '' ? $names->host : $names->instance)),
        )->successful() && Process::run(
            "openssl pkcs12 -export{$legacy} -inkey {$dir}/key.pem -in {$dir}/cert.pem -out {$dir}/cert.p12 -passout file:{$dir}/passphrase",
        )->successful();

        $stored = $generated && Process::run(
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
