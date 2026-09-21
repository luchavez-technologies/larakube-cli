<?php

/**
 * The Zitadel client credentials Secret is instance-suffixed (ADR 0021).
 *
 * It was `sso-app-{tool}` with no instance, so two instances of the same tool
 * were two distinct Zitadel clients writing to ONE Secret — the second wire
 * silently overwrote the first's client-id/secret.
 */

use App\Enums\ClusterTool;
use App\Traits\InteractsWithSso;

function ssoAppNamer(): object
{
    return new class
    {
        use InteractsWithSso;

        public function name(ClusterTool $tool, ?string $instance): string
        {
            return $this->ssoAppSecretName($tool, $instance);
        }
    };
}

test('the Zitadel app Secret carries the instance so two instances cannot collide', function (): void {
    $namer = ssoAppNamer();

    expect($namer->name(ClusterTool::VPN, 'vpn-luchtech-dev'))->toBe('sso-app-vpn-vpn-luchtech-dev')
        ->and($namer->name(ClusterTool::NOTES, 'notes-luchtech-dev'))->toBe('sso-app-notes-notes-luchtech-dev');

    // Two instances of one tool must not resolve to the same Secret.
    expect($namer->name(ClusterTool::VPN, 'vpn-a-example-com'))
        ->not->toBe($namer->name(ClusterTool::VPN, 'vpn-b-example-com'));
});

test('an empty instance is a failed host lookup, not a name', function (): void {
    // Falling back to the bare name pointed a wire at a Secret no other command
    // reads; the caller has to resolve a host first.
    $namer = ssoAppNamer();

    expect(fn () => $namer->name(ClusterTool::VPN, null))->toThrow(LogicException::class)
        ->and(fn () => $namer->name(ClusterTool::VPN, ''))->toThrow(LogicException::class);
});
