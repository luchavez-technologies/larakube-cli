<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

trait GeneratesOfflineCertificates
{
    /**
     * Generate a server TLS certificate covering all provided domains (SANs).
     *
     * Two modes:
     * - Default (no company CA): generates a fresh LaraKube CA and self-signs.
     * - Full-sign ($companyCaCrt + $companyCaKey provided): skips generating a CA;
     *   signs the server cert with the company's CA instead. The browser already
     *   trusts the company CA, so no extra trust step is needed on client machines.
     *
     * @param  array<string>  $domains
     * @return array{ca_crt: string, tls_crt: string, tls_key: string}
     */
    public function generateSanCertificates(
        array $domains,
        string $outputDir,
        ?string $companyCaCrt = null,
        ?string $companyCaKey = null,
    ): array {
        $caKey = "$outputDir/ca.key";
        $caCrt = "$outputDir/ca.crt";
        $serverKey = "$outputDir/tls.key";
        $serverCsr = "$outputDir/tls.csr";
        $serverCrt = "$outputDir/tls.crt";
        $cnfFile = "$outputDir/openssl.cnf";

        $domains = array_values(array_filter($domains));
        $primaryDomain = $domains[0] ?? 'larakube.internal';
        $sanList = implode(',', array_map(fn ($d) => "DNS:$d", $domains));

        $cnfContent = <<<CNF
[req]
distinguished_name = req_distinguished_name
req_extensions = v3_req
prompt = no

[req_distinguished_name]
CN = {$primaryDomain}

[v3_req]
basicConstraints = CA:FALSE
keyUsage = nonRepudiation, digitalSignature, keyEncipherment
subjectAltName = {$sanList}
CNF;

        file_put_contents($cnfFile, $cnfContent);

        $fullSign = $companyCaCrt !== null && $companyCaKey !== null;

        if ($fullSign) {
            // Full-sign: use the company CA directly — no need to generate our own.
            $caCrt = $companyCaCrt;
            $caKey = $companyCaKey;
        } else {
            // Default: generate a fresh LaraKube CA.
            Process::run('openssl genrsa -out '.escapeshellarg($caKey).' 2048');
            Process::run('openssl req -x509 -new -nodes -key '.escapeshellarg($caKey).' -sha256 -days 3650 -out '.escapeshellarg($caCrt).' -subj "/CN=LaraKube Airgap CA"');
        }

        Process::run('openssl genrsa -out '.escapeshellarg($serverKey).' 2048');
        Process::run('openssl req -new -key '.escapeshellarg($serverKey).' -out '.escapeshellarg($serverCsr).' -config '.escapeshellarg($cnfFile).' -extensions v3_req');
        Process::run('openssl x509 -req -in '.escapeshellarg($serverCsr).' -CA '.escapeshellarg($caCrt).' -CAkey '.escapeshellarg($caKey).' -CAcreateserial -out '.escapeshellarg($serverCrt).' -days 3650 -sha256 -extfile '.escapeshellarg($cnfFile).' -extensions v3_req');

        return [
            'ca_crt' => $caCrt,
            'tls_crt' => $serverCrt,
            'tls_key' => $serverKey,
        ];
    }
}
