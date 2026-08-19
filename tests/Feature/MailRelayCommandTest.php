<?php

use App\Commands\Mail\MailRelayCommand;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

test('mail:relay is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:relay');
});

test('mail:relay requires installed stalwart', function (): void {
    Process::fake(['*get deployment stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:relay', ['--provider' => 'brevo', '--username' => 'a@b.com', '--api-key' => 'k'])
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:relay rejects an unknown provider', function (): void {
    Process::fake(['*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d')]);

    $this->artisan('mail:relay', ['--provider' => 'sendgrid'])
        ->assertExitCode(1)
        ->expectsOutputToContain("Unknown relay provider 'sendgrid'");
});

test('mail:relay wires brevo as the outbound relay', function (): void {
    $callCount = 0;
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-relay*' => Process::result(output: '', exitCode: 1),
        '*create secret generic mail-relay*' => Process::result(output: 'secret/mail-relay created'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => function () use (&$callCount) {
            $callCount++;

            return match ($callCount) {
                1 => Process::result(output: '{"methodResponses":[["x:MtaRoute/query",{"ids":[]},"c0"],["x:MtaRoute/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
                2 => Process::result(output: '{"methodResponses":[["x:MtaRoute/set",{"created":{"r1":{"id":"rt1"}}},"c1"]],"sessionState":"x"}'),
                3 => Process::result(output: '{"methodResponses":[["x:MtaOutboundStrategy/get",{"list":[{"route":{"match":{"0":{"if":"is_local_domain(rcpt_domain)","then":"\'local\'"}},"else":"\'mx\'"},"id":"singleton"}],"notFound":[]},"c1"]],"sessionState":"x"}'),
                default => Process::result(output: '{"methodResponses":[["x:MtaOutboundStrategy/set",{"updated":{"singleton":null}},"c1"]],"sessionState":"x"}'),
            };
        },
    ]);

    $this->artisan('mail:relay', ['--provider' => 'brevo', '--username' => 'noreply@example.com', '--api-key' => 'xkeysib-test'])
        ->assertExitCode(0)
        ->expectsOutputToContain('Outbound mail now relays through Brevo')
        ->expectsOutputToContain('smtp-relay.brevo.com:2525')
        ->expectsOutputToContain('noreply@example.com')
        // Onboarding/pricing is for first-time interactive setup only — stay
        // quiet on a scripted run that already supplied both credentials.
        ->doesntExpectOutputToContain('Sign up free');
});

test('mail:relay wires SES with a region-scoped host on port 2587', function (): void {
    $callCount = 0;
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-relay*' => Process::result(output: '', exitCode: 1),
        '*create secret generic mail-relay*' => Process::result(output: 'secret/mail-relay created'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => function () use (&$callCount) {
            $callCount++;

            return match ($callCount) {
                1 => Process::result(output: '{"methodResponses":[["x:MtaRoute/query",{"ids":[]},"c0"],["x:MtaRoute/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
                2 => Process::result(output: '{"methodResponses":[["x:MtaRoute/set",{"created":{"r1":{"id":"rt1"}}},"c1"]],"sessionState":"x"}'),
                3 => Process::result(output: '{"methodResponses":[["x:MtaOutboundStrategy/get",{"list":[{"route":{"match":{"0":{"if":"is_local_domain(rcpt_domain)","then":"\'local\'"}},"else":"\'mx\'"},"id":"singleton"}],"notFound":[]},"c1"]],"sessionState":"x"}'),
                default => Process::result(output: '{"methodResponses":[["x:MtaOutboundStrategy/set",{"updated":{"singleton":null}},"c1"]],"sessionState":"x"}'),
            };
        },
    ]);

    $this->artisan('mail:relay', ['--provider' => 'ses', '--region' => 'eu-west-1', '--username' => 'AKIAEXAMPLE', '--api-key' => 'ses-smtp-pass'])
        ->assertExitCode(0)
        ->expectsOutputToContain('Outbound mail now relays through Amazon SES')
        ->expectsOutputToContain('email-smtp.eu-west-1.amazonaws.com:2587')
        ->expectsOutputToContain('AKIAEXAMPLE');
});

test('mail:relay shows onboarding + pricing before prompting for fresh credentials', function (): void {
    $callCount = 0;
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-relay*' => Process::result(output: '', exitCode: 1),
        '*create secret generic mail-relay*' => Process::result(output: 'secret/mail-relay created'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => function () use (&$callCount) {
            $callCount++;

            return match ($callCount) {
                1 => Process::result(output: '{"methodResponses":[["x:MtaRoute/query",{"ids":[]},"c0"],["x:MtaRoute/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
                2 => Process::result(output: '{"methodResponses":[["x:MtaRoute/set",{"created":{"r1":{"id":"rt1"}}},"c1"]],"sessionState":"x"}'),
                3 => Process::result(output: '{"methodResponses":[["x:MtaOutboundStrategy/get",{"list":[{"route":{"match":{"0":{"if":"is_local_domain(rcpt_domain)","then":"\'local\'"}},"else":"\'mx\'"},"id":"singleton"}],"notFound":[]},"c1"]],"sessionState":"x"}'),
                default => Process::result(output: '{"methodResponses":[["x:MtaOutboundStrategy/set",{"updated":{"singleton":null}},"c1"]],"sessionState":"x"}'),
            };
        },
    ]);

    Prompt::fake([
        'u', Key::ENTER, // username
        'k', Key::ENTER, // api-key
    ]);

    $command = app(MailRelayCommand::class);
    $input = new ArrayInput(['--provider' => 'brevo']);
    $input->bind($command->getDefinition());
    $input->setInteractive(true);
    $output = new BufferedOutput;
    $command->setInput($input);
    $command->setOutput(new OutputStyle($input, $output));

    $exitCode = $command->handle();

    expect($exitCode)->toBe(0);
    $printed = $output->fetch();
    expect($printed)->toContain('Sign up free')
        ->toContain('SMTP & API')
        ->toContain('Pricing:')
        ->toContain('300 emails/day');
});

test('mail:relay --remove is a no-op when no relay route exists', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => Process::result(output: '{"methodResponses":[["x:MtaRoute/query",{"ids":[]},"c0"],["x:MtaRoute/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
    ]);

    $this->artisan('mail:relay', ['--provider' => 'brevo', '--remove' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('No Brevo relay route is configured');
});

test('mail:relay --remove reverts to MX and deletes the route', function (): void {
    $callCount = 0;
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*delete secret mail-relay*' => Process::result(output: 'secret "mail-relay" deleted'),
        '*' => function () use (&$callCount) {
            $callCount++;

            return match ($callCount) {
                1 => Process::result(output: '{"methodResponses":[["x:MtaRoute/query",{"ids":["rt1"]},"c0"],["x:MtaRoute/get",{"list":[{"id":"rt1","name":"brevo","@type":"Relay"}],"notFound":[]},"c1"]],"sessionState":"x"}'),
                2 => Process::result(output: '{"methodResponses":[["x:MtaOutboundStrategy/get",{"list":[{"route":{"match":{},"else":"\'brevo\'"},"id":"singleton"}],"notFound":[]},"c1"]],"sessionState":"x"}'),
                3 => Process::result(output: '{"methodResponses":[["x:MtaOutboundStrategy/set",{"updated":{"singleton":null}},"c1"]],"sessionState":"x"}'),
                default => Process::result(output: '{"methodResponses":[["x:MtaRoute/set",{"destroyed":["rt1"]},"c1"]],"sessionState":"x"}'),
            };
        },
    ]);

    $this->artisan('mail:relay', ['--provider' => 'brevo', '--remove' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Outbound mail now delivers directly via MX again')
        ->expectsOutputToContain('Clean up the DNS records Brevo added')
        ->expectsOutputToContain('brevo1._domainkey');
});

// ---------------------------------------------------------------------------
// Guard-injection unit tests — pure Process::fake() approach
//
// stalwartJmap() writes its JMAP payload to a scratch file inside its own
// Spatie\TemporaryDirectory and pipes it via:
//   < escapeshellarg($tmp)  →  < '/tmp/{random}/stalwart-payload'
//
// Process::fake closures receive the PendingProcess as $process.
// $process->command is the public string property for the full shell command.
// The file still exists inside the closure because ->delete() runs only
// after Process::run() returns — and the fake is fully synchronous.
// We capture the path inside the single quotes (no surrounding quote chars)
// so file_exists() sees the real path.
// ---------------------------------------------------------------------------

/**
 * Extract and JSON-decode the JMAP payload written to a temp file by stalwartJmap().
 *
 * @return array<string, mixed>|null
 */
function jmapPayloadFromProcess(mixed $process): ?array
{
    $cmd = is_string($process->command)
        ? $process->command
        : implode(' ', (array) $process->command);

    // Matches the stdin redirect on ANY exec'd command, not a specific
    // temp-filename prefix. A non-JMAP exec's redirect falls through
    // safely via the ?: null below.
    if (preg_match("!< '([^']+)'!", $cmd, $m) && file_exists($m[1])) {
        return json_decode(file_get_contents($m[1]), true) ?: null;
    }

    return null;
}

test('stalwartSetOutboundRoute sets outbound strategy route', function (): void {
    $callCount = 0;
    $setPayload = null;

    Process::fake([
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => function ($process) use (&$callCount, &$setPayload) {
            $callCount++;
            $payload = jmapPayloadFromProcess($process);

            if ($payload && ($payload['methodCalls'][0][0] ?? '') === 'x:MtaOutboundStrategy/set') {
                $setPayload = $payload;
            }

            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:MtaOutboundStrategy/get",{"list":[{"route":{"match":{"0":{"if":"is_local_domain(rcpt_domain)","then":"\'local\'"}},"else":"\'mx\'"},"id":"singleton"}],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:MtaOutboundStrategy/set",{"updated":{"singleton":null}},"c1"]],"sessionState":"x"}');
        },
    ]);

    $trait = new class
    {
        use InteractsWithMail;
        use InteractsWithStalwartApi;

        public function setRoute(string $kubectl, string $ns, string $routeName): bool
        {
            return $this->stalwartSetOutboundRoute($kubectl, $ns, $routeName);
        }
    };

    $ok = $trait->setRoute('kubectl', 'larakube-shared', 'ses');
    expect($ok)->toBeTrue();
});

function captureRoutePatch(array $existingMatch, string $routeName, string $existingElse = "'mx'"): array
{
    $callCount = 0;
    $setPayload = null;

    Process::fake([
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => function ($process) use (&$callCount, &$setPayload, $existingMatch, $existingElse) {
            $callCount++;
            $payload = jmapPayloadFromProcess($process);

            if ($payload
                && ($payload['methodCalls'][0][0] ?? '') === 'x:MtaOutboundStrategy/set'
                && isset($payload['methodCalls'][0][1]['update']['singleton']['route']['match'])) {
                $setPayload = $payload;
            }

            if ($callCount === 1) {
                return Process::result(output: (string) json_encode([
                    'methodResponses' => [[
                        'x:MtaOutboundStrategy/get',
                        ['list' => [['route' => ['match' => (object) $existingMatch, 'else' => $existingElse], 'id' => 'singleton']], 'notFound' => []],
                        'c1',
                    ]],
                    'sessionState' => 'x',
                ], JSON_UNESCAPED_SLASHES));
            }

            return Process::result(output: '{"methodResponses":[["x:MtaOutboundStrategy/set",{"updated":{"singleton":null}},"c1"]],"sessionState":"x"}');
        },
    ]);

    $trait = new class
    {
        use InteractsWithMail;
        use InteractsWithStalwartApi;

        public function setRoute(string $kubectl, string $ns, string $routeName): bool
        {
            return $this->stalwartSetOutboundRoute($kubectl, $ns, $routeName);
        }
    };

    $trait->setRoute('kubectl', 'larakube-shared', $routeName);

    return (array) ($setPayload['methodCalls'][0][1]['update']['singleton']['route']['match'] ?? []);
}

test('the local-domain rule uses Stalwart\'s 1-argument is_local_domain', function (): void {
    $match = captureRoutePatch(
        ['0' => ['if' => 'is_local_domain(rcpt_domain)', 'then' => "'local'"]],
        'ses',
    );

    $localRule = collect($match)->first(fn ($r) => str_contains((string) ($r['if'] ?? ''), 'is_local_domain'));

    expect($localRule['if'])->toBe('is_local_domain(rcpt_domain)');
});
