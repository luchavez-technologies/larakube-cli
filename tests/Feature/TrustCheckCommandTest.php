<?php

use App\Traits\ManagesLocalCa;
use Illuminate\Support\Facades\Artisan;

function trustCheckCaHarness(): object
{
    return new class
    {
        use ManagesLocalCa;

        public function ensureCert(string $appName, ?string $tld = null): void
        {
            $this->ensureAppCertExists($appName, $tld);
        }
    };
}

function cleanupTrustCheckCertFor(string $appName): void
{
    $dir = $_SERVER['HOME'].'/.larakube/certificates';
    @unlink("{$dir}/{$appName}-dev.crt");
    @unlink("{$dir}/{$appName}-dev.key");
    @unlink("{$dir}/{$appName}-dev.tld");
}

test('trust:check reports an app cert as valid for its own pinned TLD, not the global default', function (): void {
    $appName = 'tc-app-'.uniqid();

    // The test sandbox's global TLD is 'kube' (TestCase points HOME at an
    // empty dir), but this app pinned its own 'test' override.
    trustCheckCaHarness()->ensureCert($appName, 'test');

    Artisan::call('trust:check');
    $output = Artisan::output();

    cleanupTrustCheckCertFor($appName);

    expect($output)->toContain("{$appName}")
        ->and($output)->toContain('(.test)');
});

test('trust:check flags a cert that no longer covers its recorded TLD', function (): void {
    $appName = 'tc-stale-'.uniqid();

    // Generate a cert for 'kube', then hand-edit the sidecar to claim a TLD
    // the cert doesn't actually cover (simulates a stale/corrupted sidecar).
    trustCheckCaHarness()->ensureCert($appName, 'kube');
    $dir = $_SERVER['HOME'].'/.larakube/certificates';
    file_put_contents("{$dir}/{$appName}-dev.tld", 'test');

    Artisan::call('trust:check');
    $output = Artisan::output();

    cleanupTrustCheckCertFor($appName);

    expect($output)->toContain("{$appName}")
        ->and($output)->toContain('wrong TLD');
});
