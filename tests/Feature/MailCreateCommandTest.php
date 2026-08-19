<?php

use Illuminate\Support\Facades\Process;

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

    if (preg_match("!< '([^']+larakube_stalwart[^']+)'!", $cmd, $m) && file_exists($m[1])) {
        return json_decode(file_get_contents($m[1]), true) ?: null;
    }

    return null;
}

/** Common non-account-creation fakes every mail:create test needs. */
function mailCreateBaseFakes(): array
{
    return [
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get deployment webmail-bulwark*' => Process::result(output: '', exitCode: 1),
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
