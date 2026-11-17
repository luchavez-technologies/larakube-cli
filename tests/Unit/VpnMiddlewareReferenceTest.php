<?php

use Symfony\Component\Finder\Finder;

test('no manifest template hard-codes a VPN-only Middleware name', function (): void {
    // A literal drifts from the per-instance Middleware ensureVpnMiddleware()
    // creates; templates take it from ToolInstance::vpnMiddleware() instead.
    $offenders = [];

    foreach ((new Finder)->files()->in(resource_path('views/k8s'))->name('*.blade.php') as $file) {
        if (preg_match('/[a-z0-9-]+-vpn-only(?:-[a-z0-9-]+)?@kubernetescrd/', $file->getContents()) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});
