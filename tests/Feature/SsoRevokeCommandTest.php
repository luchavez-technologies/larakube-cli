<?php

use App\Commands\Sso\SsoRevokeCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

test('sso:revoke is registered', function (): void {
    $this->artisan('list --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('sso:revoke');
});

test('sso:revoke rejects an explicit role no tool defines', function (): void {
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

test('sso:revoke declines to act without --force under non-interactive confirmation', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    Http::fake([
        '*/management/v1/projects/_search' => Http::response(['result' => [['id' => 'proj-1']]]),
        '*/v2/users' => Http::response(['result' => [['userId' => 'uid-1']]]),
    ]);

    $this->artisan('sso:revoke', ['--role' => 'openbao-admin', '--email' => 'james@luchtech.dev', '--no-interaction' => true])
        ->expectsConfirmation('Revoke [openbao-admin] from james@luchtech.dev?', 'no')
        ->assertExitCode(0)
        ->expectsOutputToContain('Cancelled');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/users/grants/_search'));
});

test('sso:revoke --role skips the discovery picker entirely, resolving the owning tool automatically', function (): void {
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

test('sso:revoke --role resolves a dynamic secrets:grant-issued per-app role key too, with no --tool needed', function (): void {
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

test('sso:revoke reports nothing to do when the user holds no role-gated access', function (): void {
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

test('sso:revoke\'s discovery sweep checks every RBAC-gated tool\'s OWN project, not two fixed ones', function (): void {
    // The actual point of the 2026-08-20 per-tool-project change, proven
    // precisely: unlike the other discovery tests in this file (which use
    // static/uniform fakes that happen to tolerate the sweep querying the
    // same fake data repeatedly), this gives each tool project a genuinely
    // DIFFERENT id and DIFFERENT grant, so the test fails outright if any
    // tool's project is silently skipped — the exact failure mode the old
    // fixed-2-project sweep couldn't have caught for a tool like Kutt.
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    $searchedNames = [];
    Http::fake([
        '*/v2/users' => Http::response(['result' => [['userId' => 'uid-1']]]),
        '*/management/v1/projects/_search' => function ($request) use (&$searchedNames) {
            $name = data_get($request, 'queries.0.nameQuery.name', '');
            $searchedNames[] = $name;

            return Http::response(['result' => [['id' => 'proj-'.md5($name)]]]);
        },
        '*/management/v1/users/grants/_search' => function ($request) {
            $projectId = data_get($request, 'queries.1.projectIdQuery.projectId', '');

            // Only Kutt's own project actually holds a grant for this user —
            // every other project (including the shared one) is empty. If
            // the sweep skipped Kutt's project, this role would never
            // surface at all.
            return Http::response(['result' => $projectId === 'proj-'.md5('link-kutt')
                ? [['id' => 'grant-kutt', 'roleKeys' => ['kutt-user']]]
                : []]);
        },
    ]);

    $this->artisan('sso:revoke', ['--email' => 'jamescarloluchavez@gmail.com', '--no-interaction' => true])
        ->expectsChoice(
            "jamescarloluchavez@gmail.com's current access — select what to revoke",
            [],
            ['kutt-user' => 'Link Management (Kutt) — Can log in to Kutt'],
        )
        ->assertExitCode(0);

    // Confirms the sweep actually reached every RBAC-gated tool's project,
    // not just the two the old code hardcoded.
    expect($searchedNames)->toContain('openbao-backend')
        ->and($searchedNames)->toContain('grafana')
        ->and($searchedNames)->toContain('dashboard-headlamp')
        ->and($searchedNames)->toContain('link-kutt')
        ->and($searchedNames)->toContain('notes-outline')
        ->and($searchedNames)->toContain('sign-documenso')
        ->and($searchedNames)->toContain('vaultwarden')
        ->and($searchedNames)->toContain('LaraKube Shared Tools');
});

test('sso:revoke\'s discovery picker defaults to an empty selection under non-interactive mode — no accidental full wipe', function (): void {
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
        ->expectsChoice(
            "james@luchtech.dev's current access — select what to revoke",
            [],
            [
                'openbao-admin' => 'Secrets Manager (OpenBao) — Full read/write on all secrets and Commons database credentials',
                'grafana-user' => 'Monitoring Stack (Grafana + Loki + Prometheus) — Can log in to Grafana (Viewer role)',
            ],
        )
        ->assertExitCode(0);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/users/uid-1/grants/grant-1')
        && in_array($request->method(), ['PUT', 'DELETE'], true));
});

test('sso:revoke --role=ocisAdmin pulls Drive\'s admin role on Drive\'s own project', function (): void {
    // Drive moved to rbacRoles() alongside ssoAdminRoles() 2026-08-20 (at the
    // user's explicit request) — requiresRbacGating() is now checked FIRST
    // in resolveSsoProject(), so this resolves via zitadelEnsureProject(the
    // 'drive-ocis' name), never the sso-app-drive secret's cached project-id.
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    Http::fake([
        '*/management/v1/projects/_search' => Http::response(['result' => [['id' => 'drive-proj-1']]]),
        '*/v2/users' => Http::response(['result' => [['userId' => 'uid-1']]]),
        '*/management/v1/users/grants/_search' => Http::sequence()
            ->push(['result' => [['id' => 'grant-1', 'roleKeys' => ['ocisAdmin']]]])
            ->push(['result' => []]),
        '*/management/v1/users/uid-1/grants/grant-1' => Http::response([]),
    ]);

    $this->artisan('sso:revoke', ['--role' => 'ocisAdmin', '--email' => 'admin@luchtech.dev', '--force' => true, '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Revoked [ocisAdmin] from admin@luchtech.dev')
        ->expectsOutputToContain('drive-ocis');

    // The ocisAdmin grant was the user's last one on Drive's project, so
    // the grant is deleted outright.
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/users/uid-1/grants/grant-1'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/management/v1/projects/_search')
        && $request['queries'][0]['nameQuery']['name'] === 'drive-ocis');
});

test("sso:revoke's discovery picker surfaces Drive's ocisAdmin on Drive's own project beside another tool's RBAC role", function (): void {
    // Drive moved to rbacRoles() alongside ssoAdminRoles() 2026-08-20 — the
    // sweep now walks every RBAC-gated tool's OWN project (a dozen of them,
    // not "the one RBAC project" vs "the one shared project" as before), so
    // this routes by EXACT project name: drive-ocis holds ocisAdmin,
    // openbao-backend holds openbao-admin, everything else (every other
    // role-bearing tool's project, plus the shared LaraKube Shared Tools
    // project) is empty. DRIVE is declared before SECRETS in ClusterTool's
    // case order, so ocisAdmin surfaces FIRST in the picker now, not second.
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    $driveGrantLookups = 0;
    Http::fake([
        '*/v2/users' => Http::response(['result' => [['userId' => 'uid-1']]]),
        '*/management/v1/projects/_search' => function ($request) {
            $name = data_get($request, 'queries.0.nameQuery.name', '');

            return Http::response(['result' => [['id' => "proj-{$name}"]]]);
        },
        '*/management/v1/users/grants/_search' => function ($request) use (&$driveGrantLookups) {
            $projectId = data_get($request, 'queries.1.projectIdQuery.projectId', '');

            if ($projectId === 'proj-drive-ocis') {
                $driveGrantLookups++;

                // Three lookups happen before this grant is truly gone:
                // (1) discovery's own sweep, (2) zitadelRevokeRole()'s
                // internal zitadelFindUserGrant() check (decides DELETE vs
                // PUT), (3) the post-revoke summary readback. Only the
                // third should see it gone.
                return Http::response(['result' => $driveGrantLookups >= 3
                    ? []
                    : [['id' => 'grant-1', 'roleKeys' => ['ocisAdmin']]]]);
            }

            if ($projectId === 'proj-openbao-backend') {
                return Http::response(['result' => [['id' => 'grant-2', 'roleKeys' => ['openbao-admin']]]]);
            }

            return Http::response(['result' => []]);
        },
        '*/management/v1/users/uid-1/grants/grant-1' => Http::response([]),
    ]);

    // ocisAdmin is now the FIRST option (Drive precedes Secrets in
    // ClusterTool's declaration order) — SPACE selects it, ENTER submits.
    // The command runs directly (not via artisan()) so Prompt::fake's mocked
    // terminal isn't clobbered by the Kernel's configurePrompts() fallbacks.
    Prompt::fake([Key::SPACE, Key::ENTER]);

    $command = app(SsoRevokeCommand::class);
    $input = new ArrayInput(['--email' => 'admin@luchtech.dev', '--force' => true]);
    $input->bind($command->getDefinition());
    $input->setInteractive(true);
    $output = new BufferedOutput;
    $command->setInput($input);
    $command->setOutput(new OutputStyle($input, $output));
    \Termwind\renderUsing($output);

    $exitCode = $command->handle();

    expect($exitCode)->toBe(0)
        ->and($output->fetch())->toContain('Revoked [ocisAdmin] from admin@luchtech.dev')
        ->toContain('now holds no roles on drive-ocis');

    // Both tools' roles were offered in ONE picker — the old single-project
    // discovery never even saw ocisAdmin, which is the bug this locks in.
    Prompt::assertStrippedOutputContains('Cloud Storage & Sync (oCIS) — oCIS administrator');
    Prompt::assertStrippedOutputContains('Secrets Manager (OpenBao)');

    // ocisAdmin was the user's last role on Drive's project → the grant is
    // deleted outright, and the revoke never touched Secrets' grant.
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/users/uid-1/grants/grant-1'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/users/uid-1/grants/grant-2'));
});
