<?php

/**
 * Rotation is started by `secrets:wire`, which registers the OpenBao static
 * role AND creates the ExternalSecret that syncs each rotated password into
 * the tool's Secret. An `{tool}:init` that registers the role itself starts
 * the rotation with nothing to carry the result back, so the Deployment is
 * left holding a password Postgres has already replaced.
 *
 * SSO and Mail are the deliberate exceptions: they are foundational and wire
 * their own sync inside the same command.
 */
dataset('tool inits that must not start rotation', [
    'sign' => 'Sign/SignInitCommand.php',
    'record' => 'Record/RecordInitCommand.php',
    'resume' => 'Resume/ResumeInitCommand.php',
    'design' => 'Design/DesignInitCommand.php',
]);

test('a tool init never registers an OpenBao static role', function (string $file): void {
    $source = (string) file_get_contents(app_path("Commands/{$file}"));

    expect($source)->not->toContain('registerStaticRole(')
        ->and($source)->not->toContain('rotateStaticRole(');
})->with('tool inits that must not start rotation');
