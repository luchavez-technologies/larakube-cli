<?php

use App\Http\Integrations\Zitadel\Requests\CreateUserRequest;
use App\Http\Integrations\Zitadel\Requests\SearchOrganizationsRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

/**
 * Extract and JSON-decode the JMAP payload written to a temp file by
 * stalwartJmap() — mirrors MailDomainCommandTest's helper of the same
 * shape (kept separate — a global function declared in two loaded test
 * files fatals on redeclaration).
 *
 * @return array<string, mixed>|null
 */
function mailCreateJmapPayload(mixed $process): ?array
{
    $cmd = is_string($process->command)
        ? $process->command
        : implode(' ', (array) $process->command);

    // Matches the stdin redirect on ANY exec'd command, not a specific
    // temp-filename prefix — stalwartJmap()'s scratch file lives in its own
    // Spatie\TemporaryDirectory now. A non-JMAP exec's redirect falls
    // through safely via the ?: null below.
    if (preg_match("!< '([^']+)'!", $cmd, $m) && file_exists($m[1])) {
        return json_decode(file_get_contents($m[1]), true) ?: null;
    }

    return null;
}

/** Common non-account-creation fakes every mail:create test needs. */
function mailCreateBaseFakes(): array
{
    return [
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*part-of=webmail*' => Process::result(output: '', exitCode: 1),
        '*get deployment sso-zitadel*' => Process::result(output: '', exitCode: 1),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
    ];
}

test('mail:create is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:create');
});

test('mail:create --domain= selects the given domain over the default first-configured one', function (): void {
    $callCount = 0;
    $accountCreatePayload = null;

    Process::fake(mailCreateBaseFakes() + [
        '*' => function ($process) use (&$callCount, &$accountCreatePayload) {
            $callCount++;

            return match ($callCount) {
                1 => Process::result(output: '{"methodResponses":[["x:Domain/query",{"ids":["d-luchtech","d-partner"]},"c0"],["x:Domain/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
                2 => Process::result(output: '{"methodResponses":[["x:Domain/get",{"list":[{"id":"d-luchtech","name":"luchtech.dev"},{"id":"d-partner","name":"partner.example"}],"notFound":[]},"c1"]],"sessionState":"x"}'),
                default => tap(Process::result(output: '{"methodResponses":[["x:Account/set",{"created":{"new1":{"id":"acc1"}}},"c1"]],"sessionState":"x"}'), function () use ($process, &$accountCreatePayload): void {
                    $accountCreatePayload = mailCreateJmapPayload($process);
                }),
            };
        },
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

    $create = $accountCreatePayload['methodCalls'][0][1]['create']['new1'];
    expect($create['domainId'])->toBe('d-partner');
});

test('mail:create rejects an unknown --domain=', function (): void {
    $callCount = 0;

    Process::fake(mailCreateBaseFakes() + [
        '*' => function () use (&$callCount) {
            $callCount++;

            return match ($callCount) {
                1 => Process::result(output: '{"methodResponses":[["x:Domain/query",{"ids":["d-luchtech"]},"c0"],["x:Domain/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
                default => Process::result(output: '{"methodResponses":[["x:Domain/get",{"list":[{"id":"d-luchtech","name":"luchtech.dev"}],"notFound":[]},"c1"]],"sessionState":"x"}'),
            };
        },
    ]);

    $this->artisan('mail:create', ['--domain' => 'partner.example', '--no-sso' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain("Unknown domain 'partner.example'");
});

test('mail:create falls back to the first configured domain when non-interactive with no domain hint', function (): void {
    $callCount = 0;
    $accountCreatePayload = null;

    Process::fake(mailCreateBaseFakes() + [
        '*' => function ($process) use (&$callCount, &$accountCreatePayload) {
            $callCount++;

            return match ($callCount) {
                1 => Process::result(output: '{"methodResponses":[["x:Domain/query",{"ids":["d-luchtech","d-partner"]},"c0"],["x:Domain/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
                2 => Process::result(output: '{"methodResponses":[["x:Domain/get",{"list":[{"id":"d-luchtech","name":"luchtech.dev"},{"id":"d-partner","name":"partner.example"}],"notFound":[]},"c1"]],"sessionState":"x"}'),
                default => tap(Process::result(output: '{"methodResponses":[["x:Account/set",{"created":{"new1":{"id":"acc1"}}},"c1"]],"sessionState":"x"}'), function () use ($process, &$accountCreatePayload): void {
                    $accountCreatePayload = mailCreateJmapPayload($process);
                }),
            };
        },
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

    $create = $accountCreatePayload['methodCalls'][0][1]['create']['new1'];
    expect($create['domainId'])->toBe('d-luchtech');
});

test('mail:create requires installed stalwart', function (): void {
    Process::fake(['*get deployment stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:create')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
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
        '*part-of=webmail*' => Process::result(output: ''),
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

test('mail:create shows the webmail URL when Bulwark is installed', function (): void {
    $callCount = 0;
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*get deployment sso-zitadel*' => Process::result(output: ''),
        '*part-of=webmail*' => Process::result(output: 'webmail-bulwark   1/1   1   1   10d'),
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

    Saloon::fake([
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

    Saloon::fake([
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
