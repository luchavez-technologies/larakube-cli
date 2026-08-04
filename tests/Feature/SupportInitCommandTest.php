<?php

test('support manifest declares SMTP_ENABLE_STARTTLS_AUTO as a literal, not valueFrom, in both containers', function () {
    // Regression guard: mail:wire sets SMTP_ENABLE_STARTTLS_AUTO via a plain
    // literal (kubectl set env NAME=value), never through the
    // support-chatwoot-smtp Secret. Declaring it here as valueFrom made a
    // later support:init re-run fail — kubectl apply's merge re-adds
    // valueFrom on top of the live literal value mail:wire already set, and
    // the two are mutually exclusive (the exact bug confirmed live on
    // Documenso, 2026-08-05). Chatwoot's manifest declares its containers
    // (web + worker) with the same env block, so this must hold for both.
    $manifest = view('k8s.support.shared', [
        'host' => 'support.example.test',
        'appName' => 'Support',
        'logoUrl' => null,
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 3,
        'vpnOnly' => false,
        'isLocal' => true,
        'proxied' => false,
    ])->render();

    preg_match_all('/- name: SMTP_ENABLE_STARTTLS_AUTO\s*\n\s*(value|valueFrom):\s*"?([^"\n]*)"?/', $manifest, $matches, PREG_SET_ORDER);

    expect($matches)->toHaveCount(2);
    foreach ($matches as $m) {
        expect($m[1])->toBe('value')
            ->and(trim($m[2], '"'))->toBe('true');
    }
});
