<?php

namespace App\Traits;

use App\Data\GlobalConfigData;
use App\Enums\CompanionDriver;

trait ManagesLocalCa
{
    protected function getLocalCaDir(): string
    {
        return home_path('.larakube/certificates');
    }

    protected function getLocalCaCertPath(): string
    {
        return $this->getLocalCaDir().'/ca.crt';
    }

    protected function getLocalCaKeyPath(): string
    {
        return $this->getLocalCaDir().'/ca.key';
    }

    protected function getAppCertsDir(): string
    {
        return $this->getLocalCaDir();
    }

    protected function getAppCertPath(string $appName): string
    {
        return $this->getAppCertsDir()."/{$appName}-dev.crt";
    }

    protected function getAppKeyPath(string $appName): string
    {
        return $this->getAppCertsDir()."/{$appName}-dev.key";
    }

    protected function getAppCertTldPath(string $appName): string
    {
        return $this->getAppCertsDir()."/{$appName}-dev.tld";
    }

    /**
     * The TLD an app's local cert was actually issued for — read from the sidecar
     * written alongside it, so diagnostics (trust:check) can verify a per-project
     * TLD override correctly instead of assuming every app uses the global TLD.
     * Falls back to the global default for certs generated before this existed.
     */
    protected function getAppCertTld(string $appName): string
    {
        $path = $this->getAppCertTldPath($appName);

        return file_exists($path) ? trim((string) file_get_contents($path)) : GlobalConfigData::load()->getLocalTld();
    }

    protected function getSystemCertPath(): string
    {
        return $this->getAppCertsDir().'/system-dev.crt';
    }

    protected function getSystemKeyPath(): string
    {
        return $this->getAppCertsDir().'/system-dev.key';
    }

    protected function localCaExists(): bool
    {
        return file_exists($this->getLocalCaKeyPath()) && file_exists($this->getLocalCaCertPath());
    }

    /**
     * Generate the persistent LaraKube Local CA at ~/.larakube/ if it does not already exist.
     */
    protected function ensureLocalCaExists(): void
    {
        if ($this->localCaExists()) {
            return;
        }

        @mkdir($this->getLocalCaDir(), 0700, true);

        exec('openssl genrsa -out '.escapeshellarg($this->getLocalCaKeyPath()).' 4096 2>/dev/null');
        exec(
            'openssl req -x509 -new -nodes'
            .' -key '.escapeshellarg($this->getLocalCaKeyPath())
            .' -sha256 -days 3650'
            .' -out '.escapeshellarg($this->getLocalCaCertPath())
            .' -subj "/CN=LaraKube Local CA/O=LaraKube" 2>/dev/null',
        );
    }

    /**
     * Ensure the per-app cert exists for {appName}.{tld} + *.{appName}.{tld}
     * + any additionalWebHosts (a fixed subdomain the wildcard already covers,
     * or a genuinely different local name it doesn't — e.g. a second brand).
     * Regenerates if missing, expiring within 30 days, covering the wrong TLD
     * (e.g. an old .dev.test cert from Phase 1 that needs upgrading to .kube),
     * or missing one of the additional hosts (so adding a new one later
     * triggers a refresh, not just the initial cert creation).
     * $tld defaults to the developer's global TLD; pass the project's own
     * getLocalTld() so a project-pinned TLD override gets a matching cert.
     *
     * @param  array<int, string>  $additionalHosts
     */
    protected function ensureAppCertExists(string $appName, ?string $tld = null, array $additionalHosts = []): void
    {
        $this->ensureLocalCaExists();
        @mkdir($this->getAppCertsDir(), 0700, true);

        $tld = $tld ?? GlobalConfigData::load()->getLocalTld();
        $crt = $this->getAppCertPath($appName);
        $key = $this->getAppKeyPath($appName);

        if (file_exists($crt) && file_exists($key)
            && $this->certIsValid($crt)
            && $this->certCoversHost($crt, "{$appName}.{$tld}")
            && collect($additionalHosts)->every(fn (string $host) => $this->certCoversHost($crt, $host))) {
            // Refresh the sidecar even when the cert itself didn't need
            // regenerating, so trust:check stays accurate for certs generated
            // before this was tracked.
            file_put_contents($this->getAppCertTldPath($appName), $tld);

            return;
        }

        $this->generateAppCert($appName, $tld, $additionalHosts);
    }

    /**
     * Ensure the system cert exists covering console.{tld}, traefik.{tld},
     * mailpit.{tld}, grafana.{tld}, the global companion hosts, and any
     * $additionalHosts the caller needs covered too (e.g. a Plex Commons'
     * current public hosts — minio.test, minio-console.test, …). Regenerates
     * if missing, expiring, covering the wrong TLD, or missing one of
     * $additionalHosts (so a newly-added Commons host triggers a refresh, not
     * just the initial cert creation) — mirrors ensureAppCertExists()'s
     * pattern for the same reason.
     *
     * @param  array<int, string>  $additionalHosts
     */
    protected function ensureSystemCertExists(array $additionalHosts = []): void
    {
        $this->ensureLocalCaExists();
        @mkdir($this->getAppCertsDir(), 0700, true);

        $crt = $this->getSystemCertPath();
        $key = $this->getSystemKeyPath();

        if (file_exists($crt) && file_exists($key)
            && $this->certIsValid($crt)
            && $this->certCoversHost($crt, 'console.'.GlobalConfigData::load()->getLocalTld())
            && collect($additionalHosts)->every(fn (string $host) => $this->certCoversHost($crt, $host))) {
            return;
        }

        $this->generateSystemCert($additionalHosts);
    }

    /**
     * $additionalHosts are extra SANs beyond {appName}.{tld} + its wildcard —
     * a fixed subdomain (already covered by the wildcard, listed anyway,
     * harmless) or a genuinely different local name (not covered by the
     * wildcard; this is what actually fixes it). Self-signed certs have no
     * ACME/DNS-challenge complexity, so listing arbitrary extra hostnames
     * here is trivial.
     *
     * @param  array<int, string>  $additionalHosts
     */
    protected function generateAppCert(string $appName, ?string $tld = null, array $additionalHosts = []): void
    {
        $dir = $this->getAppCertsDir();
        $csr = "{$dir}/{$appName}-dev.csr";
        $cnf = "{$dir}/{$appName}-dev.cnf";

        $tld = $tld ?? GlobalConfigData::load()->getLocalTld();
        $sans = implode(',', array_map(
            fn (string $host) => "DNS:{$host}",
            array_unique(["{$appName}.{$tld}", "*.{$appName}.{$tld}", ...$additionalHosts]),
        ));
        $cnfContent = <<<CNF
[req]
distinguished_name = req_distinguished_name
req_extensions     = v3_req
prompt             = no

[req_distinguished_name]
CN = {$appName}.{$tld}

[v3_req]
basicConstraints = CA:FALSE
keyUsage         = nonRepudiation, digitalSignature, keyEncipherment
subjectAltName   = {$sans}
CNF;

        $this->writeCert($cnf, $cnfContent, $csr, $this->getAppKeyPath($appName), $this->getAppCertPath($appName));
        file_put_contents($this->getAppCertTldPath($appName), $tld);
    }

    /**
     * @param  array<int, string>  $additionalHosts
     */
    protected function generateSystemCert(array $additionalHosts = []): void
    {
        $dir = $this->getAppCertsDir();
        $csr = "{$dir}/system-dev.csr";
        $cnf = "{$dir}/system-dev.cnf";

        $tld = GlobalConfigData::load()->getLocalTld();
        $companionHosts = array_map(fn ($c) => "{$c->value}.{$tld}", CompanionDriver::cases());
        // mailpit.{tld} (shared catch-all SMTP UI) and grafana.{tld} (shared
        // monitoring dashboard) are global hosts in larakube-shared, like
        // console/traefik, so the default cert must cover them. $additionalHosts
        // covers other global-namespace hosts this trait doesn't know about
        // itself — e.g. a Plex Commons' current public hosts.
        $systemHosts = array_unique(["console.{$tld}", "traefik.{$tld}", "mailpit.{$tld}", "grafana.{$tld}", ...$companionHosts, ...$additionalHosts]);
        $sans = implode(',', array_map(fn ($h) => "DNS:{$h}", $systemHosts));

        $cnfContent = <<<CNF
[req]
distinguished_name = req_distinguished_name
req_extensions     = v3_req
prompt             = no

[req_distinguished_name]
CN = console.{$tld}

[v3_req]
basicConstraints = CA:FALSE
keyUsage         = nonRepudiation, digitalSignature, keyEncipherment
subjectAltName   = {$sans}
CNF;

        $this->writeCert($cnf, $cnfContent, $csr, $this->getSystemKeyPath(), $this->getSystemCertPath());
    }

    /**
     * Build the Traefik tls.certificates YAML listing all known local certs.
     */
    protected function buildTraefikCertsYml(): string
    {
        $certs = $this->getAllLocalAppCerts();
        $lines = [
            'tls:',
            '  stores:',
            '    default:',
            '      defaultCertificate:',
            '        certFile: /certs/system-dev.pem',
            '        keyFile: /certs/system-dev-key.pem',
            '  certificates:',
            '    - certFile: /certs/system-dev.pem',
            '      keyFile: /certs/system-dev-key.pem',
            '      stores:',
            '        - default',
        ];

        foreach (array_keys($certs) as $appName) {
            $lines[] = "    - certFile: /certs/{$appName}-dev.pem";
            $lines[] = "      keyFile: /certs/{$appName}-dev-key.pem";
            $lines[] = '      stores:';
            $lines[] = '        - default';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Returns all locally-generated per-app cert pairs: [appName => [crt, key]].
     *
     * @return array<string, array{crt: string, key: string}>
     */
    protected function getAllLocalAppCerts(): array
    {
        $dir = $this->getAppCertsDir();
        $certs = [];

        foreach ((array) glob("{$dir}/*-dev.crt") as $crtPath) {
            $crtPath = (string) $crtPath;
            $baseName = basename($crtPath, '-dev.crt');
            if ($baseName === 'system') {
                continue;
            }
            $keyPath = "{$dir}/{$baseName}-dev.key";
            if (file_exists($keyPath)) {
                $certs[$baseName] = ['crt' => $crtPath, 'key' => $keyPath];
            }
        }

        return $certs;
    }

    protected function certIsValid(string $crtPath): bool
    {
        $parsed = $this->parseCert($crtPath);

        return $parsed !== null && $parsed['validTo_time_t'] > time() + (30 * 86400);
    }

    /** Cert expiry as a Unix timestamp, or null if the cert can't be read/parsed. */
    protected function getCertExpiry(string $crtPath): ?int
    {
        return $this->parseCert($crtPath)['validTo_time_t'] ?? null;
    }

    protected function certCoversHost(string $crtPath, string $host): bool
    {
        $parsed = $this->parseCert($crtPath);
        if ($parsed === null) {
            return false;
        }

        if (($parsed['subject']['CN'] ?? null) === $host) {
            return true;
        }

        $san = $parsed['extensions']['subjectAltName'] ?? '';

        return str_contains($san, "DNS:{$host}");
    }

    /**
     * Parse a cert in-process via PHP's openssl extension instead of shelling out
     * to the openssl CLI. trust:check inspects every locally-issued cert (one per
     * project), and forking a subprocess per check doesn't scale with the number
     * of projects — it also makes the check needlessly sensitive to transient
     * subprocess/fork pressure on a busy machine.
     *
     * @return array<string, mixed>|null
     */
    private function parseCert(string $crtPath): ?array
    {
        $contents = @file_get_contents($crtPath);
        if (! $contents) {
            return null;
        }

        $parsed = @openssl_x509_parse($contents);

        return $parsed ?: null;
    }

    private function writeCert(string $cnf, string $cnfContent, string $csr, string $keyPath, string $crtPath): void
    {
        file_put_contents($cnf, $cnfContent);

        exec('openssl genrsa -out '.escapeshellarg($keyPath).' 2048 2>/dev/null');
        exec('openssl req -new -key '.escapeshellarg($keyPath).' -out '.escapeshellarg($csr).' -config '.escapeshellarg($cnf).' 2>/dev/null');
        exec(
            'openssl x509 -req'
            .' -in '.escapeshellarg($csr)
            .' -CA '.escapeshellarg($this->getLocalCaCertPath())
            .' -CAkey '.escapeshellarg($this->getLocalCaKeyPath())
            .' -CAcreateserial'
            .' -out '.escapeshellarg($crtPath)
            .' -days 825 -sha256'
            .' -extfile '.escapeshellarg($cnf).' -extensions v3_req 2>/dev/null',
        );

        @unlink($cnf);
        @unlink($csr);
    }
}
