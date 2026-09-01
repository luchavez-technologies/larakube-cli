<?php

use App\Traits\DeploysClusterTool;

function localToolCertHolder(): object
{
    return new class
    {
        use DeploysClusterTool;

        public function local(string $host): bool
        {
            return $this->isLocalCertHost($host);
        }
    };
}

test('local hosts are recognised across every allowed TLD', function (): void {
    $holder = localToolCertHolder();

    // The bug this fixes: a second instance created with --domain= was never
    // added to the SAN list, so the browser rejected the https:// URL the
    // command had just printed.
    expect($holder->local('data-second.test'))->toBeTrue()
        ->and($holder->local('data.kube'))->toBeTrue()
        ->and($holder->local('notes.localhost'))->toBeTrue()
        ->and($holder->local('x.internal'))->toBeTrue();
});

test('real domains are left to Let\'s Encrypt', function (): void {
    $holder = localToolCertHolder();

    // Production issues per-host certs over HTTP-01; the local CA must never
    // try to mint one for a public domain.
    expect($holder->local('notes.luchtech.dev'))->toBeFalse()
        ->and($holder->local('data.example.com'))->toBeFalse()
        // Substring, not a suffix — must not match.
        ->and($holder->local('test.example.org'))->toBeFalse();
});
