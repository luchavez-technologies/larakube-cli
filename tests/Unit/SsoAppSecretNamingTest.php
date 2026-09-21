<?php

/**
 * The Zitadel client credentials Secret is named like every other resource a
 * tool owns (ADR 0021), instance and all.
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

        public function name(ClusterTool $tool, string $instance, ?string $component = null): string
        {
            return $this->ssoAppSecretName($tool, $instance, $component);
        }
    };
}

test('the Zitadel app Secret carries the instance so two instances cannot collide', function (): void {
    $namer = ssoAppNamer();

    // {component}-sso-{instance}, the same shape as the tool's other Secrets.
    expect($namer->name(ClusterTool::NOTES, 'notes-luchtech-dev'))->toBe('outline-sso-notes-luchtech-dev')
        ->and($namer->name(ClusterTool::GIT, 'git-luchtech-dev'))->toBe('forgejo-sso-git-luchtech-dev');

    // Two instances of one tool must not resolve to the same Secret.
    expect($namer->name(ClusterTool::VPN, 'vpn-a-example-com'))
        ->not->toBe($namer->name(ClusterTool::VPN, 'vpn-b-example-com'));
});

test('a tool with two OIDC clients names the second after its component', function (): void {
    $namer = ssoAppNamer();

    expect($namer->name(ClusterTool::CHAT, 'chat-luchtech-dev', 'mas'))
        ->toBe('chat-mas-sso-chat-luchtech-dev');
});
