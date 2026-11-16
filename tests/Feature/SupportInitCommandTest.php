<?php

use App\Enums\ClusterTool;

test('support manifest reads SMTP_ENABLE_STARTTLS_AUTO from the SMTP Secret in both containers', function (): void {
    // Wired keys come from the wirer's Secret: mail:wire/sso:wire store their
    // static keys there and import it with --from=secret (ADR 0018), so the
    // live Deployment reads them via valueFrom. A literal here can't be merged
    // with that on re-apply ("valueFrom ... may not be specified when `value`
    // is not empty", Outline on luchtech-vps).
    $manifest = view('k8s.chatwoot.shared', [
        'host' => 'support.example.test',
        'appName' => 'Support',
        'logoUrl' => null,
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 3,
        'vpnOnly' => false,
        'isLocal' => true,
        'proxied' => false,
    ])->render();

    preg_match_all('/- name: SMTP_ENABLE_STARTTLS_AUTO\s*\n\s*valueFrom:\s*\n\s*secretKeyRef:\s*\n\s*name: \S+\s*\n\s*key: SMTP_ENABLE_STARTTLS_AUTO\b/', $manifest, $matches);

    expect($matches[0])->toHaveCount(2)
        ->and(ClusterTool::CHATWOOT->smtpEnv()['static']['SMTP_ENABLE_STARTTLS_AUTO'] ?? null)->toBe('true');
});
