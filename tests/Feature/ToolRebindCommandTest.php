<?php

use App\Enums\ClusterTool;
use App\Services\ToolRegistry;
use App\Traits\InteractsWithToolRegistry;
use Illuminate\Support\Facades\Process;

pest()->use(InteractsWithToolRegistry::class);

test('tool:rebind updates the registry host and re-applies the Ingress without changing the instance', function (): void {
    $initialJson = json_encode([
        ['tool' => 'stalwart', 'instance' => 'send-luchtech-dev', 'host' => 'send.luchtech.dev', 'installedAt' => '2026-08-01T00:00:00+00:00'],
    ]);

    $updatedJson = json_encode([
        ['tool' => 'stalwart', 'instance' => 'send-luchtech-dev', 'host' => 'mail.newdomain.com', 'installedAt' => '2026-08-01T00:00:00+00:00', 'updatedAt' => '2026-10-15T00:00:00+00:00'],
    ]);

    $callCount = 0;
    Process::fake([
        '*get secret larakube-tools-registry*' => function () use (&$callCount, $initialJson, $updatedJson) {
            $callCount++;

            return Process::result(output: base64_encode($callCount > 1 ? $updatedJson : $initialJson));
        },
        '*create secret generic larakube-tools-registry*' => Process::result(output: 'registry updated'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*get ingress*' => Process::result(output: ''),
        '*apply -f *' => Process::result(output: 'applied'),
        '*annotate ingress*' => Process::result(output: 'annotated'),
    ]);

    $this->artisan('tool:rebind', ['--tool' => 'stalwart', '--to' => 'mail.newdomain.com', '--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('now serves https://mail.newdomain.com')
        ->expectsOutputToContain('instance: send-luchtech-dev');
});

test('tool:rebind refuses when the tool is not registered', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([]))),
    ]);

    $this->artisan('tool:rebind', ['--tool' => 'stalwart', '--to' => 'mail.newdomain.com', '--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('is not registered on this cluster');
});

test('tool:rebind is a no-op when --to matches the current host', function (): void {
    $registryJson = json_encode([
        ['tool' => 'stalwart', 'instance' => 'send-luchtech-dev', 'host' => 'send.luchtech.dev', 'installedAt' => '2026-08-01T00:00:00+00:00'],
    ]);

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode($registryJson)),
    ]);

    $this->artisan('tool:rebind', ['--tool' => 'stalwart', '--to' => 'send.luchtech.dev', '--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('already serves send.luchtech.dev — nothing to rebind');
});

test('tool:rebind refuses when the new host already belongs to a different tool', function (): void {
    $registryJson = json_encode([
        ['tool' => 'stalwart', 'instance' => 'send-luchtech-dev', 'host' => 'send.luchtech.dev', 'installedAt' => '2026-08-01T00:00:00+00:00'],
        ['tool' => 'forgejo', 'instance' => 'git-luchtech-dev', 'host' => 'git.luchtech.dev', 'installedAt' => '2026-08-01T00:00:00+00:00'],
    ]);

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode($registryJson)),
    ]);

    $this->artisan('tool:rebind', ['--tool' => 'stalwart', '--to' => 'git.luchtech.dev', '--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('already registered to');
});

test('tool:rebind leaves the registry resolving the SAME instance for the new host — the mechanism sso:wire depends on to reuse its Zitadel project instead of orphaning it', function (): void {
    $initialJson = json_encode([
        ['tool' => 'matrix', 'instance' => 'chat-luchtech-dev', 'host' => 'chat.luchtech.dev', 'installedAt' => '2026-08-01T00:00:00+00:00'],
    ]);

    $updatedJson = json_encode([
        ['tool' => 'matrix', 'instance' => 'chat-luchtech-dev', 'host' => 'talk.newdomain.com', 'installedAt' => '2026-08-01T00:00:00+00:00'],
    ]);

    $callCount = 0;
    Process::fake([
        '*get secret larakube-tools-registry*' => function () use (&$callCount, $initialJson, $updatedJson) {
            $callCount++;

            return Process::result(output: base64_encode($callCount > 1 ? $updatedJson : $initialJson));
        },
        '*create secret generic larakube-tools-registry*' => Process::result(output: 'registry updated'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*get ingress*' => Process::result(output: ''),
        '*apply -f *' => Process::result(output: 'applied'),
        '*annotate ingress*' => Process::result(output: 'annotated'),
    ]);

    $this->artisan('tool:rebind', ['--tool' => 'matrix', '--to' => 'talk.newdomain.com', '--force' => true])
        ->assertExitCode(0);

    // This is exactly what SsoWireCommand::handle() calls (resolveInstanceForDomain,
    // a thin wrapper over this) against the NEW host before looking up its
    // Zitadel app secret — if this resolves to a DIFFERENT instance than
    // before the rebind, sso:wire's "reuse the existing project" lookup
    // misses and zitadelEnsureProject() silently mints a second project.
    $resolved = ToolRegistry::on('kubectl')->instanceForHost(ClusterTool::MATRIX, 'talk.newdomain.com');

    expect($resolved)->toBe('chat-luchtech-dev');
});

test('tool:rebind requires --force when running non-interactively', function (): void {
    $registryJson = json_encode([
        ['tool' => 'stalwart', 'instance' => 'send-luchtech-dev', 'host' => 'send.luchtech.dev', 'installedAt' => '2026-08-01T00:00:00+00:00'],
    ]);

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode($registryJson)),
    ]);

    $this->artisan('tool:rebind', ['--tool' => 'stalwart', '--to' => 'mail.newdomain.com']);
})->throws(App\Exceptions\MissingFlagException::class, 'Missing required --force');

test('tool:rebind --no-wire-sso skips sso:wire and prints manual reminder', function (): void {
    $registryJson = json_encode([
        ['tool' => 'matrix', 'instance' => 'chat-luchtech-dev', 'host' => 'chat.luchtech.dev', 'installedAt' => '2026-08-01T00:00:00+00:00'],
        ['tool' => 'zitadel', 'instance' => 'sso-luchtech-dev', 'host' => 'sso.luchtech.dev', 'installedAt' => '2026-08-01T00:00:00+00:00'],
    ]);

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode($registryJson)),
        '*create secret generic larakube-tools-registry*' => Process::result(output: 'registry updated'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*get ingress*' => Process::result(output: ''),
        '*apply -f *' => Process::result(output: 'applied'),
        '*annotate ingress*' => Process::result(output: 'annotated'),
    ]);

    $this->artisan('tool:rebind', [
        '--tool' => 'matrix',
        '--to' => 'talk.newdomain.com',
        '--no-wire-sso' => true,
        '--force' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('now serves https://talk.newdomain.com')
        ->expectsOutputToContain('Re-register its OIDC client under the SAME Zitadel project:');
});

test('tool:rebind --wire-sso triggers sso:wire', function (): void {
    $registryJson = json_encode([
        ['tool' => 'matrix', 'instance' => 'chat-luchtech-dev', 'host' => 'chat.luchtech.dev', 'installedAt' => '2026-08-01T00:00:00+00:00'],
        ['tool' => 'zitadel', 'instance' => 'sso-luchtech-dev', 'host' => 'sso.luchtech.dev', 'installedAt' => '2026-08-01T00:00:00+00:00'],
    ]);

    $callCount = 0;
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode($registryJson)),
        '*create secret generic larakube-tools-registry*' => Process::result(output: 'registry updated'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*get ingress*' => Process::result(output: ''),
        '*apply -f *' => Process::result(output: 'applied'),
        '*annotate ingress*' => Process::result(output: 'annotated'),
        '*get deployment*zitadel*' => Process::result(output: 'NAME\nzitadel'),
        '*get secret*machine-pat*' => Process::result(output: base64_encode('fake-pat')),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('tool:rebind', [
        '--tool' => 'matrix',
        '--to' => 'talk.newdomain.com',
        '--wire-sso' => true,
        '--force' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('now serves https://talk.newdomain.com');
});
