<?php

use App\Vendors\NoteTool;

test('oidcEnv suffixes the deployment and secret name for a named instance', function (): void {
    // Confirmed live (2026-08-20): oidcEnv() accepted $instance but never
    // used it, hardcoding 'notes-outline' regardless — sso:wire --tool=notes
    // could never find a named instance's real deployment
    // (notes-outline-{instance}), only ever the unsuffixed default that
    // doesn't exist once an instance name is in play. smtpEnv() had the
    // identical bug.
    $tool = new NoteTool;

    $default = $tool->oidcEnv(null);
    expect($default['deployment'])->toBe('notes-outline')
        ->and($default['secret'])->toBe('notes-outline-oidc');

    $instanced = $tool->oidcEnv('notes-luchtech-dev');
    expect($instanced['deployment'])->toBe('notes-outline-notes-luchtech-dev')
        ->and($instanced['secret'])->toBe('notes-outline-oidc-notes-luchtech-dev');
});

test('smtpEnv suffixes the deployment and secret name for a named instance', function (): void {
    $tool = new NoteTool;

    $default = $tool->smtpEnv(null);
    expect($default['deployment'])->toBe('notes-outline')
        ->and($default['secret'])->toBe('notes-outline-smtp');

    $instanced = $tool->smtpEnv('notes-luchtech-dev');
    expect($instanced['deployment'])->toBe('notes-outline-notes-luchtech-dev')
        ->and($instanced['secret'])->toBe('notes-outline-smtp-notes-luchtech-dev');
});
