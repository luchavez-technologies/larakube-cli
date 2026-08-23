<?php

use App\Http\Integrations\Zitadel\Requests\CreateUserRequest;
use App\Http\Integrations\Zitadel\Requests\SearchOrganizationsRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

/** Common non-account-creation fakes every mail:create test needs. */
function mailCreateBaseFakes(): array
{
    return [
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*part-of=webmail*' => Process::result(output: '', exitCode: 1),
        '*get deployment sso-zitadel*' => Process::result(output: '', exitCode: 1),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
    ];
}

test('mail:create is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:create');
});

test('mail:create --domain= selects the given domain over the default first-configured one', function (): void {
    Process::fake(mailCreateBaseFakes() + ['*' => Process::result()]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Domain/query', ['ids' => ['d-luchtech', 'd-partner']], 'c0'], ['x:Domain/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Domain/get', ['list' => [['id' => 'd-luchtech', 'name' => 'luchtech.dev'], ['id' => 'd-partner', 'name' => 'partner.example']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/set', ['created' => ['new1' => ['id' => 'acc1']]], 'c1']], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:create', [
        '--email' => 'alice@example.com',
        '--domain' => 'partner.example',
        '--name' => 'Alice',
        '--password' => 'test-password-123',
        '--quota' => '1',
        '--no-sso' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('alice@partner.example');

    Saloon::assertSent(fn ($request) => ($request->body()->get('methodCalls')[0][0] ?? null) === 'x:Account/set'
        && $request->body()->get('methodCalls')[0][1]['create']['new1']['domainId'] === 'd-partner');
});

test('mail:create rejects an unknown --domain=', function (): void {
    Process::fake(mailCreateBaseFakes() + ['*' => Process::result()]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Domain/query', ['ids' => ['d-luchtech']], 'c0'], ['x:Domain/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Domain/get', ['list' => [['id' => 'd-luchtech', 'name' => 'luchtech.dev']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:create', ['--domain' => 'partner.example', '--no-sso' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain("Unknown domain 'partner.example'");
});

test('mail:create falls back to the first configured domain when non-interactive with no domain hint', function (): void {
    Process::fake(mailCreateBaseFakes() + ['*' => Process::result()]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Domain/query', ['ids' => ['d-luchtech', 'd-partner']], 'c0'], ['x:Domain/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Domain/get', ['list' => [['id' => 'd-luchtech', 'name' => 'luchtech.dev'], ['id' => 'd-partner', 'name' => 'partner.example']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/set', ['created' => ['new1' => ['id' => 'acc1']]], 'c1']], 'sessionState' => 'x']),
    ]);

    // No --domain, and the email's own domain (example.com) matches neither
    // configured domain — running under Pest, Prompt::interactive(false) is
    // already forced, so this exercises the same non-interactive fallback a
    // scripted/CI caller with no domain hint gets.
    $this->artisan('mail:create', [
        '--email' => 'bob@example.com',
        '--password' => 'test-password-123',
        '--quota' => '1',
        '--no-sso' => true,
    ])
        ->expectsQuestion('Display name', '')
        ->assertExitCode(0);

    Saloon::assertSent(fn ($request) => ($request->body()->get('methodCalls')[0][0] ?? null) === 'x:Account/set'
        && $request->body()->get('methodCalls')[0][1]['create']['new1']['domainId'] === 'd-luchtech');
});

test('mail:create requires installed stalwart', function (): void {
    Process::fake(['*app=mail-stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:create')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:create shows error when no domains configured', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Domain/query', ['ids' => []], 'c0'], ['x:Domain/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:create')
        ->assertExitCode(1)
        ->expectsOutputToContain('No domains are configured');
});

test('mail:create creates account with given args', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*get deployment sso-zitadel*' => Process::result(output: ''),
        '*part-of=webmail*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Domain/query', ['ids' => ['b']], 'c0'], ['x:Domain/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Domain/get', ['list' => [['id' => 'b', 'name' => 'example.com']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/set', ['created' => ['new1' => ['id' => 'd']]], 'c1']], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:create', [
        '--email' => 'bob@example.com',
        '--name' => 'Bob Smith',
        '--password' => 'Str0ngP@ssw0rd!',
        '--quota' => 5,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('bob@example.com')
        ->expectsOutputToContain('Str0ngP@ssw0rd!');
});

test('mail:create shows the webmail URL when Bulwark is installed', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*get deployment sso-zitadel*' => Process::result(output: ''),
        '*part-of=webmail*' => Process::result(output: 'webmail-bulwark   1/1   1   1   10d'),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Domain/query', ['ids' => ['b']], 'c0'], ['x:Domain/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Domain/get', ['list' => [['id' => 'b', 'name' => 'example.com']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/set', ['created' => ['new1' => ['id' => 'd']]], 'c1']], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:create', [
        '--email' => 'bob@example.com',
        '--name' => 'Bob Smith',
        '--password' => 'Str0ngP@ssw0rd!',
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('Or webmail:');
});

test('mail:create --sso creates a matching Zitadel identity', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Domain/query', ['ids' => ['b']], 'c0'], ['x:Domain/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Domain/get', ['list' => [['id' => 'b', 'name' => 'example.com']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/set', ['created' => ['new1' => ['id' => 'd']]], 'c1']], 'sessionState' => 'x']),
        SearchOrganizationsRequest::class => MockResponse::make(['result' => []]),
        CreateUserRequest::class => MockResponse::make(['userId' => 'zid-1']),
    ]);

    $this->artisan('mail:create', [
        '--email' => 'bob@example.com',
        '--name' => 'Bob Smith',
        '--password' => 'Str0ngP@ssw0rd!',
        '--sso' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('SSO identity created for bob@example.com');
});

test('mail:create --sso errors when Zitadel is not installed', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*get deployment sso-zitadel*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Domain/query', ['ids' => ['b']], 'c0'], ['x:Domain/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Domain/get', ['list' => [['id' => 'b', 'name' => 'example.com']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/set', ['created' => ['new1' => ['id' => 'd']]], 'c1']], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:create', [
        '--email' => 'bob@example.com',
        '--name' => 'Bob Smith',
        '--password' => 'Str0ngP@ssw0rd!',
        '--sso' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('--sso was requested, but Zitadel is not installed');
});

test('mail:create syncs to Zitadel BY DEFAULT when Zitadel is installed and no flag is given', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Domain/query', ['ids' => ['b']], 'c0'], ['x:Domain/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Domain/get', ['list' => [['id' => 'b', 'name' => 'example.com']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/set', ['created' => ['new1' => ['id' => 'd']]], 'c1']], 'sessionState' => 'x']),
        SearchOrganizationsRequest::class => MockResponse::make(['result' => []]),
        CreateUserRequest::class => MockResponse::make(['userId' => 'zid-1']),
    ]);

    // No --sso, no --no-sso: with Zitadel installed the sync is the default. The
    // non-interactive fallback must resolve to yes, so this needs no prompt.
    $this->artisan('mail:create', [
        '--email' => 'bob@example.com',
        '--name' => 'Bob Smith',
        '--password' => 'Str0ngP@ssw0rd!',
        '--no-interaction' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('SSO identity created for bob@example.com');

    // The Zitadel identity must be created with the SAME password as the mailbox,
    // so one credential logs into both mail and SSO.
    Saloon::assertSent(fn ($request) => $request instanceof CreateUserRequest
        && $request->body()->get('password')['password'] === 'Str0ngP@ssw0rd!');
});

test('mail:create --no-sso skips the Zitadel identity even when Zitadel is installed', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Domain/query', ['ids' => ['b']], 'c0'], ['x:Domain/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Domain/get', ['list' => [['id' => 'b', 'name' => 'example.com']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/set', ['created' => ['new1' => ['id' => 'd']]], 'c1']], 'sessionState' => 'x']),
    ]);

    // --no-sso wins over the default; the command must return before any Zitadel
    // call, so no Saloon fake for CreateUserRequest is needed — an attempted
    // call would fail the test.
    $this->artisan('mail:create', [
        '--email' => 'shared@example.com',
        '--name' => 'Shared Mailbox',
        '--password' => 'Str0ngP@ssw0rd!',
        '--no-sso' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('shared@example.com')
        ->doesntExpectOutputToContain('SSO identity created');
});
