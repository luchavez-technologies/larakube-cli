<?php

namespace App\Commands;

use App\Traits\InteractsWithTrust;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class TrustCommand extends Command
{
    use InteractsWithTrust, LaraKubeOutput;

    protected $signature = 'trust {ca-file? : Path to a CA file to trust (e.g. from an air-gapped bundle)}';

    protected $description = 'Install the LaraKube Local CA into your system trust store for seamless HTTPS';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Local HTTPS Setup');

        if (file_exists('/.dockerenv') && ! $this->isWsl()) {
            $this->error('  ✖ The "trust" command must be run directly on your HOST (or WSL2) machine.');

            return 1;
        }

        $customCa = $this->argument('ca-file');

        if ($customCa) {
            if (! file_exists($customCa)) {
                $this->error("  ✖ CA file not found: {$customCa}");

                return 1;
            }
            $this->info("  📦 Trusting custom CA from {$customCa}");

            return $this->installCaToKeychain($customCa);
        }

        $this->info('  🔑 Generating persistent LaraKube Local CA (stored at ~/.larakube/certificates/)...');
        $this->ensureLocalCaExists();
        $this->info('  ✅ CA ready.');

        $result = $this->installCaToKeychain($this->getLocalCaCertPath());
        if ($result !== 0) {
            return $result;
        }

        $this->setupDnsmasq();

        $this->line('');
        $this->laraKubeInfo('✅ LaraKube Local CA is trusted! Restart your browser to apply.');

        return 0;
    }
}
