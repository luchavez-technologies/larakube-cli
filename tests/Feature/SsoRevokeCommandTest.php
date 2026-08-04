<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

test('sso:revoke is registered', function () {
    $this->artisan('list --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('sso:revoke');
});

test('sso:revoke rejects an explicit role no tool defines', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    Http::fake([
        '*/management/v1/projects/_search' => Http::response(['result' => [['id' => 'proj-1']]]),
        '*/v2/users' => Http::response(['result' => [['userId' => 'uid-1']]]),
    ]);

    $this->artisan('sso:revoke', ['--role' => 'not-a-real-role', '--email' => 'james@luchtech.dev', '--force' => true, '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain("isn't a role any wired tool defines");
});

test('sso:revoke declines to act without --force under non-interactive confirmation', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    Http::fake([
        '*/management/v1/projects/_search' => Http::response(['result' => [['id' => 'proj-1']]]),
        '*/v2/users' => Http::response(['result' => [['userId' => 'uid-1']]]),
    ]);

    $this->artisan('sso:revoke', ['--role' => 'openbao-admin', '--email' => 'james@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Cancelled');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/users/grants/_search'));
});

test('sso:revoke --role skips the discovery picker entirely, resolving the owning tool automatically', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    Http::fake([
        '*/v2/users' => Http::response(['result' => [['userId' => 'uid-1']]]),
        '*/management/v1/projects/_search' => Http::response(['result' => [['id' => 'proj-1']]]),
        '*/management/v1/users/grants/_search' => Http::sequence()
            ->push(['result' => [['id' => 'grant-1', 'roleKeys' => ['openbao-admin', 'openbao-operator']]]])
            ->push(['result' => [['id' => 'grant-1', 'roleKeys' => ['openbao-admin']]]]),
        '*/management/v1/users/uid-1/grants/grant-1' => Http::response([]),
    ]);

    // No --tool at all — the owning tool is derived from the role key itself.
    $this->artisan('sso:revoke', ['--role' => 'openbao-operator', '--email' => 'james@luchtech.dev', '--force' => true, '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Revoked [openbao-operator] from james@luchtech.dev')
        ->expectsOutputToContain('openbao-admin');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/roles/_search'));
});

test('sso:revoke --role resolves a dynamic secrets:grant-issued per-app role key too, with no --tool needed', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    Http::fake([
        '*/v2/users' => Http::response(['result' => [['userId' => 'uid-1']]]),
        '*/management/v1/projects/_search' => Http::response(['result' => [['id' => 'proj-1']]]),
        '*/management/v1/users/grants/_search' => Http::sequence()
            ->push(['result' => [['id' => 'grant-1', 'roleKeys' => ['secrets-my-app-local-developer']]]])
            ->push(['result' => [['id' => 'grant-1', 'roleKeys' => []]]]),
        '*/management/v1/users/uid-1/grants/grant-1' => Http::response([]),
    ]);

    // secrets:grant mints role keys like "secrets-my-app-local-developer"
    // dynamically — sso:revoke must still resolve which project it lives on
    // (the RBAC project, same as the fixed openbao-* tiers) from the key
    // alone, closing the incident-sweep blind spot a separate revoke system
    // would otherwise create.
    $this->artisan('sso:revoke', ['--role' => 'secrets-my-app-local-developer', '--email' => 'james@luchtech.dev', '--force' => true, '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Revoked [secrets-my-app-local-developer] from james@luchtech.dev');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/roles/_search'));
});

test('sso:revoke reports nothing to do when the user holds no role-gated access', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    Http::fake([
        '*/v2/users' => Http::response(['result' => [['userId' => 'uid-1']]]),
        '*/management/v1/projects/_search' => Http::response(['result' => [['id' => 'proj-1']]]),
        '*/management/v1/users/grants/_search' => Http::response(['result' => []]),
    ]);

    // No --role given → discovery path, but there's nothing to discover.
    $this->artisan('sso:revoke', ['--email' => 'james@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('holds no role-gated access');
});

test('sso:revoke\'s discovery picker defaults to an empty selection under non-interactive mode — no accidental full wipe', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    Http::fake([
        '*/v2/users' => Http::response(['result' => [['userId' => 'uid-1']]]),
        '*/management/v1/projects/_search' => Http::response(['result' => [['id' => 'proj-1']]]),
        '*/management/v1/users/grants/_search' => Http::response(['result' => [
            ['id' => 'grant-1', 'roleKeys' => ['openbao-admin', 'grafana-user']],
        ]]),
    ]);

    // --force bypasses the SEPARATE confirm() gate deliberately, so this
    // isolates multiselect's own default — without --force, confirm()
    // alone would already block a wipe regardless of what multiselect
    // defaulted to, which would leave this test unable to tell the two
    // safety nets apart (confirmed by mutation-testing this exact case).
    $this->artisan('sso:revoke', ['--email' => 'james@luchtech.dev', '--force' => true, '--no-interaction' => true])
        ->assertExitCode(0);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/users/uid-1/grants/grant-1')
        && in_array($request->method(), ['PUT', 'DELETE'], true));
});

test('sso:revoke --role=ocisAdmin pulls Drive\'s admin role on the shared project', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-drive*' => Process::result(output: base64_encode('shared-proj-1')),
    ]);

    Http::fake([
        '*/v2/users' => Http::response(['result' => [['userId' => 'uid-1']]]),
        '*/management/v1/users/grants/_search' => Http::sequence()
            ->push(['result' => [['id' => 'grant-1', 'roleKeys' => ['ocisAdmin']]]])
            ->push(['result' => []]),
        '*/management/v1/users/uid-1/grants/grant-1' => Http::response([]),
    ]);

    $this->artisan('sso:revoke', ['--role' => 'ocisAdmin', '--email' => 'admin@luchtech.dev', '--force' => true, '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Revoked [ocisAdmin] from admin@luchtech.dev')
        ->expectsOutputToContain('LaraKube Shared Tools');

    // The ocisAdmin grant was the user's last one on the shared project, so
    // the grant is deleted outright on the drive app's own project.
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/users/uid-1/grants/grant-1'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/management/v1/projects/_search'));
});

test("sso:revoke's discovery picker surfaces Drive's ocisAdmin on the shared project beside RBAC roles — and revokes it against its own project", function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    // The discovery path consults BOTH role-bearing projects: the RBAC one
    // (role-gated roles) and the shared one (drive's ocisAdmin). Count the
    // shared-project grant lookups so the post-revoke summary reflects the
    // deletion instead of echoing the pre-revoke grant forever.
    $sharedGrantLookups = 0;
    Http::fake([
        '*/v2/users' => Http::response(['result' => [['userId' => 'uid-1']]]),
        '*/management/v1/projects/_search' => function ($request) {
            $name = data_get($request, 'queries.0.nameQuery.name', '');

            return Http::response(['result' => [['id' => $name === 'LaraKube Shared Tools' ? 'proj-shared' : 'proj-rbac']]]);
        },
        '*/management/v1/users/grants/_search' => function ($request) use (&$sharedGrantLookups) {
            $projectId = data_get($request, 'queries.1.projectIdQuery.projectId', '');
            if ($projectId === 'proj-shared') {
                $sharedGrantLookups++;

                return Http::response(['result' => $sharedGrantLookups >= 3
                    ? []
                    : [['id' => 'grant-1', 'roleKeys' => ['ocisAdmin']]]]);
            }

            return Http::response(['result' => [['id' => 'grant-2', 'roleKeys' => ['openbao-admin']]]]);
        },
        '*/management/v1/users/uid-1/grants/grant-1' => Http::response([]),
    ]);

    // The picker iterates the RBAC project first, so ocisAdmin is the SECOND
    // option (openbao-admin is first) — DOWN then SPACE selects it, ENTER submits.
    // The command runs directly (not via artisan()) so Prompt::fake's mocked
    // terminal isn't clobbered by the Kernel's configurePrompts() fallbacks.
    Prompt::fake([Key::DOWN, Key::SPACE, Key::ENTER]);

    $command = app(App\Commands\Sso\SsoRevokeCommand::class);
    $input = new Symfony\Component\Console\Input\ArrayInput(['--email' => 'admin@luchtech.dev', '--force' => true]);
    $input->bind($command->getDefinition());
    $input->setInteractive(true);
    $output = new Symfony\Component\Console\Output\BufferedOutput;
    $command->setInput($input);
    $command->setOutput(new Illuminate\Console\OutputStyle($input, $output));
    \Termwind\renderUsing($output);

    $exitCode = $command->handle();

    expect($exitCode)->toBe(0);
    expect($output->fetch())
        ->toContain('Revoked [ocisAdmin] from admin@luchtech.dev')
        ->toContain('now holds no roles on LaraKube Shared Tools');

    // Both projects' roles were offered in ONE picker — the old single-project
    // discovery never even saw ocisAdmin, which is the bug this locks in.
    Prompt::assertStrippedOutputContains('Cloud Storage & Sync (oCIS) — oCIS administrator');
    Prompt::assertStrippedOutputContains('Secrets Manager (OpenBao)');

    // ocisAdmin was the user's last role on the shared project → the grant is
    // deleted outright, and the revoke never touched the RBAC project's grant.
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/users/uid-1/grants/grant-1'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/users/uid-1/grants/grant-2'));
});
