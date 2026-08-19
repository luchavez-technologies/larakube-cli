<?php

use App\Commands\Mail\MailPasswordCommand;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use Illuminate\Console\OutputStyle;
use Illuminate\Process\FakeProcessSequence;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

test('mail:accounts is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:accounts');
});

test('mail:accounts shows error when stalwart not installed', function (): void {
    Process::fake(['*get deployment stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:accounts')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:accounts shows empty when no accounts exist', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":[],"queryState":"n"},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
    ]);

    $this->artisan('mail:accounts')
        ->assertExitCode(0)
        ->expectsOutputToContain('No accounts found');
});

test('mail:accounts lists accounts', function (): void {
    $callCount = 0;
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":["b","c"]},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"b","name":"admin","description":"System administrator","emailAddress":"admin@example.com","roles":{"@type":"Admin"},"quotas":{},"usedDiskQuota":486},{"id":"c","name":"alice","description":"Alice Smith","emailAddress":"alice@example.com","roles":{"@type":"User"},"quotas":{"maxDiskQuota":1073741824},"usedDiskQuota":1048576}],"notFound":[]},"c1"]],"sessionState":"x"}');
        },
    ]);

    $exitCode = Artisan::call('mail:accounts');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('admin@example.com')
        ->toContain('alice@example.com');
});

test('mail:create is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:create');
});

test('mail:create shows error when no domains configured', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => Process::result(output: '{"methodResponses":[["x:Domain/query",{"ids":[]},"c0"],["x:Domain/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
    ]);

    $this->artisan('mail:create')
        ->assertExitCode(1)
        ->expectsOutputToContain('No domains are configured');
});

test('mail:create creates account with given args', function (): void {
    $callCount = 0;
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*get deployment sso-zitadel*' => Process::result(output: ''),
        '*get deployment webmail-bulwark*' => Process::result(output: ''),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Domain/query",{"ids":["b"]},"c0"],["x:Domain/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }
            if ($callCount === 2) {
                return Process::result(output: '{"methodResponses":[["x:Domain/get",{"list":[{"id":"b","name":"example.com"}],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/set",{"created":{"new1":{"id":"d"}}},"c1"]],"sessionState":"x"}');
        },
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

test('mail:delete is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:delete');
});

test('mail:delete deletes account by email', function (): void {
    $callCount = 0;
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*get deployment sso-zitadel*' => Process::result(output: ''),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":["b"]},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }
            if ($callCount === 2) {
                return Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"b","name":"admin","description":"Admin","emailAddress":"admin@example.com"}],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/set",{"destroyed":["b"]},"c1"]],"sessionState":"x"}');
        },
    ]);

    $exitCode = Artisan::call('mail:delete', ['--email' => 'admin@example.com', '--force' => true]);

    expect($exitCode)->toBe(0);
});

test('mail:password is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:password');
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

test('mail:quota is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:quota');
});

test('mail:quota sets quota', function (): void {
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
            if ($callCount === 2) {
                return Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"c","name":"alice","description":"Alice","emailAddress":"alice@example.com","quotas":{"maxDiskQuota":1073741824}}],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/set",{"updated":{"c":null}},"c1"]],"sessionState":"x"}');
        },
    ]);

    $exitCode = Artisan::call('mail:quota', ['--email' => 'alice@example.com', '--quota' => '10']);

    expect($exitCode)->toBe(0);
});

test('mail:show is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:show');
});

test('mail:show displays admin credentials', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('s3cret-p@ss')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
    ]);

    $this->artisan('mail:show')
        ->assertExitCode(0)
        ->expectsOutputToContain('admin')
        ->expectsOutputToContain('s3cret-p@ss');
});

test('mail:show <email> displays that account\'s client setup, never a password', function (): void {
    $callCount = 0;
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*get deployment sso-zitadel*' => Process::result(output: ''),
        '*get deployment webmail-bulwark*' => Process::result(output: ''),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":["c"]},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"c","name":"alice","description":"Alice Smith","emailAddress":"alice@example.com","roles":{"@type":"User"}}],"notFound":[]},"c1"]],"sessionState":"x"}');
        },
    ]);

    $this->artisan('mail:show', ['--email' => 'alice@example.com'])
        ->assertExitCode(0)
        ->expectsOutputToContain('alice@example.com')
        ->expectsOutputToContain('Alice Smith')
        ->expectsOutputToContain('Issue a new one')
        ->doesntExpectOutputToContain('test-admin-pass');
});

test('mail:create shows the webmail URL when Bulwark is installed', function (): void {
    $callCount = 0;
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*get deployment sso-zitadel*' => Process::result(output: ''),
        '*get deployment webmail-bulwark*' => Process::result(output: 'webmail-bulwark   1/1   1   1   10d'),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Domain/query",{"ids":["b"]},"c0"],["x:Domain/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }
            if ($callCount === 2) {
                return Process::result(output: '{"methodResponses":[["x:Domain/get",{"list":[{"id":"b","name":"example.com"}],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/set",{"created":{"new1":{"id":"d"}}},"c1"]],"sessionState":"x"}');
        },
    ]);

    $this->artisan('mail:create', [
        '--email' => 'bob@example.com',
        '--name' => 'Bob Smith',
        '--password' => 'Str0ngP@ssw0rd!',
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('Or webmail:');
});

test('mail:show <email> errors when the account does not exist', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":[]},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
    ]);

    $this->artisan('mail:show', ['--email' => 'ghost@example.com'])
        ->assertExitCode(1)
        ->expectsOutputToContain("Account 'ghost@example.com' not found");
});

test('mail:domains is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:domains');
});

test('mail:domains shows empty when no domains exist', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => Process::result(output: '{"methodResponses":[["x:Domain/query",{"ids":[]},"c0"],["x:Domain/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
    ]);

    $this->artisan('mail:domains')
        ->assertExitCode(0)
        ->expectsOutputToContain('No domains configured');
});

test('mail:show requires installed stalwart', function (): void {
    Process::fake(['*get deployment stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:show')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:domains requires installed stalwart', function (): void {
    Process::fake(['*get deployment stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:domains')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:create requires installed stalwart', function (): void {
    Process::fake(['*get deployment stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:create')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:delete requires installed stalwart', function (): void {
    Process::fake(['*get deployment stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:delete')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:password requires installed stalwart', function (): void {
    Process::fake(['*get deployment stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:password')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:quota requires installed stalwart', function (): void {
    Process::fake(['*get deployment stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:quota')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:create --sso creates a matching Zitadel identity', function (): void {
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
                return Process::result(output: '{"methodResponses":[["x:Domain/query",{"ids":["b"]},"c0"],["x:Domain/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }
            if ($callCount === 2) {
                return Process::result(output: '{"methodResponses":[["x:Domain/get",{"list":[{"id":"b","name":"example.com"}],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/set",{"created":{"new1":{"id":"d"}}},"c1"]],"sessionState":"x"}');
        },
    ]);

    Http::fake(['*/v2/organizations/_search' => Http::response(['result' => []]), '*/v2/users/human' => Http::response(['userId' => 'zid-1'])]);

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
    $callCount = 0;
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*get deployment sso-zitadel*' => Process::result(output: ''),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Domain/query",{"ids":["b"]},"c0"],["x:Domain/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }
            if ($callCount === 2) {
                return Process::result(output: '{"methodResponses":[["x:Domain/get",{"list":[{"id":"b","name":"example.com"}],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/set",{"created":{"new1":{"id":"d"}}},"c1"]],"sessionState":"x"}');
        },
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
                return Process::result(output: '{"methodResponses":[["x:Domain/query",{"ids":["b"]},"c0"],["x:Domain/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }
            if ($callCount === 2) {
                return Process::result(output: '{"methodResponses":[["x:Domain/get",{"list":[{"id":"b","name":"example.com"}],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/set",{"created":{"new1":{"id":"d"}}},"c1"]],"sessionState":"x"}');
        },
    ]);

    Http::fake(['*/v2/organizations/_search' => Http::response(['result' => []]), '*/v2/users/human' => Http::response(['userId' => 'zid-1'])]);

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
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/users/human')
        && ($request['password']['password'] ?? null) === 'Str0ngP@ssw0rd!');
});

test('mail:create --no-sso skips the Zitadel identity even when Zitadel is installed', function (): void {
    $callCount = 0;
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Domain/query",{"ids":["b"]},"c0"],["x:Domain/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }
            if ($callCount === 2) {
                return Process::result(output: '{"methodResponses":[["x:Domain/get",{"list":[{"id":"b","name":"example.com"}],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/set",{"created":{"new1":{"id":"d"}}},"c1"]],"sessionState":"x"}');
        },
    ]);

    // --no-sso wins over the default; the command must return before any Zitadel
    // call, so no Http::fake is needed — an attempted call would fail the test.
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

test('mail:delete --sso removes the matching Zitadel identity', function (): void {
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
                return Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":["b"]},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }
            if ($callCount === 2) {
                return Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"b","name":"admin","description":"Admin","emailAddress":"admin@example.com"}],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/set",{"destroyed":["b"]},"c1"]],"sessionState":"x"}');
        },
    ]);

    Http::fake([
        '*/v2/users/*' => Http::response(['details' => []]),
        '*/v2/users' => Http::response(['result' => [['userId' => 'zid-1']]]),
    ]);

    $this->artisan('mail:delete', ['--email' => 'admin@example.com', '--force' => true, '--sso' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('SSO identity for admin@example.com removed');
});

test('mail:show <email> shows the webmail URL when Bulwark is installed', function (): void {
    $callCount = 0;
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*get deployment sso-zitadel*' => Process::result(output: ''),
        '*get deployment webmail-bulwark*' => Process::result(output: 'webmail-bulwark   1/1   1   1   10d'),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":["c"]},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"c","name":"alice","description":"Alice Smith","emailAddress":"alice@example.com","roles":{"@type":"User"}}],"notFound":[]},"c1"]],"sessionState":"x"}');
        },
    ]);

    $this->artisan('mail:show', ['--email' => 'alice@example.com'])
        ->assertExitCode(0)
        ->expectsOutputToContain('Webmail:');
});

test('mail:show <email> shows SSO status when Zitadel is installed', function (): void {
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

            return Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"c","name":"alice","description":"Alice Smith","emailAddress":"alice@example.com","roles":{"@type":"User"}}],"notFound":[]},"c1"]],"sessionState":"x"}');
        },
    ]);

    Http::fake(['*/v2/users' => Http::response(['result' => [['userId' => 'zid-1']]])]);

    $this->artisan('mail:show', ['--email' => 'alice@example.com'])
        ->assertExitCode(0)
        ->expectsOutputToContain('SSO:');
});

test('mail:init explains why it skipped Commons store auto-config instead of staying silent', function (): void {
    // No plex-commons ConfigMap on the cluster: a legitimate skip, but it used
    // to print nothing at all, which is indistinguishable from a broken run.
    Process::fake([
        '*get configmap plex-commons*' => Process::result(output: '', exitCode: 1),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('pw')),
        '*rollout*' => Process::result(output: 'rolled out'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('mail:init local --domain=example.com --no-interaction --force')
        ->expectsOutputToContain('no Plex Commons')
        ->expectsOutputToContain('plex:init');
});

test('the store hint never mixes the postgres superuser with STALWART_STORE_PASSWORD', function (): void {
    // Two mutually exclusive credential paths used to be printed together:
    // username `postgres` (superuser) alongside "use STALWART_STORE_PASSWORD"
    // (the dedicated `stalwart` role's password). Pairing them fails auth.
    $source = (string) file_get_contents(base_path('app/Traits/InteractsWithPlex.php'));

    // The superuser username and the env-var advice must sit in opposite
    // branches of the same conditional, never in one straight-line block.
    expect($source)->toContain('$openBaoBootstrapped')
        ->and(substr_count($source, 'STALWART_STORE_PASSWORD'))->toBeGreaterThan(0);

    $hintSection = substr($source, (int) strpos($source, '7. Configure stores'));
    $hintSection = substr($hintSection, 0, (int) strpos($hintSection, 'mail:restart'));

    // Both credentials still appear (one per branch), but the block must carry
    // the explicit warning against combining them.
    expect($hintSection)->toContain('Do NOT use the postgres superuser here')
        ->and($hintSection)->toContain('STALWART_STORE_PASSWORD')
        ->and($hintSection)->toContain('Username: <fg=blue>postgres');
});

test('the store hint warns that switching stores empties the directory', function (): void {
    // Accounts, domains and DKIM keys live in Stalwart's data store and are not
    // migrated — an operator who switches mid-flight silently loses them.
    $source = (string) file_get_contents(base_path('app/Traits/InteractsWithPlex.php'));

    expect($source)->toContain('EMPTY directory')
        ->and($source)->toContain('are NOT migrated');
});

/**
 * Fake JMAP transport for mail:dkim. Reading signatures costs four in-pod curls
 * in this order: DkimSignature/query, DkimSignature/get, then Domain/query+get
 * (one request) and a second Domain/get to resolve names for the ids returned.
 *
 * @param  list<array{id: string, type: string, stage: string}>  $signatures
 */
function dkimJmapSequence(array $signatures): FakeProcessSequence
{
    $ids = array_map(fn (array $s) => $s['id'], $signatures);

    return Process::sequence()
        ->push(Process::result(output: json_encode([
            'methodResponses' => [['x:DkimSignature/query', ['ids' => $ids], 'c0']],
        ])))
        ->push(Process::result(output: json_encode([
            'methodResponses' => [['x:DkimSignature/get', ['list' => array_map(fn (array $s) => [
                'id' => $s['id'],
                'domainId' => 'dom-1',
                'selector' => 'v1-'.$s['id'],
                '@type' => $s['type'],
                'stage' => $s['stage'],
            ], $signatures)], 'c1']],
        ])))
        ->push(Process::result(output: json_encode([
            'methodResponses' => [
                ['x:Domain/query', ['ids' => ['dom-1']], 'c0'],
                ['x:Domain/get', ['list' => []], 'c1'],
            ],
        ])))
        ->push(Process::result(output: json_encode([
            'methodResponses' => [['x:Domain/get', ['list' => [['id' => 'dom-1', 'name' => 'luchtech.dev']]], 'c1']],
        ])));
}

test('mail:dkim is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:dkim');
});

test('mail:dkim requires installed stalwart', function (): void {
    Process::fake(['*get deployment stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:dkim')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:dkim fails and points at --fix when a domain has two active keys', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*exec *' => dkimJmapSequence([
            ['id' => 'sig-rsa', 'type' => 'Dkim1RsaSha256', 'stage' => 'active'],
            ['id' => 'sig-ed', 'type' => 'Dkim1Ed25519Sha256', 'stage' => 'active'],
        ]),
    ]);

    $this->artisan('mail:dkim')
        ->assertExitCode(1)
        ->expectsOutputToContain('luchtech.dev has 2 active signing keys')
        ->expectsOutputToContain('--fix');
});

test('mail:dkim passes when a single active key signs the domain', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*exec *' => dkimJmapSequence([
            ['id' => 'sig-rsa', 'type' => 'Dkim1RsaSha256', 'stage' => 'active'],
        ]),
    ]);

    $this->artisan('mail:dkim')
        ->assertExitCode(0)
        ->expectsOutputToContain('single active key');
});

test('mail:dkim --fix destroys the Ed25519 key and reports the count', function (): void {
    // --fix prunes first, then re-reads, so the destroy response is spliced in
    // ahead of a second full read that now shows RSA only.
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*exec *' => Process::sequence()
            // Read for the prune: one RSA + one Ed25519.
            ->push(Process::result(output: json_encode([
                'methodResponses' => [['x:DkimSignature/query', ['ids' => ['sig-rsa', 'sig-ed']], 'c0']],
            ])))
            ->push(Process::result(output: json_encode([
                'methodResponses' => [['x:DkimSignature/get', ['list' => [
                    ['id' => 'sig-rsa', 'domainId' => 'dom-1', 'selector' => 'v1-rsa', '@type' => 'Dkim1RsaSha256', 'stage' => 'active'],
                    ['id' => 'sig-ed', 'domainId' => 'dom-1', 'selector' => 'v1-ed', '@type' => 'Dkim1Ed25519Sha256', 'stage' => 'active'],
                ]], 'c1']],
            ])))
            ->push(Process::result(output: json_encode([
                'methodResponses' => [['x:Domain/query', ['ids' => ['dom-1']], 'c0'], ['x:Domain/get', ['list' => []], 'c1']],
            ])))
            ->push(Process::result(output: json_encode([
                'methodResponses' => [['x:Domain/get', ['list' => [['id' => 'dom-1', 'name' => 'luchtech.dev']]], 'c1']],
            ])))
            // The destroy itself.
            ->push(Process::result(output: json_encode([
                'methodResponses' => [['x:DkimSignature/set', ['destroyed' => ['sig-ed']], 'c2']],
            ])))
            // Re-read for the table: RSA only now.
            ->push(Process::result(output: json_encode([
                'methodResponses' => [['x:DkimSignature/query', ['ids' => ['sig-rsa']], 'c0']],
            ])))
            ->push(Process::result(output: json_encode([
                'methodResponses' => [['x:DkimSignature/get', ['list' => [
                    ['id' => 'sig-rsa', 'domainId' => 'dom-1', 'selector' => 'v1-rsa', '@type' => 'Dkim1RsaSha256', 'stage' => 'active'],
                ]], 'c1']],
            ])))
            ->push(Process::result(output: json_encode([
                'methodResponses' => [['x:Domain/query', ['ids' => ['dom-1']], 'c0'], ['x:Domain/get', ['list' => []], 'c1']],
            ])))
            ->push(Process::result(output: json_encode([
                'methodResponses' => [['x:Domain/get', ['list' => [['id' => 'dom-1', 'name' => 'luchtech.dev']]], 'c1']],
            ]))),
    ]);

    $this->artisan('mail:dkim', ['--fix' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Removed 1 Ed25519 signing key')
        ->expectsOutputToContain('single active key');
});

/** Harness to exercise the API-key trait methods directly with faked Process calls. */
function apiKeyHarness(): object
{
    return new class
    {
        use InteractsWithMail;
        use InteractsWithStalwartApi;

        public function ensure(string $kubectl, string $ns): ?string
        {
            return $this->stalwartEnsureApiKey($kubectl, $ns);
        }
    };
}

test('stalwartEnsureApiKey mints and stores a key, creating the automation principal', function (): void {
    $callCount = 0;
    Process::fake([
        // No key yet → bootstrap; recovery admin available for the mint's basic auth.
        '*get secret mail-secrets*api-key*' => Process::result(output: '', exitCode: 1),
        '*get secret mail-secrets*admin-password*' => Process::result(output: base64_encode('recovery-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*patch secret mail-secrets*' => Process::result(output: 'patched'),
        '*exec *' => function () use (&$callCount) {
            $callCount++;

            return match ($callCount) {
                // stalwartAccounts: query(+empty get), then get(ids) — no automation principal
                1 => Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":["c"]},"c0"],["x:Account/get",{"list":[]},"c1"]]}'),
                2 => Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"c","name":"admin"}]},"c1"]]}'),
                // stalwartDomains: query(+empty get), then get(ids)
                3 => Process::result(output: '{"methodResponses":[["x:Domain/query",{"ids":["b"]},"c0"],["x:Domain/get",{"list":[]},"c1"]]}'),
                4 => Process::result(output: '{"methodResponses":[["x:Domain/get",{"list":[{"id":"b","name":"luchtech.dev"}]},"c1"]]}'),
                // create the larakube-automation principal
                5 => Process::result(output: '{"methodResponses":[["x:Account/set",{"created":{"bot":{"id":"z"}}},"c1"]]}'),
                // mint the API key (server-generated secret)
                default => Process::result(output: '{"methodResponses":[["x:ApiKey/set",{"created":{"k1":{"id":"nk","secret":"API_MINTED"}}},"c1"]]}'),
            };
        },
    ]);

    expect(apiKeyHarness()->ensure('kubectl', 'larakube-shared'))->toBe('API_MINTED');
    Process::assertRan(fn ($p) => str_contains($p->command, 'patch secret mail-secrets'));
});

test('mail:recover is registered', function (): void {
    $this->artisan('list')->assertExitCode(0)->expectsOutputToContain('mail:recover');
});

test('mail:recover errors when stalwart is not installed', function (): void {
    Process::fake(['*get deployment stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:recover', ['--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:recover re-mints the automation API key via the recovery admin', function (): void {
    $callCount = 0;
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*admin-password*' => Process::result(output: base64_encode('recovery-pass')),
        '*get secret mail-secrets*api-key*' => Process::result(output: '', exitCode: 1),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('recovery-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*patch secret mail-secrets*' => Process::result(output: 'patched'),
        '*exec *' => function () use (&$callCount) {
            $callCount++;

            return match ($callCount) {
                // automation principal already exists
                1 => Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":["auto1"]},"c0"],["x:Account/get",{"list":[]},"c1"]]}'),
                2 => Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"auto1","name":"larakube-automation"}]},"c1"]]}'),
                // no existing keys to destroy
                3 => Process::result(output: '{"methodResponses":[["x:ApiKey/query",{"ids":[]},"c0"]]}'),
                // mint the fresh key
                default => Process::result(output: '{"methodResponses":[["x:ApiKey/set",{"created":{"k1":{"id":"nk","secret":"API_FRESH"}}},"c1"]]}'),
            };
        },
    ]);

    $this->artisan('mail:recover', ['--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Automation API key re-minted');

    Process::assertRan(fn ($p) => str_contains($p->command, 'patch secret mail-secrets'));
});
