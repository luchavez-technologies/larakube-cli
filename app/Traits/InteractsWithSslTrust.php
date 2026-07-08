<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

trait InteractsWithSslTrust
{
    use DetectsWsl, InteractsWithOs;

    /**
     * Check if the LaraKube Local CA is already trusted by the system.
     */
    protected function isSslTrusted(): bool
    {
        if (file_exists('/.dockerenv')) {
            return false;
        }

        if ($this->isWsl()) {
            $result = Process::run('certutil.exe -verifystore Root "LaraKube Local CA"');
            $output = $result->output().$result->errorOutput();

            return str_contains($output, 'Certificate is valid') || str_contains($output, 'CertUtil: -verifystore command completed successfully');
        }

        if ($this->isDarwin()) {
            return Process::run('security find-certificate -c "LaraKube Local CA"')->output() !== '';
        }

        if ($this->isLinux()) {
            $paths = [
                '/usr/local/share/ca-certificates/larakube-local-ca.crt',
                '/etc/pki/ca-trust/source/anchors/larakube-local-ca.crt',
            ];

            foreach ($paths as $path) {
                if (file_exists($path)) {
                    return true;
                }
            }
        }

        return false;
    }
}
