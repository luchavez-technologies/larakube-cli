<?php

use App\Commands\Mail\MailPasswordCommand;
use App\Http\Integrations\Zitadel\Requests\SearchUsersRequest;
use App\Http\Integrations\Zitadel\Requests\SetUserPasswordRequest;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('mail:password is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:password');
});

test('mail:password requires installed stalwart', function (): void {
    Process::fake(['*app=mail-stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:password')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:password resets password', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*get deployment sso-zitadel*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Account/query', ['ids' => ['c']], 'c0'], ['x:Account/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/get', ['list' => [['id' => 'c', 'name' => 'alice', 'description' => 'Alice', 'emailAddress' => 'alice@example.com']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/set', ['updated' => ['c' => null]], 'c1']], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:password', ['--email' => 'alice@example.com', '--password' => 'NewStr0ngP@ss!', '--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('alice@example.com')
        ->expectsOutputToContain('NewStr0ngP@ss!');
});

test('mail:password syncs the SSO password BY DEFAULT when Zitadel is installed and the identity exists', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Account/query', ['ids' => ['c']], 'c0'], ['x:Account/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/get', ['list' => [['id' => 'c', 'name' => 'alice', 'description' => 'Alice', 'emailAddress' => 'alice@example.com']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/set', ['updated' => ['c' => null]], 'c1']], 'sessionState' => 'x']),
        SetUserPasswordRequest::class => MockResponse::make([], 200),
        SearchUsersRequest::class => MockResponse::make(['result' => [['userId' => 'zid-1']]]),
    ]);

    $this->artisan('mail:password', ['--email' => 'alice@example.com', '--password' => 'NewStr0ngP@ss!', '--force' => true, '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('SSO password updated for alice@example.com');

    // The SSO password must be set to the SAME new value as the mailbox.
    Saloon::assertSent(fn ($request) => $request instanceof SetUserPasswordRequest
        && $request->resolveEndpoint() === 'v2/users/zid-1/password'
        && $request->body()->get('newPassword')['password'] === 'NewStr0ngP@ss!');
});

test('mail:password --no-sso leaves Zitadel untouched', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Account/query', ['ids' => ['c']], 'c0'], ['x:Account/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/get', ['list' => [['id' => 'c', 'name' => 'alice', 'description' => 'Alice', 'emailAddress' => 'alice@example.com']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/set', ['updated' => ['c' => null]], 'c1']], 'sessionState' => 'x']),
    ]);

    // --no-sso returns before any Zitadel call — no Http::fake, so an attempted
    // call would fail the test.
    $this->artisan('mail:password', ['--email' => 'alice@example.com', '--password' => 'NewStr0ngP@ss!', '--force' => true, '--no-sso' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('NewStr0ngP@ss!')
        ->doesntExpectOutputToContain('SSO password updated');
});

test('mail:password hints (does not error) when no matching SSO identity exists', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    // Zitadel is up, but the email has no identity (empty search result).
    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Account/query', ['ids' => ['c']], 'c0'], ['x:Account/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/get', ['list' => [['id' => 'c', 'name' => 'alice', 'description' => 'Alice', 'emailAddress' => 'alice@example.com']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/set', ['updated' => ['c' => null]], 'c1']], 'sessionState' => 'x']),
        SearchUsersRequest::class => MockResponse::make(['result' => []]),
    ]);

    $this->artisan('mail:password', ['--email' => 'alice@example.com', '--password' => 'NewStr0ngP@ss!', '--force' => true, '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('No matching SSO identity for alice@example.com');
});

test('mail:password without --force asks for confirmation and cancels on decline', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Account/query', ['ids' => ['c']], 'c0'], ['x:Account/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/get', ['list' => [['id' => 'c', 'name' => 'alice', 'description' => 'Alice', 'emailAddress' => 'alice@example.com']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
    ]);

    Prompt::fake([Key::ENTER]); // accept confirm()'s default, which is No

    $command = app(MailPasswordCommand::class);
    $input = new ArrayInput(['--email' => 'alice@example.com', '--password' => 'Whatever!123']);
    $input->bind($command->getDefinition());
    $input->setInteractive(true);
    $output = new BufferedOutput;
    $command->setInput($input);
    $command->setOutput(new OutputStyle($input, $output));

    $exitCode = $command->handle();

    expect($exitCode)->toBe(0)
        ->and($output->fetch())->toContain('invalidates');
    // Declining the confirmation must stop before the actual update call —
    // only the account-lookup JMAP calls (query + get) should have fired.
    Saloon::assertSentCount(2);
});
