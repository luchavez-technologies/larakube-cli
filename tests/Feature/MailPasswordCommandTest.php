<?php

use App\Commands\Mail\MailPasswordCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

test('mail:password is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:password');
});

test('mail:password requires installed stalwart', function (): void {
    Process::fake(['*get deployment stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:password')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:password resets password', function (): void {
    $callCount = 0;
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*get deployment sso-zitadel*' => Process::result(output: ''),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":["c"]},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }
            if ($callCount === 2) {
                return Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"c","name":"alice","description":"Alice","emailAddress":"alice@example.com"}],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/set",{"updated":{"c":null}},"c1"]],"sessionState":"x"}');
        },
    ]);

    $this->artisan('mail:password', ['--email' => 'alice@example.com', '--password' => 'NewStr0ngP@ss!', '--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('alice@example.com')
        ->expectsOutputToContain('NewStr0ngP@ss!');
});

test('mail:password syncs the SSO password BY DEFAULT when Zitadel is installed and the identity exists', function (): void {
    $callCount = 0;
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":["c"]},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }
            if ($callCount === 2) {
                return Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"c","name":"alice","description":"Alice","emailAddress":"alice@example.com"}],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/set",{"updated":{"c":null}},"c1"]],"sessionState":"x"}');
        },
    ]);

    Http::fake([
        '*/v2/users/*/password' => Http::response([], 200),
        '*/v2/users' => Http::response(['result' => [['userId' => 'zid-1']]]),
    ]);

    $this->artisan('mail:password', ['--email' => 'alice@example.com', '--password' => 'NewStr0ngP@ss!', '--force' => true, '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('SSO password updated for alice@example.com');

    // The SSO password must be set to the SAME new value as the mailbox.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/users/zid-1/password')
        && ($request['newPassword']['password'] ?? null) === 'NewStr0ngP@ss!');
});

test('mail:password --no-sso leaves Zitadel untouched', function (): void {
    $callCount = 0;
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":["c"]},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }
            if ($callCount === 2) {
                return Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"c","name":"alice","description":"Alice","emailAddress":"alice@example.com"}],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/set",{"updated":{"c":null}},"c1"]],"sessionState":"x"}');
        },
    ]);

    // --no-sso returns before any Zitadel call — no Http::fake, so an attempted
    // call would fail the test.
    $this->artisan('mail:password', ['--email' => 'alice@example.com', '--password' => 'NewStr0ngP@ss!', '--force' => true, '--no-sso' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('NewStr0ngP@ss!')
        ->doesntExpectOutputToContain('SSO password updated');
});

test('mail:password hints (does not error) when no matching SSO identity exists', function (): void {
    $callCount = 0;
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":["c"]},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }
            if ($callCount === 2) {
                return Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"c","name":"alice","description":"Alice","emailAddress":"alice@example.com"}],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/set",{"updated":{"c":null}},"c1"]],"sessionState":"x"}');
        },
    ]);

    // Zitadel is up, but the email has no identity (empty search result).
    Http::fake(['*/v2/users' => Http::response(['result' => []])]);

    $this->artisan('mail:password', ['--email' => 'alice@example.com', '--password' => 'NewStr0ngP@ss!', '--force' => true, '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('No matching SSO identity for alice@example.com');
});

test('mail:password without --force asks for confirmation and cancels on decline', function (): void {
    $callCount = 0;
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":["c"]},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"c","name":"alice","description":"Alice","emailAddress":"alice@example.com"}],"notFound":[]},"c1"]],"sessionState":"x"}');
        },
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
    expect($callCount)->toBe(2);
});
