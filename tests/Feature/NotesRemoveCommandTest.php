<?php

use Illuminate\Support\Facades\Process;

test('notes:remove tears down main\'s resources by their un-suffixed names', function (): void {
    Process::fake([
        '*get secret notes-secrets*' => Process::result(output: '', exitCode: 1),
        '*delete*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('notes:remove local --force')->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete')
        && str_contains($process->command, 'deployment/notes-outline')
        && str_contains($process->command, 'service/notes ')
        && str_contains($process->command, 'ingress/notes ')
        && str_contains($process->command, 'secret/notes-secrets ')
        && str_contains($process->command, 'secret/notes-outline-oidc')
        && str_contains($process->command, 'secret/notes-outline-smtp'));
});

test('notes:remove --domain scopes teardown to that instance\'s resources, not main\'s', function (): void {
    // Regression guard: teardown() used to delete deployment/notes-outline,
    // service/notes, and ingress/notes unconditionally — the exact fixed
    // names main uses — regardless of which instance --domain resolved to.
    // Removing a second instance would silently tear down main instead.
    Process::fake([
        '*get secret notes-secrets-blog-example-com*' => Process::result(output: '', exitCode: 1),
        '*delete*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('notes:remove local --domain=blog.example.com --force')->assertExitCode(0);

    Process::assertRan(function ($process) {
        if (! str_contains($process->command, 'delete')) {
            return false;
        }

        return str_contains($process->command, 'deployment/notes-outline-blog-example-com')
            && str_contains($process->command, 'service/notes-blog-example-com')
            && str_contains($process->command, 'ingress/notes-blog-example-com')
            && str_contains($process->command, 'secret/notes-secrets-blog-example-com')
            && str_contains($process->command, 'secret/notes-outline-oidc-blog-example-com')
            // The SMTP secret is a single fixed name mail:wire writes for
            // every instance (a separate, pre-existing gap) — removing a
            // non-main instance must never touch it, or it breaks main's
            // SMTP wiring.
            && ! str_contains($process->command, 'notes-outline-smtp')
            && ! str_contains($process->command, 'deployment/notes-outline ')
            && ! str_contains($process->command, 'service/notes ')
            && ! str_contains($process->command, 'ingress/notes ');
    });
});
